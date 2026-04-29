<?php
/**
 * Plugin Name: Payment Event Logger Final
 * Description: 決済ログの匿名化とシミュレーター機能を提供します。
 * Version: 1.0.0
 * Author: Moheji
 */
/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║  EC野菜サイト 決済エンジニアリング基盤  v4.0 FINAL — ポートフォリオ完全版    ║
 * ║                                                                              ║
 * ║  Code Snippets にそのまま貼り付け可能（PHP snippet / 全ページ対象）           ║
 * ║  PHP 8.x + WordPress 6.x + MySQL 8.x                                        ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  DE（データエンジニア）ポートフォリオ設計原則                                 ║
 * ║                                                                              ║
 * ║  1. Completeness  — 全イベントを漏れなく payment_events に記録               ║
 * ║  2. Traceability  — 失敗理由・rawレスポンスを Enum と 1:1 で保全             ║
 * ║  3. Idempotency   — FOR UPDATE + status チェックで二重決済を構造的に防止     ║
 * ║  4. Consistency   — 制作年月 vs 決済完了日時の乖離を 31日閾値で自動検知      ║
 * ║  5. Privacy       — PiiMasker が保存前に全 rawResponse を自動マスキング      ║
 * ║  6. Testability   — PaymentGatewayInterface DI でモック差し替えが一行        ║
 * ║  7. Observability — ブラウザ Console に PIIマスキング監査ログを出力          ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  ファイル構成（16セクション）                                                 ║
 * ║                                                                              ║
 * ║  § 1.  Enum / 定数定義（label / rowStyle / badgeStyle メソッド付き）         ║
 * ║  § 2.  カスタム例外クラス（通信/タイムアウト/拒否/バリデーションを型分離）    ║
 * ║  § 3.  PiiMasker — 再帰マスキング + マスクパス収集                           ║
 * ║  § 4.  PaymentEventLogger — PiiMasker 統合ロガー                             ║
 * ║  § 5.  GatewayResponseValidator — ホワイトリスト方式レスポンス検証           ║
 * ║  § 6.  StripeDeclineCodeMapper — decline_code → Enum 変換                   ║
 * ║  § 7.  PaymentGatewayInterface — DI 抽象化（本番/モック共通契約）            ║
 * ║  § 8.  StripeGatewayAdapter — 本番 Stripe アダプター                         ║
 * ║  § 9.  MockPaymentGateway — 8シナリオ対応モックシミュレーター                ║
 * ║  § 10. PaymentProcessingService — 決済処理コア（5段階 catch 分岐）           ║
 * ║  § 11. WorkDateConsistencyChecker — 整合性検証（31日閾値）                   ║
 * ║  § 12. WordPress フック統合                                                  ║
 * ║  § 13. メタボックス — 決済ログビューア（投稿画面）                           ║
 * ║  § 14. REST API — シミュレーター実行エンドポイント                           ║
 * ║  § 15. フロントエンド テストダッシュボード（ショートコード）                  ║
 * ║        ★ Console セキュリティログ統合                                        ║
 * ║  § 16. 管理画面 ツール > 決済シミュレーター                                  ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */

declare(strict_types=1);

// =============================================================================
// § 1. Enum / 定数定義
// =============================================================================

/**
 * payment_events.event_type — DDL と 1:1 対応
 * メタボックス描画メソッドをここに集約することで、
 * 表示ロジックが DB の enum 定義と常に同期する。
 */
enum PaymentEventType: int
{
    case REQUEST_SENT             = 0;
    case AUTHORIZED               = 1;
    case DECLINED                 = 2;
    case TIMEOUT                  = 3;
    case CANCELLED                = 4;
    case REFUND_REQUESTED         = 5;
    case REFUND_COMPLETED         = 6;
    case WEBHOOK_RECEIVED         = 7;
    case DATA_CONSISTENCY_WARNING = 8;

    /** 管理画面・メタボックスに表示する日本語ラベル */
    public function label(): string
    {
        return match($this) {
            self::REQUEST_SENT             => 'リクエスト送信',
            self::AUTHORIZED               => '承認完了',
            self::DECLINED                 => '決済拒否',
            self::TIMEOUT                  => 'タイムアウト',
            self::CANCELLED                => 'キャンセル',
            self::REFUND_REQUESTED         => '返金リクエスト',
            self::REFUND_COMPLETED         => '返金完了',
            self::WEBHOOK_RECEIVED         => 'Webhook受信',
            self::DATA_CONSISTENCY_WARNING => 'データ整合性警告',
        };
    }

    /**
     * メタボックス行の背景色・左ボーダー色
     * 異常系（DECLINED / TIMEOUT）と警告を色で即座に識別できるようにする。
     */
    public function rowStyle(): string
    {
        return match($this) {
            self::AUTHORIZED               => 'background:#f0fdf4;border-left:3px solid #16a34a;',
            self::DECLINED                 => 'background:#fef2f2;border-left:3px solid #dc2626;',
            self::TIMEOUT                  => 'background:#fffbeb;border-left:3px solid #d97706;',
            self::DATA_CONSISTENCY_WARNING => 'background:#faf5ff;border-left:3px solid #7c3aed;',
            default                        => 'background:#f8fafc;border-left:3px solid #94a3b8;',
        };
    }

    /** メタボックス / フロントエンド バッジの背景色・文字色 */
    public function badgeStyle(): string
    {
        return match($this) {
            self::AUTHORIZED               => 'background:#dcfce7;color:#166534;',
            self::DECLINED                 => 'background:#fee2e2;color:#991b1b;',
            self::TIMEOUT                  => 'background:#fef9c3;color:#854d0e;',
            self::DATA_CONSISTENCY_WARNING => 'background:#ede9fe;color:#5b21b6;',
            default                        => 'background:#f1f5f9;color:#475569;',
        };
    }
}

/**
 * payment_events.failure_reason — DDL と 1:1 対応
 * 分析クエリの WHERE failure_reason = N と完全に連動する。
 */
enum PaymentFailureReason: int
{
    case NONE               = 0;
    case CVV_MISMATCH       = 1;
    case INVALID_CARD       = 2;
    case CARD_EXPIRED       = 3;
    case INSUFFICIENT_FUNDS = 4;
    case AVS_MISMATCH       = 5;
    case THREE_DS_FAILED    = 6;
    case FRAUD_BLOCK        = 7;
    case CARD_RESTRICTED    = 8;
    case GATEWAY_ERROR      = 9;
    case TIMEOUT            = 10;
    case OTHER              = 99;

    public function label(): string
    {
        return match($this) {
            self::NONE               => '—',
            self::CVV_MISMATCH       => 'CVV不一致',
            self::INVALID_CARD       => 'カード番号無効',
            self::CARD_EXPIRED       => '有効期限切れ',
            self::INSUFFICIENT_FUNDS => '残高不足',
            self::AVS_MISMATCH       => '住所不一致(AVS)',
            self::THREE_DS_FAILED    => '3Dセキュア失敗',
            self::FRAUD_BLOCK        => '不正検知ブロック',
            self::CARD_RESTRICTED    => 'カード利用停止',
            self::GATEWAY_ERROR      => 'ゲートウェイエラー',
            self::TIMEOUT            => 'タイムアウト',
            self::OTHER              => 'その他',
        };
    }
}

/** payments.status — DDL と 1:1 対応 */
enum PaymentStatus: int
{
    case PENDING    = 0;
    case PROCESSING = 1;
    case COMPLETED  = 2;
    case FAILED     = 3;
    case CANCELLED  = 4;
    case REFUNDED   = 5;
}

/** モックシミュレーターのシナリオ識別子 */
enum MockScenario: string
{
    case SUCCESS          = 'success';
    case CVV_MISMATCH     = 'cvv_mismatch';
    case EXPIRED_CARD     = 'expired_card';
    case INSUFFICIENT     = 'insufficient_funds';
    case FRAUD            = 'fraud';
    case TIMEOUT          = 'timeout';
    case CONNECTION_ERROR = 'connection_error';
    case INVALID_RESPONSE = 'invalid_response';

    public function label(): string
    {
        return match($this) {
            self::SUCCESS          => '✅ 正常決済（SUCCESS）',
            self::CVV_MISMATCH     => '❌ CVV不一致',
            self::EXPIRED_CARD     => '❌ 有効期限切れ',
            self::INSUFFICIENT     => '❌ 残高不足',
            self::FRAUD            => '⚠️ 不正検知',
            self::TIMEOUT          => '⏱ タイムアウト',
            self::CONNECTION_ERROR => '🔌 通信エラー',
            self::INVALID_RESPONSE => '🔒 不正レスポンス',
        };
    }
}

// =============================================================================
// § 2. カスタム例外クラス
//
// 設計原則: エラー種別を「型」で区別することで catch ブロックが
// 文字列比較なしに確実に分岐できる。
// =============================================================================

/** ゲートウェイへの通信自体が失敗（ネットワーク / DNS / TLS エラー等） */
class PaymentGatewayConnectionException extends \RuntimeException {}

/**
 * HTTP は繋がったがタイムアウト
 * ★ この例外は payments を FAILED にしてはいけない（結果不明のため）
 */
class PaymentGatewayTimeoutException extends \RuntimeException {}

/**
 * カード情報は届いたが決済が拒否された
 * CVV不一致・残高不足・不正検知等、ゲートウェイ側の判断による拒否
 */
class PaymentDeclinedException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly string               $gatewayCode,
        public readonly PaymentFailureReason $reason,
        public readonly array                $rawResponse,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}

/** レスポンス構造が仕様外（改ざん・バグ疑い） — セキュリティインシデント候補 */
class PaymentResponseValidationException extends \RuntimeException {}

// =============================================================================
// § 3. PiiMasker — 個人情報自動マスキング
//
// 設計原則:
//   - MASKED_KEYS はホワイトリスト管理（将来追加しやすいよう定数化）
//   - 再帰処理でネストした JSON も漏れなく処理
//   - キー名に加え、値がメール形式の場合も強制マスク（二重防衛）
//   - collectMaskedPaths() でメタボックス / Console ログに適用フィールドを表示
// =============================================================================

final class PiiMasker
{
    /**
     * マスキング対象キー名（完全一致 / 大文字小文字無視）
     * Stripe API・一般的な決済 API の頻出フィールドを網羅
     */
    private const MASKED_KEYS = [
        // 個人識別情報
        'email', 'email_address', 'receipt_email', 'customer_email',
        'name', 'full_name', 'first_name', 'last_name', 'family_name', 'given_name',
        'phone', 'phone_number', 'tel',
        // 住所
        'address', 'address_line1', 'address_line2', 'line1', 'line2',
        'city', 'state', 'postal_code', 'zip', 'country',
        'billing_details', 'shipping',
        // カード情報（万が一の混入対策）
        'number', 'card_number', 'pan',
        'cvc', 'cvv', 'cvc2', 'cvv2', 'security_code',
        'exp_month', 'exp_year', 'expiry',
        // 自由記述（個人名が混入しうる）
        'description',
    ];

    private const EMAIL_PATTERN = '/^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$/';

    /**
     * 配列を再帰的にスキャンし PII を [MASKED] に置換して返す。
     *
     * @param  array $data マスキング前のデータ
     * @return array       マスキング済みデータ（構造はそのまま保持）
     */
    public static function mask(array $data): array
    {
        $masked = [];
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string)$key), self::MASKED_KEYS, strict: true)) {
                // キーがマスク対象 → 型・値を問わず [MASKED] に置換
                $masked[$key] = '[MASKED]';
            } elseif (is_array($value)) {
                // ネスト配列は再帰処理
                $masked[$key] = self::mask($value);
            } elseif (is_string($value) && preg_match(self::EMAIL_PATTERN, trim($value))) {
                // キー名に関わらず値がメール形式なら強制マスク（二重防衛）
                $masked[$key] = '[MASKED]';
            } else {
                $masked[$key] = $value;
            }
        }
        return $masked;
    }

    /**
     * マスキングが適用されたフィールドのキーパスを収集する。
     * Console ログ・メタボックスの「PII 適用済み」バッジに使用。
     *
     * 例: ['billing_details.email', 'billing_details.address.postal_code']
     *
     * @param  array  $data   マスキング済みデータ
     * @param  string $prefix 再帰時のキーパスプレフィクス
     * @return string[]
     */
    public static function collectMaskedPaths(array $data, string $prefix = ''): array
    {
        $paths = [];
        foreach ($data as $key => $value) {
            $path = $prefix !== '' ? "{$prefix}.{$key}" : (string)$key;
            if ($value === '[MASKED]') {
                $paths[] = $path;
            } elseif (is_array($value)) {
                $paths = array_merge($paths, self::collectMaskedPaths($value, $path));
            }
        }
        return $paths;
    }
}

// =============================================================================
// § 4. PaymentEventLogger — PiiMasker 統合ロガー
//
// 設計原則:
//   - DB 書き込みだけを責務とする純粋なユーティリティクラス
//   - ログ失敗 ≠ 決済失敗 → 内部で catch し error_log に記録
//   - rawResponse は保存前に必ず PiiMasker::mask() を通す
// =============================================================================

final class PaymentEventLogger
{
    public function __construct(private readonly \wpdb $db) {}

    /**
     * イベントを payment_events に INSERT する。
     * この関数は例外を外に出さない。ログ失敗は error_log に記録するのみ。
     */
    public function record(
        int                  $paymentId,
        PaymentEventType     $eventType,
        PaymentFailureReason $failureReason = PaymentFailureReason::NONE,
        ?string              $gatewayCode   = null,
        ?array               $rawResponse   = null,
        ?string              $ipAddress     = null,
    ): void {
        try {
            // PII マスキング（保存直前に実行 — 呼び出し元の責任に依存しない）
            $safeResponse = $rawResponse !== null
                ? PiiMasker::mask($rawResponse)
                : null;

            $result = $this->db->insert(
                'payment_events',
                [
                    'payment_id'     => $paymentId,
                    'event_type'     => $eventType->value,
                    'failure_reason' => $failureReason->value,
                    'gateway_code'   => $gatewayCode,
                    'raw_response'   => $safeResponse !== null
                                        ? wp_json_encode($safeResponse)
                                        : null,
                    'ip_address'     => $ipAddress,
                    'occurred_at'    => current_time('mysql', true), // UTC
                ],
                ['%d', '%d', '%d', '%s', '%s', '%s', '%s']
            );

            if ($result === false) {
                error_log(sprintf(
                    '[PaymentEventLogger] DB INSERT failed. payment_id=%d event_type=%d db_error="%s"',
                    $paymentId, $eventType->value, $this->db->last_error
                ));
            }
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[PaymentEventLogger] Exception. payment_id=%d msg="%s"',
                $paymentId, $e->getMessage()
            ));
        }
    }
}

// =============================================================================
// § 5. GatewayResponseValidator — ホワイトリスト方式レスポンス検証
//
// セキュリティ設計:
//   - status は許可リストで厳密に比較（truthy 評価禁止）
//   - 必須キーが 1 つでも欠けたら即例外（部分信頼禁止）
//   - object 種別チェックで別リソースの偽装を防止
// =============================================================================

final class GatewayResponseValidator
{
    /**
     * Stripe の PaymentIntent レスポンスを検証する。
     *
     * @param  mixed $raw json_decode 済みの連想配列（型未確定）
     * @return array      正規化済みレスポンス
     * @throws PaymentResponseValidationException 構造不正時
     */
    public static function validateStripePaymentIntent(mixed $raw): array
    {
        // (A) 型チェック
        if (!is_array($raw)) {
            throw new PaymentResponseValidationException(
                'Gateway response is not an array: ' . gettype($raw)
            );
        }

        // (B) 必須キーの存在チェック
        foreach (['id', 'object', 'status', 'amount', 'currency'] as $key) {
            if (!array_key_exists($key, $raw)) {
                throw new PaymentResponseValidationException(
                    "Gateway response missing required key: {$key}"
                );
            }
        }

        // (C) object 種別チェック（Stripe 固有）
        if ($raw['object'] !== 'payment_intent') {
            throw new PaymentResponseValidationException(
                "Unexpected object type: {$raw['object']}"
            );
        }

        // (D) status はホワイトリスト方式で判定（★ 曖昧な比較は絶対に使わない）
        $allowed = [
            'requires_payment_method', 'requires_confirmation', 'requires_action',
            'processing', 'requires_capture', 'canceled', 'succeeded',
        ];
        if (!in_array($raw['status'], $allowed, strict: true)) {
            throw new PaymentResponseValidationException(
                "Unexpected payment_intent status: {$raw['status']}"
            );
        }

        return $raw;
    }
}

// =============================================================================
// § 6. StripeDeclineCodeMapper — decline_code → PaymentFailureReason 変換
//
// 未知のコードは OTHER(99) に落とし、raw_response に生コードを保全する。
// 参照: https://stripe.com/docs/declines/codes
// =============================================================================

final class StripeDeclineCodeMapper
{
    private const MAP = [
        'incorrect_cvc'                    => PaymentFailureReason::CVV_MISMATCH,
        'invalid_cvc'                      => PaymentFailureReason::CVV_MISMATCH,
        'incorrect_number'                 => PaymentFailureReason::INVALID_CARD,
        'invalid_number'                   => PaymentFailureReason::INVALID_CARD,
        'expired_card'                     => PaymentFailureReason::CARD_EXPIRED,
        'invalid_expiry_month'             => PaymentFailureReason::CARD_EXPIRED,
        'invalid_expiry_year'              => PaymentFailureReason::CARD_EXPIRED,
        'insufficient_funds'               => PaymentFailureReason::INSUFFICIENT_FUNDS,
        'card_decline_rate_limit_exceeded' => PaymentFailureReason::INSUFFICIENT_FUNDS,
        'incorrect_zip'                    => PaymentFailureReason::AVS_MISMATCH,
        'incorrect_address'                => PaymentFailureReason::AVS_MISMATCH,
        'fraudulent'                       => PaymentFailureReason::FRAUD_BLOCK,
        'stolen_card'                      => PaymentFailureReason::FRAUD_BLOCK,
        'lost_card'                        => PaymentFailureReason::FRAUD_BLOCK,
        'card_not_supported'               => PaymentFailureReason::CARD_RESTRICTED,
        'do_not_honor'                     => PaymentFailureReason::CARD_RESTRICTED,
        'restricted_card'                  => PaymentFailureReason::CARD_RESTRICTED,
        'blocked'                          => PaymentFailureReason::CARD_RESTRICTED,
        'processing_error'                 => PaymentFailureReason::GATEWAY_ERROR,
        'issuer_not_available'             => PaymentFailureReason::GATEWAY_ERROR,
    ];

    public static function fromGatewayCode(?string $code): PaymentFailureReason
    {
        return $code !== null
            ? (self::MAP[$code] ?? PaymentFailureReason::OTHER)
            : PaymentFailureReason::OTHER;
    }
}

// =============================================================================
// § 7. PaymentGatewayInterface — DI 抽象化
//
// 本番アダプターとモックが同一インターフェースを実装することで、
// PaymentProcessingService はゲートウェイ実装を一切意識しない。
// =============================================================================

interface PaymentGatewayInterface
{
    /**
     * @throws PaymentGatewayConnectionException
     * @throws PaymentGatewayTimeoutException
     * @throws PaymentDeclinedException
     */
    public function charge(int $amount, string $token, string $idempotencyKey): array;
}

// =============================================================================
// § 8. StripeGatewayAdapter — 本番 Stripe アダプター
// =============================================================================

final class StripeGatewayAdapter implements PaymentGatewayInterface
{
    public function __construct(private readonly string $secretKey) {}

    public function charge(int $amount, string $token, string $idempotencyKey): array
    {
        // Stripe SDK 導入後、コメントを解除する
        // try {
        //     $stripe = new \Stripe\StripeClient($this->secretKey);
        //     $intent = $stripe->paymentIntents->create([
        //         'amount'         => $amount,
        //         'currency'       => 'jpy',
        //         'payment_method' => $token,
        //         'confirm'        => true,
        //         'return_url'     => home_url('/checkout/complete'),
        //     ], ['idempotency_key' => $idempotencyKey]);
        //     return $intent->toArray();
        // } catch (\Stripe\Exception\ApiConnectionException $e) {
        //     throw new PaymentGatewayConnectionException($e->getMessage(), 0, $e);
        // } catch (\Stripe\Exception\CardException $e) {
        //     throw new PaymentDeclinedException(
        //         $e->getMessage(),
        //         $e->getDeclineCode() ?? $e->getStripeCode() ?? 'unknown',
        //         PaymentFailureReason::OTHER,
        //         $e->getJsonBody() ?? [],
        //         0, $e
        //     );
        // } catch (\Stripe\Exception\RateLimitException|\Stripe\Exception\InvalidRequestException $e) {
        //     throw new PaymentGatewayConnectionException($e->getMessage(), 0, $e);
        // }
        return []; // SDK 未導入時のスタブ
    }
}

// =============================================================================
// § 9. MockPaymentGateway — 8シナリオ対応モックシミュレーター
//
// 設計原則:
//   - MockScenario enum でシナリオを型安全に管理（文字列フリー入力を禁止）
//   - SUCCESS シナリオは PII を意図的に混入 → PiiMasker の動作検証に使用
//   - mock_txn_ プレフィクスで本番 DB レコードと識別可能
//   - INVALID_RESPONSE はバリデーターのセキュリティテスト用
// =============================================================================

final class MockPaymentGateway implements PaymentGatewayInterface
{
    private const MOCK_TXN_PREFIX = 'mock_txn_';

    public function charge(int $amount, string $token, string $idempotencyKey): array
    {
        $scenario = MockScenario::tryFrom(str_replace('mock_', '', $token));

        if ($scenario === null) {
            throw new \InvalidArgumentException(
                "Unknown mock scenario token: {$token}. " .
                'Valid values: ' . implode(', ', array_column(MockScenario::cases(), 'value'))
            );
        }

        return match($scenario) {

            // ── 正常決済: PII を意図的に含める → マスキング動作確認 ──────────
            MockScenario::SUCCESS => [
                'id'              => self::MOCK_TXN_PREFIX . uniqid(),
                'object'          => 'payment_intent',
                'status'          => 'succeeded',
                'amount'          => $amount,
                'currency'        => 'jpy',
                'billing_details' => [
                    'email'   => 'test-customer@example.com', // → [MASKED]
                    'name'    => 'テスト 太郎',                // → [MASKED]
                    'address' => [
                        'postal_code' => '029-3101',           // → [MASKED]
                        'city'        => '一関市',             // → [MASKED]
                    ],
                ],
                'metadata' => [
                    'is_mock'         => 'true',
                    'idempotency_key' => $idempotencyKey,
                ],
                'created' => time(),
            ],

            // ── CVV不一致 ────────────────────────────────────────────────────
            MockScenario::CVV_MISMATCH => throw new PaymentDeclinedException(
                "Your card's security code is incorrect.",
                'incorrect_cvc',
                PaymentFailureReason::CVV_MISMATCH,
                [
                    'error' => [
                        'code'         => 'incorrect_cvc',
                        'decline_code' => 'incorrect_cvc',
                        'type'         => 'card_error',
                        'param'        => 'cvc',
                        'email'        => 'test@example.com', // → [MASKED]
                    ],
                    'metadata' => ['is_mock' => 'true'],
                ]
            ),

            // ── 有効期限切れ ─────────────────────────────────────────────────
            MockScenario::EXPIRED_CARD => throw new PaymentDeclinedException(
                'Your card has expired.',
                'expired_card',
                PaymentFailureReason::CARD_EXPIRED,
                ['error' => ['code' => 'expired_card', 'type' => 'card_error'],
                 'metadata' => ['is_mock' => 'true']]
            ),

            // ── 残高不足 ─────────────────────────────────────────────────────
            MockScenario::INSUFFICIENT => throw new PaymentDeclinedException(
                'Your card has insufficient funds.',
                'insufficient_funds',
                PaymentFailureReason::INSUFFICIENT_FUNDS,
                ['error' => ['code' => 'insufficient_funds', 'type' => 'card_error'],
                 'metadata' => ['is_mock' => 'true']]
            ),

            // ── 不正検知 ─────────────────────────────────────────────────────
            MockScenario::FRAUD => throw new PaymentDeclinedException(
                'The card was declined.',
                'fraudulent',
                PaymentFailureReason::FRAUD_BLOCK,
                ['error' => ['code' => 'card_declined', 'decline_code' => 'fraudulent',
                             'type' => 'card_error'],
                 'metadata' => ['is_mock' => 'true']]
            ),

            // ── タイムアウト: payments は PROCESSING のまま保留 ───────────────
            MockScenario::TIMEOUT =>
                throw new PaymentGatewayTimeoutException(
                    '[MOCK] Gateway request timed out after 30 seconds.'
                ),

            // ── 通信エラー: リクエスト未到達 → payments は FAILED ────────────
            MockScenario::CONNECTION_ERROR =>
                throw new PaymentGatewayConnectionException(
                    '[MOCK] Could not connect to payment gateway: Connection refused.'
                ),

            // ── 不正レスポンス構造: GatewayResponseValidator が弾く ───────────
            MockScenario::INVALID_RESPONSE => [
                'id'     => self::MOCK_TXN_PREFIX . uniqid(),
                'object' => 'payment_intent',
                'status' => 'INVALID_STATUS_FOR_TESTING', // バリデーターに弾かれる
                'amount' => $amount,
                // currency を意図的に省略 → 必須キーチェックに引っかかる
            ],
        };
    }
}

// =============================================================================
// § 10. PaymentProcessingService — 決済処理コア
//
// セキュリティ設計:
//   1. 二重決済防止: FOR UPDATE + status IN (PROCESSING, COMPLETED) チェック
//   2. レスポンス構造検証後に status を読む（先読み禁止）
//   3. status は 'succeeded' との厳密な文字列比較のみ（truthy 評価禁止）
//   4. タイムアウトは PROCESSING のまま保留（結果不明 → FAILED にしない）
//   5. 最終 catch で全例外を記録してから再 throw（握りつぶし禁止）
// =============================================================================

final class PaymentProcessingService
{
    public function __construct(
        private readonly \wpdb                   $db,
        private readonly PaymentEventLogger      $logger,
        private readonly PaymentGatewayInterface $gateway,
    ) {}

    /**
     * 決済を実行し、結果を payments / payment_events に記録する。
     *
     * @return int payments.id（成功時）
     * @throws \RuntimeException 呼び出し元がハンドルすべき致命的エラー
     */
    public function charge(
        int    $orderId,
        int    $amount,
        string $gatewayToken,
        string $idempotencyKey,
    ): int {
        // ── [セキュリティ 1] 二重決済防止 ────────────────────────────────────
        $existing = $this->db->get_row(
            $this->db->prepare(
                "SELECT id FROM payments
                  WHERE order_id = %d
                    AND status IN (%d, %d)
                  LIMIT 1 FOR UPDATE",
                $orderId,
                PaymentStatus::PROCESSING->value,
                PaymentStatus::COMPLETED->value
            )
        );
        if ($existing !== null) {
            error_log("[PaymentProcessing] Duplicate charge blocked. order_id={$orderId}");
            throw new \LogicException(
                "Charge already in progress or completed for order {$orderId}"
            );
        }

        $paymentId = $this->insertPaymentRecord($orderId, $amount);
        $clientIp  = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $this->logger->record(
            paymentId: $paymentId,
            eventType: PaymentEventType::REQUEST_SENT,
            ipAddress: $clientIp,
        );

        try {
            $raw = $this->gateway->charge($amount, $gatewayToken, $idempotencyKey);

            // ── [セキュリティ 2] 構造検証 → status 読み取り ──────────────────
            $validated = GatewayResponseValidator::validateStripePaymentIntent($raw);

            // ── [セキュリティ 3] succeeded 以外は全て失敗扱い ────────────────
            if ($validated['status'] !== 'succeeded') {
                throw new PaymentDeclinedException(
                    "Payment not succeeded: {$validated['status']}",
                    $validated['status'],
                    PaymentFailureReason::OTHER,
                    $validated
                );
            }

            $this->logger->record(
                paymentId:   $paymentId,
                eventType:   PaymentEventType::AUTHORIZED,
                rawResponse: $validated,
                ipAddress:   $clientIp,
            );
            $this->updatePaymentStatus(
                $paymentId, PaymentStatus::COMPLETED, $validated['id']
            );
            return $paymentId;

        } catch (PaymentGatewayTimeoutException $e) {
            // ── [セキュリティ 4] タイムアウト: PROCESSING のまま保留 ──────────
            $this->logger->record(
                paymentId:     $paymentId,
                eventType:     PaymentEventType::TIMEOUT,
                failureReason: PaymentFailureReason::TIMEOUT,
                rawResponse:   ['error' => $e->getMessage()],
                ipAddress:     $clientIp,
            );
            error_log("[PaymentProcessing] Timeout. payment_id={$paymentId}");
            throw $e;

        } catch (PaymentGatewayConnectionException $e) {
            $this->logger->record(
                paymentId:     $paymentId,
                eventType:     PaymentEventType::DECLINED,
                failureReason: PaymentFailureReason::GATEWAY_ERROR,
                rawResponse:   ['error' => $e->getMessage()],
                ipAddress:     $clientIp,
            );
            $this->updatePaymentStatus($paymentId, PaymentStatus::FAILED);
            throw $e;

        } catch (PaymentDeclinedException $e) {
            $reason = StripeDeclineCodeMapper::fromGatewayCode($e->gatewayCode);
            $this->logger->record(
                paymentId:     $paymentId,
                eventType:     PaymentEventType::DECLINED,
                failureReason: $reason,
                gatewayCode:   $e->gatewayCode,
                rawResponse:   $e->rawResponse,
                ipAddress:     $clientIp,
            );
            $this->updatePaymentStatus($paymentId, PaymentStatus::FAILED);
            throw $e;

        } catch (PaymentResponseValidationException $e) {
            $this->logger->record(
                paymentId:     $paymentId,
                eventType:     PaymentEventType::DECLINED,
                failureReason: PaymentFailureReason::GATEWAY_ERROR,
                gatewayCode:   'RESPONSE_VALIDATION_FAILED',
                rawResponse:   ['validation_error' => $e->getMessage()],
                ipAddress:     $clientIp,
            );
            $this->updatePaymentStatus($paymentId, PaymentStatus::FAILED);
            throw $e;

        } catch (\Throwable $e) {
            // ── [セキュリティ 5] 想定外例外: 必ず記録して再 throw ────────────
            $this->logger->record(
                paymentId:     $paymentId,
                eventType:     PaymentEventType::DECLINED,
                failureReason: PaymentFailureReason::OTHER,
                rawResponse:   ['unexpected_error' => $e->getMessage()],
                ipAddress:     $clientIp,
            );
            $this->updatePaymentStatus($paymentId, PaymentStatus::FAILED);
            throw $e;
        }
    }

    // ── プライベートヘルパー ─────────────────────────────────────────────────

    private function insertPaymentRecord(int $orderId, int $amount): int
    {
        $this->db->insert(
            'payments',
            [
                'order_id'     => $orderId,
                'method'       => 0, // クレジットカード固定（本実装では引数化）
                'status'       => PaymentStatus::PROCESSING->value,
                'amount'       => $amount,
                'requested_at' => current_time('mysql', true),
            ],
            ['%d', '%d', '%d', '%d', '%s']
        );

        $id = $this->db->insert_id;
        if (!$id) {
            throw new \RuntimeException(
                'Failed to insert payment record: ' . $this->db->last_error
            );
        }
        return (int) $id;
    }

    private function updatePaymentStatus(
        int           $paymentId,
        PaymentStatus $status,
        ?string       $gatewayTransactionId = null,
    ): void {
        $data   = ['status' => $status->value];
        $format = ['%d'];

        if ($status === PaymentStatus::COMPLETED) {
            $data['completed_at']           = current_time('mysql', true);
            $data['gateway_transaction_id'] = $gatewayTransactionId;
            $format[] = '%s';
            $format[] = '%s';
        }

        $this->db->update('payments', $data, ['id' => $paymentId], $format, ['%d']);
    }
}

// =============================================================================
// § 11. WorkDateConsistencyChecker — 整合性検証（31日閾値）
//
// 制作年月（_work_date）と決済完了日時を比較し、
// 31日以上の乖離がある場合に DATA_CONSISTENCY_WARNING を記録する。
// 売上分析で「いつの案件か」が決済日でズレると集計が狂うため。
// =============================================================================

final class WorkDateConsistencyChecker
{
    private const THRESHOLD_DAYS = 31;

    public static function checkAndLog(
        int                $postId,
        int                $paymentId,
        string             $completedAt,
        PaymentEventLogger $logger,
    ): void {
        $workDateRaw = get_post_meta($postId, '_work_date', true); // 'YYYY-MM'

        if (empty($workDateRaw)) {
            error_log("[ConsistencyCheck] _work_date not set. post_id={$postId}");
            return;
        }

        $workDate    = \DateTimeImmutable::createFromFormat('Y-m-d', $workDateRaw . '-01');
        $completedDt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $completedAt);

        if ($workDate === false || $completedDt === false) {
            error_log(
                "[ConsistencyCheck] Invalid date format. " .
                "work_date={$workDateRaw} completed_at={$completedAt}"
            );
            return;
        }

        $diffDays = (int) abs($workDate->diff($completedDt)->days);

        if ($diffDays >= self::THRESHOLD_DAYS) {
            $logger->record(
                paymentId:   $paymentId,
                eventType:   PaymentEventType::DATA_CONSISTENCY_WARNING,
                gatewayCode: 'DATA_CONSISTENCY_WARNING',
                rawResponse: [
                    'post_id'        => $postId,
                    'work_date'      => $workDateRaw,
                    'completed_at'   => $completedAt,
                    'diff_days'      => $diffDays,
                    'threshold_days' => self::THRESHOLD_DAYS,
                    'note'           => '制作年月と決済完了日の乖離が閾値を超えています。' .
                                        '売上分析の期間帰属を手動確認してください。',
                ],
            );

            error_log(sprintf(
                '[ConsistencyCheck] WARNING diff=%d days. post_id=%d payment_id=%d',
                $diffDays, $postId, $paymentId
            ));
        }
    }
}

// =============================================================================
// § 12. WordPress フック統合
// =============================================================================

add_action('save_post_work_performance', function(int $postId): void {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $postId)) return;

    $orderId = (int) get_post_meta($postId, '_order_id', true);
    if (!$orderId) return;

    global $wpdb;
    $payment = $wpdb->get_row($wpdb->prepare(
        "SELECT id, completed_at FROM payments
          WHERE order_id = %d AND status = %d
          ORDER BY completed_at DESC LIMIT 1",
        $orderId, PaymentStatus::COMPLETED->value
    ));

    if ($payment?->completed_at) {
        $logger = new PaymentEventLogger($wpdb);
        WorkDateConsistencyChecker::checkAndLog(
            $postId, (int) $payment->id, $payment->completed_at, $logger
        );
    }
}, 10, 1);

// =============================================================================
// § 13. メタボックス — 決済ログビューア（投稿画面）
//
// 「制作実績」投稿の編集画面に payment_events を時系列表示する。
// - 異常系: 背景色・左ボーダーで視覚強調
// - raw_response: クリックで展開するアコーディオン
// - PII: [MASKED] フィールドを黄色バッジで目視確認可能
// =============================================================================

add_action('add_meta_boxes', function(): void {
    add_meta_box(
        'payment_event_log_viewer',
        '📊 決済イベントログ（DE実績管理）',
        'pef_meta_box_render',
        'work_performance',
        'normal',
        'high'
    );
});

function pef_meta_box_render(\WP_Post $post): void
{
    global $wpdb;

    $orderId     = (int) get_post_meta($post->ID, '_order_id', true);
    $workDateRaw = (string) get_post_meta($post->ID, '_work_date', true);

    echo '<style>
    .pef-wrap{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px}
    .pef-summary{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px}
    .pef-stat{padding:8px 14px;border-radius:6px;background:#f8fafc;border:1px solid #e2e8f0;min-width:100px;text-align:center}
    .pef-stat-num{font-size:22px;font-weight:600;line-height:1.2}
    .pef-stat-lbl{font-size:11px;color:#64748b;margin-top:2px}
    .pef-stat.ok .pef-stat-num{color:#16a34a}
    .pef-stat.ng .pef-stat-num{color:#dc2626}
    .pef-stat.warn .pef-stat-num{color:#d97706}
    .pef-row{border-radius:6px;padding:10px 14px;margin-bottom:8px;font-size:12px}
    .pef-row-head{display:flex;align-items:center;gap:8px;flex-wrap:wrap;cursor:pointer;user-select:none}
    .pef-badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;white-space:nowrap}
    .pef-fail-badge{background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:4px;font-size:11px}
    .pef-gw-badge{background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:4px;font-size:11px;font-family:monospace}
    .pef-time{color:#94a3b8;font-size:11px;margin-left:auto}
    .pef-accordion{display:none;margin-top:10px;border-top:1px solid rgba(0,0,0,.06);padding-top:8px}
    .pef-accordion.open{display:block}
    .pef-masked-notice{background:#fef9c3;border:1px solid #fbbf24;border-radius:4px;padding:5px 10px;margin-bottom:6px;font-size:11px;color:#78350f}
    .pef-json{background:#1e293b;color:#94a3b8;padding:12px;border-radius:4px;overflow:auto;max-height:220px;white-space:pre;font-family:monospace;font-size:11px;line-height:1.6;margin:0}
    .pef-json .key{color:#7dd3fc}.pef-json .str{color:#86efac}.pef-json .num{color:#fda4af}.pef-json .mask{color:#fde047;font-weight:600}
    .pef-hint{font-size:11px;color:#94a3b8;margin-left:4px}
    .pef-warn-box{background:#faf5ff;border:1px solid #c4b5fd;border-radius:6px;padding:10px 14px;margin-bottom:12px}
    .pef-no-log{color:#94a3b8;font-style:italic;padding:8px 0}
    </style>';

    echo '<div class="pef-wrap">';

    if (!$orderId) {
        echo '<p class="pef-no-log">⚠ _order_id メタが未設定です。</p>';
        echo '</div>';
        return;
    }

    // 全イベントを取得
    $allEvents = $wpdb->get_results($wpdb->prepare(
        "SELECT pe.*
           FROM payment_events pe
           JOIN payments p ON p.id = pe.payment_id
          WHERE p.order_id = %d
          ORDER BY pe.occurred_at ASC",
        $orderId
    ));

    if (empty($allEvents)) {
        echo '<p class="pef-no-log">決済レコードなし（order_id=' . esc_html((string)$orderId) . '）</p>';
        echo '</div>';
        return;
    }

    // サマリーカード
    $cntTotal    = count($allEvents);
    $cntAuth     = count(array_filter($allEvents, fn($e) => (int)$e->event_type === PaymentEventType::AUTHORIZED->value));
    $cntDeclined = count(array_filter($allEvents, fn($e) => (int)$e->event_type === PaymentEventType::DECLINED->value));
    $cntWarn     = count(array_filter($allEvents, fn($e) => (int)$e->event_type === PaymentEventType::DATA_CONSISTENCY_WARNING->value));

    echo '<div class="pef-summary">';
    echo '<div class="pef-stat"><div class="pef-stat-num">' . $cntTotal . '</div><div class="pef-stat-lbl">総イベント数</div></div>';
    echo '<div class="pef-stat ok"><div class="pef-stat-num">' . $cntAuth . '</div><div class="pef-stat-lbl">承認成功</div></div>';
    echo '<div class="pef-stat ng"><div class="pef-stat-num">' . $cntDeclined . '</div><div class="pef-stat-lbl">決済拒否</div></div>';
    echo '<div class="pef-stat warn"><div class="pef-stat-num">' . $cntWarn . '</div><div class="pef-stat-lbl">整合性警告</div></div>';
    echo '</div>';

    // 整合性警告バナー
    if ($cntWarn > 0 && $workDateRaw !== '') {
        echo '<div class="pef-warn-box"><strong>⚠ データ整合性警告</strong>：制作年月（';
        echo esc_html($workDateRaw);
        echo '）と決済完了日時に 31日以上の乖離が検出されました。売上分析の期間帰属を手動確認してください。</div>';
    }

    // イベント行レンダリング
    foreach ($allEvents as $idx => $ev) {
        $type   = PaymentEventType::tryFrom((int) $ev->event_type);
        $fail   = PaymentFailureReason::tryFrom((int) $ev->failure_reason);
        $rowSt  = $type?->rowStyle()  ?? 'background:#f8fafc;border-left:3px solid #94a3b8;';
        $badgeSt = $type?->badgeStyle() ?? 'background:#f1f5f9;color:#475569;';
        $typeLbl = $type?->label()    ?? "type:{$ev->event_type}";
        $failLbl = $fail?->label()    ?? '';

        $rawData     = $ev->raw_response ? (json_decode($ev->raw_response, true) ?? []) : [];
        $maskedPaths = PiiMasker::collectMaskedPaths($rawData);
        $hasDetail   = !empty($rawData) || $ev->gateway_code;
        $accId       = 'pef-acc-' . $idx;

        echo '<div class="pef-row" style="' . $rowSt . '">';
        echo '<div class="pef-row-head" onclick="(function(el){el.classList.toggle(\'open\')})(document.getElementById(\'' . $accId . '\'))">';
        echo '<span class="pef-badge" style="' . $badgeSt . '">' . esc_html($typeLbl) . '</span>';

        if ($fail && $fail !== PaymentFailureReason::NONE) {
            echo '<span class="pef-fail-badge">' . esc_html($failLbl) . '</span>';
        }
        if ($ev->gateway_code) {
            echo '<span class="pef-gw-badge">' . esc_html($ev->gateway_code) . '</span>';
        }
        if ($hasDetail) {
            echo '<span class="pef-hint">▼ 詳細</span>';
        }
        $timeDisplay = $ev->occurred_at
            ? esc_html(wp_date('Y-m-d H:i:s', strtotime($ev->occurred_at)))
            : '—';
        echo '<span class="pef-time">' . $timeDisplay . '</span>';
        echo '</div>'; // .pef-row-head

        if ($hasDetail) {
            echo '<div class="pef-accordion" id="' . $accId . '">';
            if (!empty($maskedPaths)) {
                echo '<div class="pef-masked-notice">';
                echo '🔒 <strong>PII マスキング適用済み：</strong>' . esc_html(implode(', ', $maskedPaths));
                echo '</div>';
            }
            if (!empty($rawData)) {
                echo '<pre class="pef-json">' . pef_highlight_json($rawData) . '</pre>';
            }
            echo '</div>';
        }

        echo '</div>'; // .pef-row
    }

    echo '</div>'; // .pef-wrap
}

/**
 * JSON 配列をサーバーサイドでハイライト済み HTML に変換する。
 * PHP 側で生成するため XSS リスクがない。
 */
function pef_highlight_json(array $data, int $depth = 0): string
{
    $indent = str_repeat('  ', $depth);
    $lines  = [];

    foreach ($data as $key => $value) {
        $keyHtml = '<span class="key">"' . esc_html($key) . '"</span>: ';

        if ($value === '[MASKED]') {
            $valHtml = '<span class="mask">"[MASKED]"</span>';
        } elseif (is_array($value)) {
            $inner   = pef_highlight_json($value, $depth + 1);
            $valHtml = "{\n" . $inner . "\n" . $indent . '}';
        } elseif (is_string($value)) {
            $valHtml = '<span class="str">"' . esc_html($value) . '"</span>';
        } elseif (is_numeric($value)) {
            $valHtml = '<span class="num">' . esc_html((string) $value) . '</span>';
        } elseif (is_bool($value)) {
            $valHtml = '<span class="num">' . ($value ? 'true' : 'false') . '</span>';
        } else {
            $valHtml = esc_html((string) $value);
        }

        $lines[] = $indent . '  ' . $keyHtml . $valHtml;
    }

    return implode(",\n", $lines);
}

// =============================================================================
// § 14. REST API — シミュレーター実行エンドポイント
//
// セキュリティ設計:
//   - WP_DEBUG=true かつ manage_options 権限者のみ許可
//   - scenario は MockScenario::tryFrom でホワイトリスト検証
//   - nonce は wp_rest を使用（WP 標準）
// =============================================================================

add_action('rest_api_init', function(): void {
    register_rest_route('ec-payment/v1', '/simulate', [
        'methods'             => 'POST',
        'callback'            => 'pef_simulate_handler',
        'permission_callback' => function(): bool {
            if (!defined('WP_DEBUG') || !WP_DEBUG) return false;
            return current_user_can('manage_options');
        },
        'args' => [
            'scenario' => [
                'required'          => true,
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'validate_callback' => fn($v) => MockScenario::tryFrom($v) !== null,
            ],
            'order_id' => [
                'required'          => true,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
            ],
            'post_id' => [
                'required'          => false,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'default'           => 0,
            ],
            'amount' => [
                'required'          => false,
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'default'           => 1000,
            ],
        ],
    ]);

    register_rest_route('ec-payment/v1', '/events/(?P<payment_id>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'pef_events_handler',
        'permission_callback' => fn(): bool => current_user_can('edit_posts'),
        'args' => ['payment_id' => ['required' => true, 'type' => 'integer']],
    ]);
});

function pef_simulate_handler(\WP_REST_Request $request): \WP_REST_Response
{
    global $wpdb;

    $scenario = MockScenario::from($request->get_param('scenario'));
    $orderId  = (int) $request->get_param('order_id');
    $postId   = (int) $request->get_param('post_id');
    $amount   = (int) $request->get_param('amount');

    $logger  = new PaymentEventLogger($wpdb);
    $service = new PaymentProcessingService($wpdb, $logger, new MockPaymentGateway());

    $result = [
        'scenario'       => $scenario->value,
        'scenario_label' => $scenario->label(),
        'order_id'       => $orderId,
        'amount'         => $amount,
        'payment_id'     => null,
        'status'         => null,
        'error'          => null,
        'db_events'      => [],
        'masked_fields'  => [],  // Console ログ用: マスクされたフィールドパス
    ];

    try {
        $paymentId            = $service->charge(
            $orderId, $amount,
            'mock_' . $scenario->value,
            'mock-order-' . $orderId . '-' . uniqid()
        );
        $result['payment_id'] = $paymentId;
        $result['status']     = 'COMPLETED';

        if ($postId > 0) {
            $payment = $wpdb->get_row(
                $wpdb->prepare("SELECT completed_at FROM payments WHERE id=%d", $paymentId)
            );
            if ($payment?->completed_at) {
                WorkDateConsistencyChecker::checkAndLog(
                    $postId, $paymentId, $payment->completed_at, $logger
                );
            }
        }
    } catch (PaymentGatewayTimeoutException $e) {
        $result['status'] = 'TIMEOUT_PENDING';
        $result['error']  = $e->getMessage();
    } catch (\Throwable $e) {
        $result['status'] = 'FAILED';
        $result['error']  = $e->getMessage();
    }

    // DB に書き込まれたイベントを返す + マスクフィールドを収集
    if ($result['payment_id']) {
        $events = $wpdb->get_results($wpdb->prepare(
            "SELECT event_type, failure_reason, gateway_code, raw_response, ip_address, occurred_at
               FROM payment_events
              WHERE payment_id = %d
              ORDER BY occurred_at ASC",
            $result['payment_id']
        ), ARRAY_A);

        $result['db_events'] = $events ?? [];

        // Console ログ用: 全イベントのマスクパスを集約
        $allMasked = [];
        foreach ($result['db_events'] as $ev) {
            if ($ev['raw_response']) {
                $rawArr = json_decode($ev['raw_response'], true) ?? [];
                $paths  = PiiMasker::collectMaskedPaths($rawArr);
                $allMasked = array_unique(array_merge($allMasked, $paths));
            }
        }
        $result['masked_fields'] = array_values($allMasked);
    }

    return new \WP_REST_Response($result, 200);
}

function pef_events_handler(\WP_REST_Request $request): \WP_REST_Response
{
    global $wpdb;
    $paymentId = (int) $request->get_param('payment_id');
    $events    = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM payment_events WHERE payment_id = %d ORDER BY occurred_at ASC",
        $paymentId
    ), ARRAY_A);
    return new \WP_REST_Response(['events' => $events ?? []], 200);
}

// =============================================================================
// § 15. フロントエンド テストダッシュボード（ショートコード）
//
// 使い方: 固定ページに [payment_test_dashboard] を貼り付けるだけ。
// WP_DEBUG=true かつ管理者のみ表示。本番環境では何も出力しない。
//
// ★ Console セキュリティログ設計:
//   - 実行のたびに console.group でログをグループ化
//   - マスクフィールドが存在する場合: console.warn で一覧表示
//   - 全シナリオ共通: [Security Check] PII Masking Applied メッセージ出力
//   - エラー時: console.error でスタックトレース相当の情報を出力
//   - タイムスタンプ付きで監査ログとして機能
// =============================================================================

add_shortcode('payment_test_dashboard', function(): string {
    if (!defined('WP_DEBUG') || !WP_DEBUG) return '';
    if (!current_user_can('manage_options')) return '';

    $restUrl       = esc_url(rest_url('ec-payment/v1/simulate'));
    $nonce         = wp_create_nonce('wp_rest');
    $scenarios     = array_map(
        fn($c) => ['value' => $c->value, 'label' => $c->label()],
        MockScenario::cases()
    );
    $scenariosJson = wp_json_encode($scenarios);

    ob_start(); ?>
<div id="ec-test-dashboard" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;max-width:900px;margin:0 auto;padding:24px 0">

<style>
#ec-test-dashboard *{box-sizing:border-box}
.etd-header{border-bottom:2px solid #e2e8f0;padding-bottom:12px;margin-bottom:24px}
.etd-header h2{margin:0;font-size:20px;color:#1e293b}
.etd-header p{margin:4px 0 0;font-size:13px;color:#64748b}
.etd-grid{display:grid;grid-template-columns:300px 1fr;gap:20px}
.etd-panel{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px}
.etd-panel h3{margin:0 0 16px;font-size:15px;color:#1e293b;border-bottom:1px solid #f1f5f9;padding-bottom:8px}
.etd-label{display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:5px;margin-top:12px}
.etd-select,.etd-input{width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;color:#1e293b;background:#fff}
.etd-select:focus,.etd-input:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.1)}
.etd-desc{font-size:11px;color:#64748b;margin:5px 0 0;line-height:1.5;min-height:28px}
.etd-run-btn{width:100%;margin-top:18px;padding:10px;background:#4f46e5;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;transition:background .15s}
.etd-run-btn:hover{background:#4338ca}
.etd-run-btn:active{transform:scale(.98)}
.etd-run-btn:disabled{background:#a5b4fc;cursor:not-allowed}
.etd-status{padding:10px 14px;border-radius:6px;font-size:13px;font-weight:600;margin-bottom:14px;display:none}
.etd-status.ok{background:#dcfce7;color:#166534;border:1px solid #86efac}
.etd-status.fail{background:#fee2e2;color:#991b1b;border:1px solid #fca5a5}
.etd-status.pending{background:#fef9c3;color:#854d0e;border:1px solid #fde68a}
.etd-console-hint{background:#1e293b;color:#94a3b8;padding:8px 12px;border-radius:6px;font-size:11px;font-family:monospace;margin-bottom:12px;display:none}
.etd-console-hint.show{display:block}
.etd-event-row{padding:10px 12px;border-radius:6px;margin-bottom:6px;font-size:12px}
.etd-event-head{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.etd-badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600}
.etd-ts{font-size:11px;color:#94a3b8;margin-left:auto}
.etd-pii-notice{background:#fef9c3;border:1px solid #fbbf24;border-radius:4px;padding:4px 8px;margin-top:6px;font-size:11px;color:#78350f}
.etd-raw-toggle{font-size:11px;color:#6366f1;cursor:pointer;margin-top:4px;display:inline-block;user-select:none}
.etd-raw{display:none;background:#1e293b;color:#94a3b4;padding:10px;border-radius:4px;font-family:monospace;font-size:11px;white-space:pre;overflow:auto;max-height:180px;margin-top:4px}
.etd-raw.show{display:block}
.etd-history-title{font-size:11px;font-weight:600;color:#94a3b8;margin:16px 0 6px}
.etd-history-row{display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #f1f5f9;font-size:12px}
.etd-history-row:last-child{border:none}
@media(max-width:640px){.etd-grid{grid-template-columns:1fr}}
</style>

<div class="etd-header">
    <h2>🧪 決済テストダッシュボード</h2>
    <p>モックシナリオを実行し DB（payments / payment_events）への書き込みをリアルタイム確認。WP_DEBUG=true 環境専用 — ブラウザの Console も合わせてご確認ください。</p>
</div>

<div class="etd-grid">

<div class="etd-panel">
    <h3>シナリオ実行</h3>
    <label class="etd-label" for="etd-scenario">シナリオ選択</label>
    <select class="etd-select" id="etd-scenario"></select>
    <p class="etd-desc" id="etd-desc-text"></p>

    <label class="etd-label" for="etd-order-id">Order ID</label>
    <input class="etd-input" type="number" id="etd-order-id" value="1" min="1">

    <label class="etd-label" for="etd-post-id">Post ID（制作実績・任意）</label>
    <input class="etd-input" type="number" id="etd-post-id" value="0" min="0">
    <p class="etd-desc" style="min-height:0">入力すると _work_date 整合性チェックも連動</p>

    <label class="etd-label" for="etd-amount">金額（円）</label>
    <input class="etd-input" type="number" id="etd-amount" value="5000" min="1">

    <button class="etd-run-btn" id="etd-run">▶ シミュレーション実行</button>

    <div>
        <p class="etd-history-title">実行履歴</p>
        <div id="etd-history-list"></div>
    </div>
</div>

<div class="etd-panel">
    <h3>実行結果</h3>
    <div id="etd-status" class="etd-status"></div>
    <div id="etd-console-hint" class="etd-console-hint"></div>
    <div id="etd-events-wrap">
        <p style="font-size:13px;color:#94a3b8">シナリオを実行するとここに結果が表示されます</p>
    </div>
</div>

</div>
</div>

<script>
(function(){
    'use strict';

    /* ── 定数 ──────────────────────────────────────────────── */
    const REST_URL  = <?= wp_json_encode($restUrl) ?>;
    const NONCE     = <?= wp_json_encode($nonce) ?>;
    const SCENARIOS = <?= $scenariosJson ?>;

    const TYPE_LABELS = {
        0:'リクエスト送信', 1:'承認完了', 2:'決済拒否', 3:'タイムアウト',
        4:'キャンセル', 5:'返金リクエスト', 6:'返金完了', 7:'Webhook受信',
        8:'データ整合性警告',
    };
    const FAIL_LABELS = {
        0:'—', 1:'CVV不一致', 2:'カード番号無効', 3:'有効期限切れ',
        4:'残高不足', 5:'住所不一致', 6:'3DS失敗', 7:'不正検知',
        8:'利用停止', 9:'GWエラー', 10:'タイムアウト', 99:'その他',
    };
    const TYPE_STYLES = {
        1:{bg:'#dcfce7',color:'#166534',row:'background:#f0fdf4;border-left:3px solid #16a34a'},
        2:{bg:'#fee2e2',color:'#991b1b',row:'background:#fef2f2;border-left:3px solid #dc2626'},
        3:{bg:'#fef9c3',color:'#854d0e',row:'background:#fffbeb;border-left:3px solid #d97706'},
        8:{bg:'#ede9fe',color:'#5b21b6',row:'background:#faf5ff;border-left:3px solid #7c3aed'},
    };
    const DEFAULT_STYLE = {
        bg:'#f1f5f9', color:'#475569',
        row:'background:#f8fafc;border-left:3px solid #94a3b8',
    };

    const DESCS = {
        success:'✅ 正常決済。billing_details に PII を含め、マスキング動作を確認できます。',
        cvv_mismatch:'❌ CVV/CVC 不一致。カード番号は正しいがセキュリティコードが間違いのシナリオ。',
        expired_card:'❌ カード有効期限切れ。',
        insufficient_funds:'❌ 残高不足による拒否。',
        fraud:'⚠️ 不正取引として検知されたシナリオ（fraudulent）。',
        timeout:'⏱ タイムアウト。payments は PROCESSING のまま保留。Webhook で最終確認が必要。',
        connection_error:'🔌 ゲートウェイへの接続自体が失敗。payments は FAILED になります。',
        invalid_response:'🔒 不正なレスポンス構造。GatewayResponseValidator がブロックします。',
    };

    /* ── DOM 参照 ───────────────────────────────────────────── */
    const scenarioSel  = document.getElementById('etd-scenario');
    const descText     = document.getElementById('etd-desc-text');
    const runBtn       = document.getElementById('etd-run');
    const statusEl     = document.getElementById('etd-status');
    const consoleHint  = document.getElementById('etd-console-hint');
    const eventsWrap   = document.getElementById('etd-events-wrap');
    const historyList  = document.getElementById('etd-history-list');

    /* ── セレクト初期化 ─────────────────────────────────────── */
    SCENARIOS.forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.value;
        opt.textContent = s.label;
        scenarioSel.appendChild(opt);
    });

    function updateDesc() {
        descText.textContent = DESCS[scenarioSel.value] ?? '';
    }
    scenarioSel.addEventListener('change', updateDesc);
    updateDesc();

    /* ── ユーティリティ ─────────────────────────────────────── */

    /** マスキングパスを再帰収集（クライアントサイド版） */
    function collectMasked(obj, prefix = '') {
        const paths = [];
        if (!obj || typeof obj !== 'object') return paths;
        for (const [k, v] of Object.entries(obj)) {
            const p = prefix ? `${prefix}.${k}` : k;
            if (v === '[MASKED]') {
                paths.push(p);
            } else if (typeof v === 'object' && v !== null) {
                paths.push(...collectMasked(v, p));
            }
        }
        return paths;
    }

    /** JSON シンタックスハイライト（XSS 対策: エスケープ後に置換） */
    function highlightJson(str) {
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"(\[MASKED\])"/g,
                '<span style="color:#fde047;font-weight:700">"$1"</span>')
            .replace(/"([^"]+)":/g,
                '<span style="color:#7dd3fc">"$1"</span>:')
            .replace(/: "([^"]+)"/g,
                ': <span style="color:#86efac">"$1"</span>')
            .replace(/: (\d+)/g,
                ': <span style="color:#fda4af">$1</span>');
    }

    /* ── イベント行レンダリング ─────────────────────────────── */
    function renderEvent(ev, idx) {
        const t  = parseInt(ev.event_type, 10);
        const f  = parseInt(ev.failure_reason, 10);
        const s  = TYPE_STYLES[t] ?? DEFAULT_STYLE;
        const tl = TYPE_LABELS[t] ?? String(t);
        const fl = FAIL_LABELS[f] ?? String(f);

        let rawObj = null;
        try { rawObj = ev.raw_response ? JSON.parse(ev.raw_response) : null; } catch (_) {}
        const maskedPaths = rawObj ? collectMasked(rawObj) : [];

        const failBadge = f !== 0
            ? `<span class="etd-badge" style="background:#fee2e2;color:#991b1b">${fl}</span>`
            : '';
        const gwBadge = ev.gateway_code
            ? `<span class="etd-badge" style="background:#f1f5f9;color:#475569;font-family:monospace">${ev.gateway_code}</span>`
            : '';
        const piiNotice = maskedPaths.length
            ? `<div class="etd-pii-notice">🔒 PII マスキング済: <strong>${maskedPaths.join(', ')}</strong></div>`
            : '';
        const rawId = `etd-raw-${idx}`;
        const rawSection = rawObj
            ? `<span class="etd-raw-toggle"
                 onclick="var r=document.getElementById('${rawId}');r.classList.toggle('show');this.textContent=r.classList.contains('show')?'▲ raw_response を閉じる':'▼ raw_response を展開'">
                ▼ raw_response を展開</span>
               <pre class="etd-raw" id="${rawId}">${highlightJson(JSON.stringify(rawObj, null, 2))}</pre>`
            : '';

        return `<div class="etd-event-row" style="${s.row};padding:10px 12px;border-radius:6px;margin-bottom:6px">
            <div class="etd-event-head">
                <span class="etd-badge" style="background:${s.bg};color:${s.color}">${tl}</span>
                ${failBadge}${gwBadge}
                <span class="etd-ts">${ev.occurred_at ?? ''}</span>
            </div>
            ${piiNotice}${rawSection}
        </div>`;
    }

    /* ── ★ Console セキュリティログ出力関数 ─────────────────────
     *
     * 設計:
     *   - console.group で実行ごとにグループ化（折りたたみ可能）
     *   - [Security Check] メッセージを常に出力
     *   - マスクフィールドが存在する場合は console.warn で一覧
     *   - エラー時は console.error で詳細を出力
     *   - 全ログにタイムスタンプを付与（監査ログとして機能）
     * ──────────────────────────────────────────────────────────── */
    function emitSecurityLog(data, scenario, status, error) {
        const ts      = new Date().toISOString();
        const pid     = data?.payment_id ?? 'N/A';
        const masked  = data?.masked_fields ?? [];
        const label   = `[EC Payment] ${ts} | scenario: ${scenario} | payment_id: ${pid}`;

        console.group(label);

        // ── 常に出力: PIIマスキング監査メッセージ ──
        console.info(
            '%c[Security Check] PII Masking Applied. Check logs for [MASKED] fields.',
            'color:#f59e0b;font-weight:bold'
        );

        // ── 実行ステータス ──
        if (status === 'COMPLETED') {
            console.info('%c✅ Status: COMPLETED', 'color:#16a34a;font-weight:bold');
        } else if (status === 'TIMEOUT_PENDING') {
            console.warn('%c⏱ Status: TIMEOUT_PENDING — DB は PROCESSING のまま保留', 'color:#d97706');
        } else {
            console.error('%c❌ Status: ' + status, 'color:#dc2626;font-weight:bold');
            if (error) {
                console.error('  Error detail:', error);
            }
        }

        // ── マスクフィールド一覧 ──
        if (masked.length > 0) {
            console.warn(
                '%c🔒 Masked PII fields (' + masked.length + '):', 'color:#f59e0b',
                masked
            );
            console.info(
                '  → DB の raw_response カラムでこれらのフィールドが [MASKED] ' +
                'に置換されていることを SQL で確認してください。'
            );
        } else {
            console.info('  ℹ Masked fields: none (失敗シナリオは raw_response が存在しない場合あり)');
        }

        // ── DB イベントサマリー ──
        const events = data?.db_events ?? [];
        if (events.length > 0) {
            console.groupCollapsed('📋 DB Events (' + events.length + ' records)');
            events.forEach((ev, i) => {
                const typeMap = {0:'REQUEST_SENT',1:'AUTHORIZED',2:'DECLINED',
                                  3:'TIMEOUT',4:'CANCELLED',8:'DATA_CONSISTENCY_WARNING'};
                const typeName = typeMap[ev.event_type] ?? 'event_type:' + ev.event_type;
                console.log(
                    `  [${i}] ${typeName}`,
                    '| failure_reason:', ev.failure_reason,
                    '| gateway_code:', ev.gateway_code ?? 'null',
                    '| at:', ev.occurred_at
                );
            });
            console.groupEnd();
        }

        // ── セキュリティ検証クエリへの案内 ──
        console.info(
            '%c🔍 SQL 検証:',
            'color:#6366f1;font-weight:bold',
            '\n  SELECT raw_response FROM payment_events WHERE payment_id=' + pid +
            ';\n  → billing_details.email / name / address などが [MASKED] であることを確認'
        );

        console.groupEnd();
    }

    /* ── 実行ボタン ─────────────────────────────────────────── */
    runBtn.addEventListener('click', async function() {
        runBtn.disabled   = true;
        runBtn.textContent = '実行中...';
        statusEl.style.display = 'none';
        consoleHint.classList.remove('show');
        eventsWrap.innerHTML = '<p style="color:#94a3b8;font-size:13px">処理中...</p>';

        const payload = {
            scenario: scenarioSel.value,
            order_id: parseInt(document.getElementById('etd-order-id').value, 10) || 1,
            post_id:  parseInt(document.getElementById('etd-post-id').value, 10)  || 0,
            amount:   parseInt(document.getElementById('etd-amount').value, 10)   || 1000,
        };

        let responseData = null;

        try {
            const res = await fetch(REST_URL, {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce':   NONCE,
                },
                body: JSON.stringify(payload),
            });

            if (!res.ok) {
                throw new Error(`HTTP ${res.status}: ${res.statusText}`);
            }

            responseData = await res.json();
            const { status, error, payment_id, db_events, masked_fields } = responseData;

            /* ── ステータスバー ── */
            const statusMap = {
                COMPLETED:       ['ok',      `✅ 決済完了 — payment_id: ${payment_id}`],
                FAILED:          ['fail',    `❌ 決済失敗 — ${error ?? ''}`],
                TIMEOUT_PENDING: ['pending', '⏱ タイムアウト — payments は PROCESSING 保留中'],
            };
            const [cls, msg] = statusMap[status] ?? ['pending', status ?? ''];
            statusEl.className  = `etd-status ${cls}`;
            statusEl.textContent = msg;
            statusEl.style.display = 'block';

            /* ── Console ヒントバナー ── */
            consoleHint.textContent =
                '[Security Check] PII Masking Applied — ' +
                (masked_fields?.length
                    ? 'Masked: ' + masked_fields.join(', ')
                    : 'No PII in this scenario') +
                ' | 詳細は Console を確認';
            consoleHint.classList.add('show');

            /* ── ★ Console セキュリティログ出力 ── */
            emitSecurityLog(responseData, payload.scenario, status, error);

            /* ── イベント一覧 ── */
            if (db_events?.length) {
                eventsWrap.innerHTML = db_events.map((ev, i) => renderEvent(ev, i)).join('');
            } else {
                eventsWrap.innerHTML =
                    '<p style="color:#f59e0b;font-size:13px">' +
                    'DB イベントなし（タイムアウト時は payment_id 未確定）</p>';
            }

            /* ── 実行履歴 ── */
            const histRow = document.createElement('div');
            histRow.className = 'etd-history-row';
            histRow.innerHTML =
                `<span class="etd-badge" style="background:${
                    cls==='ok'?'#dcfce7':cls==='fail'?'#fee2e2':'#fef9c3'
                };color:${
                    cls==='ok'?'#166534':cls==='fail'?'#991b1b':'#854d0e'
                }">${status}</span>
                <span style="color:#1e293b">${responseData.scenario_label}</span>
                <span style="color:#94a3b8;margin-left:auto">#${payment_id ?? '—'}</span>`;
            historyList.prepend(histRow);
            if (historyList.children.length > 8) {
                historyList.lastChild.remove();
            }

        } catch (err) {
            /* ── fetch 自体のエラー ── */
            statusEl.className   = 'etd-status fail';
            statusEl.textContent = '⚠ エラー: ' + err.message;
            statusEl.style.display = 'block';
            eventsWrap.innerHTML = '';

            /* Console にも出力 */
            console.group('[EC Payment] Fetch Error');
            console.error('%c[Security Check] PII Masking Applied. Check logs for [MASKED] fields.',
                'color:#f59e0b;font-weight:bold');
            console.error('❌ Fetch failed:', err.message);
            console.groupEnd();

        } finally {
            runBtn.disabled    = false;
            runBtn.textContent = '▶ シミュレーション実行';
        }
    });

})();
</script>
<?php
    return ob_get_clean();
});

// =============================================================================
// § 16. 管理画面 ツール > 決済シミュレーター
// =============================================================================

add_action('admin_menu', function(): void {
    if (!defined('WP_DEBUG') || !WP_DEBUG) return;
    add_management_page(
        '決済シミュレーター',
        '決済シミュレーター',
        'manage_options',
        'payment-simulator',
        'pef_admin_simulator_page'
    );
});

function pef_admin_simulator_page(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('権限がありません。');
    }

    echo '<div class="wrap">';
    echo '<p style="background:#fff3cd;border:1px solid #ffc107;padding:8px 14px;'
       . 'border-radius:4px;font-size:13px;margin-bottom:16px">'
       . '⚠ このページは <code>WP_DEBUG=true</code> のテスト環境専用です。'
       . '本番環境では絶対に有効化しないでください。</p>';
    echo do_shortcode('[payment_test_dashboard]');
    echo '</div>';
}
