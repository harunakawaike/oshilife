-- Phase 6：既存の予約金額DECIMAL(12,2)を維持し、本人の積立・支出を追加する。
CREATE TABLE savings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    oshi_id BIGINT UNSIGNED NULL,
    amount DECIMAL(12,2) NOT NULL,
    saving_date DATE NOT NULL,
    note TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX savings_owner_date (user_id,saving_date),
    INDEX savings_owner_oshi_date (user_id,oshi_id,saving_date),
    INDEX savings_oshi (oshi_id),
    CONSTRAINT savings_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT savings_oshi_fk FOREIGN KEY (oshi_id) REFERENCES oshis(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE expenses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    oshi_id BIGINT UNSIGNED NULL,
    live_event_id BIGINT UNSIGNED NULL,
    trip_id BIGINT UNSIGNED NULL,
    room_expense_id BIGINT UNSIGNED NULL,
    title VARCHAR(150) NOT NULL,
    category VARCHAR(30) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    expense_date DATE NOT NULL,
    special_effect_eligible TINYINT(1) NOT NULL,
    source_type VARCHAR(30) NOT NULL DEFAULT 'manual',
    source_id BIGINT UNSIGNED NULL,
    note TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY expense_source_once (user_id,source_type,source_id),
    INDEX expenses_owner_date (user_id,expense_date),
    INDEX expenses_owner_oshi_date (user_id,oshi_id,expense_date),
    INDEX expenses_owner_category_date (user_id,category,expense_date),
    INDEX expenses_owner_effect_date (user_id,special_effect_eligible,expense_date),
    INDEX expenses_oshi (oshi_id),
    INDEX expenses_live (live_event_id),
    INDEX expenses_trip (trip_id),
    CONSTRAINT expenses_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT expenses_oshi_fk FOREIGN KEY (oshi_id) REFERENCES oshis(id) ON DELETE RESTRICT,
    CONSTRAINT expenses_live_fk FOREIGN KEY (live_event_id) REFERENCES live_events(id) ON DELETE RESTRICT,
    CONSTRAINT expenses_trip_fk FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- source_idは予約削除後も記録を保つため、交通・宿泊への外部キーにはしない。
-- 反映時は必ずPHPで元予約→遠征→本人を検証する。room系テーブルはまだ作らない。
