-- 招待リンク専用テーブルを追加する。012と既存26テーブル・旧招待履歴は変更しない。
-- 適用前にバックアップ・別DBへの復元確認を行う。生トークンは保存しない。
CREATE TABLE room_invite_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id BIGINT UNSIGNED NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    expires_at DATETIME NOT NULL,
    max_uses INT UNSIGNED NULL,
    used_count INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(10) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY room_invite_token (token_hash),
    KEY room_invite_owner (room_id,created_by_user_id),
    KEY room_invite_listing (room_id,status,expires_at),
    CONSTRAINT room_invite_link_owner_fk FOREIGN KEY (room_id,created_by_user_id) REFERENCES rooms(id,owner_user_id) ON DELETE RESTRICT,
    CONSTRAINT room_invite_link_status CHECK (status IN ('active','revoked','expired')),
    CONSTRAINT room_invite_link_limit CHECK (max_uses IS NULL OR (max_uses>0 AND used_count<=max_uses))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
