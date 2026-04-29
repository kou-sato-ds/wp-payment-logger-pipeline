-- ============================================================================
-- 決済エンジニアリング基盤 — DB検証用 SQL クエリ集 (最終版)
-- 対象: MySQL 8.x
-- 用途: DE ポートフォリオ用テスト後の DB 直接検証
-- ============================================================================


-- ============================================================================
-- § A. PIIマスキング漏れ検証
--      「[MASKED] であるべき値が生のまま保存されていないか」を確認する
--      → 1件でも返れば PiiMasker のバグ確定
-- ============================================================================

-- A-1. メールアドレスパターンが raw_response に残っていないか
SELECT
    pe.id            AS event_id,
    pe.payment_id,
    pe.event_type,
    pe.occurred_at,
    SUBSTRING(pe.raw_response, 1, 300) AS raw_head
FROM payment_events pe
WHERE
    pe.raw_response IS NOT NULL
    AND REGEXP_LIKE(
            pe.raw_response,
            '[a-zA-Z0-9._%+\\-]+@[a-zA-Z0-9.\\-]+\\.[a-zA-Z]{2,}'
        )
ORDER BY pe.occurred_at DESC;
-- 期待値: 0件。1件でも返れば PiiMasker::mask() のバグ。


-- A-2. 日本の郵便番号パターン（NNN-NNNN）が残っていないか
SELECT
    pe.id, pe.payment_id, pe.occurred_at,
    SUBSTRING(pe.raw_response, 1, 300) AS raw_head
FROM payment_events pe
WHERE
    pe.raw_response IS NOT NULL
    AND REGEXP_LIKE(pe.raw_response, '[0-9]{3}-[0-9]{4}')
ORDER BY pe.occurred_at DESC;
-- 期待値: 0件。


-- A-3. JSON_EXTRACT で billing_details の各フィールドを直接確認
--      成功シナリオ（mock_txn_ プレフィクス付き）の結果を対象とする
SELECT
    pe.id                                                                   AS event_id,
    pe.payment_id,
    p.gateway_transaction_id,
    JSON_UNQUOTE(JSON_EXTRACT(pe.raw_response, '$.billing_details.email'))
                                                                            AS billing_email,
    JSON_UNQUOTE(JSON_EXTRACT(pe.raw_response, '$.billing_details.name'))
                                                                            AS billing_name,
    JSON_UNQUOTE(
        JSON_EXTRACT(pe.raw_response, '$.billing_details.address.postal_code')
    )                                                                       AS postal_code,
    JSON_UNQUOTE(
        JSON_EXTRACT(pe.raw_response, '$.billing_details.address.city')
    )                                                                       AS city
FROM payment_events pe
JOIN payments p ON p.id = pe.payment_id
WHERE
    pe.raw_response IS NOT NULL
    AND p.gateway_transaction_id LIKE 'mock_txn_%'
    AND JSON_EXTRACT(pe.raw_response, '$.billing_details') IS NOT NULL
ORDER BY pe.occurred_at DESC;
-- 期待値: billing_email / billing_name / postal_code / city が全て "[MASKED]"


-- A-4. [MASKED] 適用イベントの日別サマリー（マスキング率の確認）
SELECT
    DATE(pe.occurred_at)                                         AS date,
    COUNT(*)                                                     AS total_events,
    SUM(pe.raw_response LIKE '%[MASKED]%')                       AS events_with_masking,
    SUM(pe.raw_response IS NOT NULL
        AND pe.raw_response NOT LIKE '%[MASKED]%')               AS events_without_masking,
    ROUND(
        100.0 * SUM(pe.raw_response LIKE '%[MASKED]%')
        / NULLIF(COUNT(*), 0), 1
    )                                                            AS masking_rate_pct
FROM payment_events pe
WHERE pe.raw_response IS NOT NULL
GROUP BY DATE(pe.occurred_at)
ORDER BY date DESC;


-- A-5. モックレコードを除外した本番候補データでの漏れ確認
--      (gateway_transaction_id が mock_txn_ でない = 本番候補)
SELECT
    pe.id, pe.payment_id, pe.occurred_at,
    SUBSTRING(pe.raw_response, 1, 200) AS raw_head
FROM payment_events pe
JOIN payments p ON p.id = pe.payment_id
WHERE
    (p.gateway_transaction_id IS NULL
     OR p.gateway_transaction_id NOT LIKE 'mock_txn_%')
    AND pe.raw_response IS NOT NULL
    AND REGEXP_LIKE(
            pe.raw_response,
            '[a-zA-Z0-9._%+\\-]+@[a-zA-Z0-9.\\-]+\\.[a-zA-Z]{2,}'
        )
ORDER BY pe.occurred_at DESC;
-- 期待値: 0件。本番データに PII が混入していないことを確認。


-- ============================================================================
-- § B. 制作年月（_work_date）vs 決済完了日時 の乖離抽出
--      「売上分析で期間帰属がズレているレコード」を洗い出す
-- ============================================================================

-- B-1. DATA_CONSISTENCY_WARNING イベント一覧（閾値31日超のもの）
SELECT
    pe.id                                                              AS event_id,
    pe.payment_id,
    p.order_id,
    JSON_UNQUOTE(JSON_EXTRACT(pe.raw_response, '$.post_id'))           AS post_id,
    JSON_UNQUOTE(JSON_EXTRACT(pe.raw_response, '$.work_date'))         AS work_date,
    JSON_UNQUOTE(JSON_EXTRACT(pe.raw_response, '$.completed_at'))      AS completed_at,
    CAST(JSON_EXTRACT(pe.raw_response, '$.diff_days') AS UNSIGNED)     AS diff_days,
    CAST(JSON_EXTRACT(pe.raw_response, '$.threshold_days') AS UNSIGNED) AS threshold_days,
    pe.occurred_at
FROM payment_events pe
JOIN payments p ON p.id = pe.payment_id
WHERE
    pe.event_type    = 8
    AND pe.gateway_code = 'DATA_CONSISTENCY_WARNING'
ORDER BY diff_days DESC;


-- B-2. 乖離日数の分布（ヒストグラム用）
SELECT
    CASE
        WHEN diff_days <  31  THEN '① 0〜30日（正常）'
        WHEN diff_days <  60  THEN '② 31〜59日'
        WHEN diff_days <  90  THEN '③ 60〜89日'
        WHEN diff_days < 180  THEN '④ 90〜179日'
        ELSE                       '⑤ 180日以上'
    END                    AS range_label,
    COUNT(*)               AS cnt
FROM (
    SELECT
        CAST(JSON_EXTRACT(pe.raw_response, '$.diff_days') AS UNSIGNED) AS diff_days
    FROM payment_events pe
    WHERE pe.event_type = 8
) sub
GROUP BY range_label
ORDER BY MIN(diff_days);


-- B-3. wp_postmeta と直接 JOIN して乖離レコードを抽出
--      payment_events に WARNING が記録されていない古いデータも網羅する
SELECT
    p.id                                                               AS payment_id,
    p.order_id,
    pm2.meta_value                                                     AS work_date_raw,
    DATE_FORMAT(p.completed_at, '%Y-%m')                               AS payment_month,
    ABS(DATEDIFF(
        STR_TO_DATE(CONCAT(pm2.meta_value, '-01'), '%Y-%m-%d'),
        p.completed_at
    ))                                                                 AS diff_days,
    p.amount,
    p.completed_at
FROM payments p
JOIN wp_postmeta pm
    ON  pm.meta_key            = '_order_id'
    AND CAST(pm.meta_value AS UNSIGNED) = p.order_id
JOIN wp_postmeta pm2
    ON  pm2.post_id            = pm.post_id
    AND pm2.meta_key           = '_work_date'
    AND pm2.meta_value        != ''
WHERE
    p.status = 2
    AND ABS(DATEDIFF(
            STR_TO_DATE(CONCAT(pm2.meta_value, '-01'), '%Y-%m-%d'),
            p.completed_at
        )) >= 31
ORDER BY diff_days DESC;


-- ============================================================================
-- § C. 決済エラー分析
-- ============================================================================

-- C-1. エラー種別 × 月別集計（CVV不一致の多い月がわかる）
SELECT
    DATE_FORMAT(pe.occurred_at, '%Y-%m')   AS ym,
    pe.failure_reason,
    CASE pe.failure_reason
        WHEN 0  THEN '—'
        WHEN 1  THEN 'CVV不一致'
        WHEN 2  THEN 'カード番号無効'
        WHEN 3  THEN '有効期限切れ'
        WHEN 4  THEN '残高不足'
        WHEN 5  THEN '住所不一致(AVS)'
        WHEN 6  THEN '3Dセキュア失敗'
        WHEN 7  THEN '不正検知ブロック'
        WHEN 8  THEN 'カード利用停止'
        WHEN 9  THEN 'GWエラー'
        WHEN 10 THEN 'タイムアウト'
        WHEN 99 THEN 'その他'
    END                                    AS failure_label,
    COUNT(*)                               AS fail_count
FROM payment_events pe
WHERE pe.event_type = 2
GROUP BY ym, pe.failure_reason
ORDER BY ym DESC, fail_count DESC;


-- C-2. 二重決済チェック（同一 order_id に COMPLETED が複数ないか）
SELECT
    order_id,
    COUNT(*) AS completed_count,
    GROUP_CONCAT(id ORDER BY requested_at) AS payment_ids
FROM payments
WHERE status = 2
GROUP BY order_id
HAVING completed_count > 1
ORDER BY completed_count DESC;
-- 期待値: 0件。1件でも返れば二重決済が発生している。


-- C-3. モックデータの混入確認（本番分析から除外すべきレコード一覧）
SELECT
    p.id               AS payment_id,
    p.order_id,
    p.amount,
    p.status,
    p.gateway_transaction_id,
    p.requested_at
FROM payments p
WHERE p.gateway_transaction_id LIKE 'mock_txn_%'
ORDER BY p.requested_at DESC;


-- C-4. タイムアウト後に AUTHORIZED が来たか（Webhook 復旧確認）
SELECT
    t.payment_id,
    t.occurred_at                                          AS timeout_at,
    a.occurred_at                                          AS authorized_at,
    TIMESTAMPDIFF(SECOND, t.occurred_at, a.occurred_at)   AS recovery_seconds
FROM payment_events t
LEFT JOIN payment_events a
    ON  a.payment_id  = t.payment_id
    AND a.event_type  = 1
    AND a.occurred_at > t.occurred_at
WHERE t.event_type = 3
ORDER BY t.occurred_at DESC;


-- C-5. 月別決済成功率
SELECT
    DATE_FORMAT(pe.occurred_at, '%Y-%m')        AS ym,
    COUNT(*)                                    AS total_attempts,
    SUM(pe.event_type = 1)                      AS authorized,
    SUM(pe.event_type = 2)                      AS declined,
    ROUND(
        100.0 * SUM(pe.event_type = 1)
        / NULLIF(COUNT(*), 0), 1
    )                                           AS auth_rate_pct
FROM payment_events pe
WHERE pe.event_type IN (1, 2)
GROUP BY ym
ORDER BY ym DESC;
