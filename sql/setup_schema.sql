-- 決済ログ保存用テーブル構造
DROP TABLE IF EXISTS payments;
CREATE TABLE payments (
    id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id VARCHAR(50) NOT NULL,
    method TINYINT(1) DEFAULT 0,
    status TINYINT(1) DEFAULT 0,
    amount DECIMAL(10, 2) NOT NULL,
    requested_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP TABLE IF EXISTS payment_events;
CREATE TABLE payment_events (
    id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    payment_id BIGINT(20) UNSIGNED,
    event_type VARCHAR(50),
    payload_json TEXT COMMENT '匿名化済みのJSONデータ',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;