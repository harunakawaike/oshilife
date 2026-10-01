-- Oshilife v2 イベント名称移行Step 1までの新規環境用スキーマ。作成済みDBには再実行しないこと。
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
-- Phase 5：共有予定を唯一の日時・タイトルの保存先にして、7テーブルを追加する。
CREATE TABLE venues (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(150) NOT NULL, prefecture VARCHAR(20) NOT NULL, address VARCHAR(255) NOT NULL DEFAULT '',
 latitude DECIMAL(10,7) NULL, longitude DECIMAL(10,7) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY venues_identity (name,prefecture,address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- タイトル・推し・公演日・開始終了・作成者・メモはschedule_id先のschedulesへ一元化。
CREATE TABLE events (
 event_type VARCHAR(30) NOT NULL DEFAULT 'live',
 CONSTRAINT event_type_allowed CHECK (event_type IN ('live','performance','event','sports','exhibition','other')),
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 schedule_id BIGINT UNSIGNED NOT NULL, venue_id BIGINT UNSIGNED NOT NULL,
 open_time TIME NULL, status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY event_schedule_unique (schedule_id), INDEX event_venue (venue_id), INDEX event_status (status,schedule_id),
 CONSTRAINT event_schedule_fk FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE RESTRICT,
 CONSTRAINT event_venue_fk FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE user_event_status (
 entry_method VARCHAR(30) NOT NULL DEFAULT 'unknown',
 participation_status VARCHAR(30) NOT NULL DEFAULT 'considering',
 sales_type VARCHAR(30) NOT NULL DEFAULT 'none',
 legacy_lottery_status VARCHAR(30) NULL,
 CONSTRAINT entry_method_allowed CHECK (entry_method IN ('lottery','first_come','reservation','no_application','unknown')),
 CONSTRAINT participation_status_allowed CHECK (participation_status IN ('considering','confirmed','not_attending','cancelled')),
 CONSTRAINT sales_type_allowed CHECK (sales_type IN ('fanclub','general_sale','production_release','official_presale','other','none')),
 CONSTRAINT lottery_status_allowed CHECK (lottery_status IN ('not_applicable','pending','won','lost')),
 CONSTRAINT application_status_allowed CHECK (application_status IN ('not_applied','applying','applied','not_required')),
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL, event_id BIGINT UNSIGNED NOT NULL,
 application_status VARCHAR(30) NOT NULL DEFAULT 'not_applied', lottery_status VARCHAR(30) NOT NULL DEFAULT 'not_applicable',
 trip_type VARCHAR(10) NULL, note TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY user_event_unique (user_id,event_id), INDEX user_event_event (event_id),
 CONSTRAINT user_event_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT user_event_event_fk FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE trips (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL, event_id BIGINT UNSIGNED NOT NULL,
 trip_name VARCHAR(150) NOT NULL, departure_date DATE NOT NULL, return_date DATE NOT NULL, note TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY trips_user_event (user_id,event_id), UNIQUE KEY trips_owner_identity (id,user_id,event_id), INDEX trips_event (event_id),
 CONSTRAINT trips_management_fk FOREIGN KEY (user_id,event_id) REFERENCES user_event_status(user_id,event_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE transportations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, trip_id BIGINT UNSIGNED NOT NULL,
 transport_type VARCHAR(30) NOT NULL, transport_type_other VARCHAR(100) NULL,
 departure_place VARCHAR(150) NOT NULL, arrival_place VARCHAR(150) NOT NULL,
 departure_at DATETIME NOT NULL, arrival_at DATETIME NOT NULL,
 amount DECIMAL(12,2) NULL, reservation_status VARCHAR(20) NOT NULL, note TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX transport_trip (trip_id,departure_at),
 CONSTRAINT transport_trip_fk FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE accommodations (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, trip_id BIGINT UNSIGNED NOT NULL,
 hotel_name VARCHAR(150) NOT NULL, check_in_at DATETIME NOT NULL, check_out_at DATETIME NOT NULL,
 amount DECIMAL(12,2) NULL, reservation_status VARCHAR(20) NOT NULL,
 address VARCHAR(255) NOT NULL DEFAULT '', url VARCHAR(2048) NULL, note TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX accommodation_trip (trip_id,check_in_at),
 CONSTRAINT accommodation_trip_fk FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE todos (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL, event_id BIGINT UNSIGNED NOT NULL, trip_id BIGINT UNSIGNED NULL,
 title VARCHAR(150) NOT NULL, due_date DATE NULL, is_completed TINYINT(1) NOT NULL DEFAULT 0,
 todo_type VARCHAR(10) NOT NULL, template_key VARCHAR(40) NULL,
 deleted_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY todo_template_once (user_id,event_id,template_key),
 INDEX todo_owner_event (user_id,event_id,deleted_at,is_completed), INDEX todo_event (event_id), INDEX todo_trip (trip_id,user_id,event_id),
 CONSTRAINT todo_management_fk FOREIGN KEY (user_id,event_id) REFERENCES user_event_status(user_id,event_id) ON DELETE RESTRICT,
 CONSTRAINT todo_trip_owner_fk FOREIGN KEY (trip_id,user_id,event_id) REFERENCES trips(id,user_id,event_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
    event_id BIGINT UNSIGNED NULL,
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
    INDEX expenses_event (event_id),
    INDEX expenses_trip (trip_id),
    CONSTRAINT expenses_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT expenses_oshi_fk FOREIGN KEY (oshi_id) REFERENCES oshis(id) ON DELETE RESTRICT,
    CONSTRAINT expenses_event_fk FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT,
    CONSTRAINT expenses_trip_fk FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- source_idは予約削除後も記録を保つため、交通・宿泊への外部キーにはしない。
-- 反映時は必ずPHPで元予約→遠征→本人を検証する。room系テーブルはまだ作らない。
-- 支払いと会計反映を分離する。既存金額・支出・集計の列は変更しない。
ALTER TABLE user_event_status
 ADD COLUMN ticket_amount DECIMAL(12,2) NULL,
 ADD COLUMN ticket_payment_status VARCHAR(10) NOT NULL DEFAULT 'unpaid',
 ADD COLUMN ticket_paid_date DATE NULL,
 ADD COLUMN ticket_expense_id BIGINT UNSIGNED NULL,
 ADD UNIQUE KEY ticket_expense_once (ticket_expense_id),
 ADD CONSTRAINT ticket_expense_fk FOREIGN KEY (ticket_expense_id) REFERENCES expenses(id) ON DELETE SET NULL;
ALTER TABLE transportations
 ADD COLUMN payment_status VARCHAR(10) NOT NULL DEFAULT 'unpaid',
 ADD COLUMN paid_date DATE NULL,
 ADD COLUMN expense_id BIGINT UNSIGNED NULL,
 ADD UNIQUE KEY transportation_expense_once (expense_id),
 ADD CONSTRAINT transportation_expense_fk FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE SET NULL;
ALTER TABLE accommodations
 ADD COLUMN payment_status VARCHAR(10) NOT NULL DEFAULT 'unpaid',
 ADD COLUMN paid_date DATE NULL,
 ADD COLUMN expense_id BIGINT UNSIGNED NULL,
 ADD UNIQUE KEY accommodation_expense_once (expense_id),
 ADD CONSTRAINT accommodation_expense_fk FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE SET NULL;

-- Phase 6ですでに反映した本人の支出だけを紐付ける。支払済みとは推測しない。
UPDATE transportations item JOIN trips t ON t.id=item.trip_id
 JOIN expenses e ON e.user_id=t.user_id AND e.source_type='transportation' AND e.source_id=item.id
 SET item.expense_id=e.id,item.updated_at=item.updated_at;
UPDATE accommodations item JOIN trips t ON t.id=item.trip_id
 JOIN expenses e ON e.user_id=t.user_id AND e.source_type='accommodation' AND e.source_id=item.id
 SET item.expense_id=e.id,item.updated_at=item.updated_at;
-- 表示名ではなく固定キーで入金TODOを識別する。過去の正確な支払日は不明なのでNULLのまま。
UPDATE user_event_status u JOIN todos td ON td.user_id=u.user_id AND td.event_id=u.event_id
 SET u.ticket_payment_status='paid',u.updated_at=u.updated_at
 WHERE td.template_key='payment' AND td.is_completed=1 AND td.deleted_at IS NULL;
