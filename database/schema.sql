-- Oshilife v2 Phase 2までの新規環境用スキーマ。作成済みDBには再実行しないこと。
-- DBそのものの作成はREADMEの別手順で行う。DROP/DELETEは含めない。
USE oshilife_v2;

CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    display_name VARCHAR(50) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY users_email_unique (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    theme_color VARCHAR(7) NOT NULL DEFAULT '#F8F5F1',
    accent_color VARCHAR(7) NOT NULL DEFAULT '#986879',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY user_settings_user_unique (user_id),
    CONSTRAINT user_settings_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Phase 2の追加分だけを適用する。users/user_settingsの変更・削除はしない。
-- 接続するDBは.envの専用DBを使用し、既存Oshilifeには実行しない。
CREATE TABLE oshis (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    oshi_type ENUM('group','solo','actor','other') NOT NULL,
    emoji VARCHAR(32) NOT NULL,
    theme_color VARCHAR(7) NOT NULL DEFAULT '#986879',
    created_by_user_id BIGINT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY oshis_name_type_unique (name, oshi_type),
    CONSTRAINT oshis_creator_fk FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE members (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    oshi_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    color_name VARCHAR(40) NOT NULL,
    heart_emoji VARCHAR(8) NOT NULL,
    hex_color VARCHAR(7) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY members_oshi_name_unique (oshi_id, name),
    CONSTRAINT members_oshi_fk FOREIGN KEY (oshi_id) REFERENCES oshis(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 中間テーブルは「誰がどの推しを登録したか」だけを保存する。
-- 推し本体を複製しないため、解除しても他ユーザーが利用する共有マスタは残る。
CREATE TABLE user_oshis (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    oshi_id BIGINT UNSIGNED NOT NULL,
    registered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY user_oshis_user_oshi_unique (user_id, oshi_id),
    CONSTRAINT user_oshis_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT user_oshis_oshi_fk FOREIGN KEY (oshi_id) REFERENCES oshis(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
