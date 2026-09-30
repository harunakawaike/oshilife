-- Oshilife v2 Phase 3までの新規環境用スキーマ。作成済みDBには再実行しないこと。
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
    -- 旧仕様との互換用。推しテーマカラーは廃止済みで、画面・API・CSVから使用しない。
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

-- Phase 3：既存5テーブルを変更せず、予定の4テーブルだけを追加する。
CREATE TABLE schedules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    oshi_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(150) NOT NULL,
    category VARCHAR(20) NOT NULL,
    schedule_date DATE NOT NULL,
    start_time TIME NULL,
    end_time TIME NULL,
    is_all_day TINYINT(1) NOT NULL DEFAULT 0,
    visibility ENUM('public','private') NOT NULL DEFAULT 'private',
    status ENUM('active','cancelled','deleted') NOT NULL DEFAULT 'active',
    note TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX schedules_owner_date (created_by_user_id, schedule_date),
    INDEX schedules_public_date (visibility, status, schedule_date),
    INDEX schedules_oshi_date (oshi_id, schedule_date, category),
    CONSTRAINT schedules_owner_fk FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT schedules_oshi_fk FOREIGN KEY (oshi_id) REFERENCES oshis(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 予定とメンバーの多対多を表す中間テーブル。メンバー名を複製しない。
CREATE TABLE schedule_members (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    schedule_id BIGINT UNSIGNED NOT NULL,
    member_id BIGINT UNSIGNED NOT NULL,
    UNIQUE KEY schedule_members_unique (schedule_id, member_id),
    INDEX schedule_members_member (member_id),
    CONSTRAINT schedule_members_schedule_fk FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE,
    CONSTRAINT schedule_members_member_fk FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE schedule_sources (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    schedule_id BIGINT UNSIGNED NOT NULL,
    source_type VARCHAR(30) NOT NULL,
    source_url VARCHAR(2048) NULL,
    note VARCHAR(500) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX schedule_sources_schedule (schedule_id),
    CONSTRAINT schedule_sources_schedule_fk FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 同期中は元の予定を参照する。個人編集時だけcustom_*に完全な入力値を保存する。
CREATE TABLE user_schedules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    schedule_id BIGINT UNSIGNED NOT NULL,
    sync_enabled TINYINT(1) NOT NULL DEFAULT 1,
    custom_title VARCHAR(150) NULL,
    custom_date DATE NULL,
    custom_start_time TIME NULL,
    custom_end_time TIME NULL,
    custom_is_all_day TINYINT(1) NULL,
    custom_note TEXT NULL,
    added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY user_schedules_unique (user_id, schedule_id),
    INDEX user_schedules_schedule (schedule_id),
    INDEX user_schedules_custom_date (user_id, sync_enabled, custom_date),
    CONSTRAINT user_schedules_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT user_schedules_schedule_fk FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
