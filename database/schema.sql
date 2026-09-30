-- Oshilife v2 Phase 4までの新規環境用スキーマ。作成済みDBには再実行しないこと。
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

-- 受信と既読を本人ごとに保存。ページ移動しても同じポップアップを繰り返さない。
CREATE TABLE notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    recipient_user_id BIGINT UNSIGNED NOT NULL,
    schedule_id BIGINT UNSIGNED NOT NULL,
    kind ENUM('helped','thanks','correction') NOT NULL,
    event_key VARCHAR(100) NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    correction_id BIGINT UNSIGNED NULL,
    shown_at DATETIME NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY notifications_event (recipient_user_id,event_key),
    INDEX notifications_inbox (recipient_user_id,read_at,id),
    CONSTRAINT notifications_recipient_fk FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT notifications_actor_fk FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT notifications_schedule_fk FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE CASCADE,
    CONSTRAINT notifications_correction_fk FOREIGN KEY (correction_id) REFERENCES correction_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 既存の感謝・確認待ち提案も通知一覧へ入れる。元データは変更しない。
INSERT INTO notifications (recipient_user_id,schedule_id,kind,event_key,actor_user_id,created_at)
SELECT s.created_by_user_id,r.schedule_id,r.reaction_type,CONCAT('reaction:',r.schedule_id,':',r.user_id,':',r.reaction_type),r.user_id,r.created_at
FROM reactions r JOIN schedules s ON s.id=r.schedule_id WHERE s.visibility='public' AND s.created_by_user_id<>r.user_id;
INSERT INTO notifications (recipient_user_id,schedule_id,kind,event_key,actor_user_id,correction_id,created_at)
SELECT s.created_by_user_id,cr.schedule_id,'correction',CONCAT('correction:',cr.id),cr.requested_by_user_id,cr.id,cr.created_at
FROM correction_requests cr JOIN schedules s ON s.id=cr.schedule_id WHERE cr.status='pending';
