-- Phase 4：既存9テーブル・データを保持し、修正提案と感謝の2テーブルだけ追加する。
CREATE TABLE correction_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    schedule_id BIGINT UNSIGNED NOT NULL,
    requested_by_user_id BIGINT UNSIGNED NOT NULL,
    field_name VARCHAR(30) NOT NULL,
    old_value TEXT NULL,
    new_value TEXT NULL,
    reason VARCHAR(1000) NOT NULL,
    source_url VARCHAR(2048) NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX corrections_schedule_status (schedule_id,status,created_at),
    INDEX corrections_requester (requested_by_user_id,created_at),
    INDEX corrections_status (status,created_at),
    CONSTRAINT corrections_schedule_fk FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE RESTRICT,
    CONSTRAINT corrections_user_fk FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    schedule_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    reaction_type ENUM('helped','thanks') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY reactions_unique (schedule_id,user_id,reaction_type),
    INDEX reactions_user (user_id,created_at),
    INDEX reactions_type_date (reaction_type,created_at),
    INDEX reactions_schedule_date (schedule_id,created_at),
    CONSTRAINT reactions_schedule_fk FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE RESTRICT,
    CONSTRAINT reactions_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
