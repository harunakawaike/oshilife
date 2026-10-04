-- トークの投稿だけを追加。既存30テーブルと精算状態は変更しない。
CREATE TABLE room_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    display_name_snapshot VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY room_message_timeline (room_id,id),
    KEY room_message_author (room_id,user_id),
    KEY room_message_rate (user_id,created_at),
    CONSTRAINT room_message_member_fk FOREIGN KEY (room_id,user_id) REFERENCES room_members(room_id,user_id) ON DELETE RESTRICT,
    CONSTRAINT room_message_status CHECK (status IN ('active','deleted')),
    CONSTRAINT room_message_length CHECK (CHAR_LENGTH(message) BETWEEN 1 AND 1000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
