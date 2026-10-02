-- Step 3-1：本人のイベント管理にだけ紐づく同行者。既存テーブル・行は変更しない。
-- バックアップ確認後に一度だけ実行する。自分の行も初回の保存操作時に作る。
CREATE TABLE event_participants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    note TEXT NOT NULL,
    self_marker TINYINT UNSIGNED NULL DEFAULT NULL,
    archived_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY participant_scope (id, user_id, event_id),
    -- UNIQUEはNULLの同行者を複数許可し、値1の「自分」だけを一人に制限する。
    UNIQUE KEY participant_self_once (user_id, event_id, self_marker),
    KEY participant_active (user_id, event_id, archived_at, id),
    CONSTRAINT participant_self_allowed CHECK (self_marker IS NULL OR self_marker = 1),
    CONSTRAINT participant_self_active CHECK (self_marker IS NULL OR archived_at IS NULL),
    CONSTRAINT participant_management_fk FOREIGN KEY (user_id, event_id)
        REFERENCES user_event_status (user_id, event_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
