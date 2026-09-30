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
CREATE TABLE live_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 schedule_id BIGINT UNSIGNED NOT NULL, venue_id BIGINT UNSIGNED NOT NULL,
 open_time TIME NULL, status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY live_schedule_unique (schedule_id), INDEX live_venue (venue_id), INDEX live_status (status,schedule_id),
 CONSTRAINT live_schedule_fk FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE RESTRICT,
 CONSTRAINT live_venue_fk FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE user_live_status (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL, live_event_id BIGINT UNSIGNED NOT NULL,
 application_status VARCHAR(30) NOT NULL DEFAULT 'not_applied', lottery_status VARCHAR(30) NOT NULL DEFAULT 'pending',
 trip_type VARCHAR(10) NULL, note TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY user_live_unique (user_id,live_event_id), INDEX user_live_event (live_event_id),
 CONSTRAINT user_live_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT user_live_live_fk FOREIGN KEY (live_event_id) REFERENCES live_events(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE trips (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL, live_event_id BIGINT UNSIGNED NOT NULL,
 trip_name VARCHAR(150) NOT NULL, departure_date DATE NOT NULL, return_date DATE NOT NULL, note TEXT NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY trips_user_live (user_id,live_event_id), UNIQUE KEY trips_owner_identity (id,user_id,live_event_id), INDEX trips_live (live_event_id),
 CONSTRAINT trips_management_fk FOREIGN KEY (user_id,live_event_id) REFERENCES user_live_status(user_id,live_event_id) ON DELETE RESTRICT
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
 user_id BIGINT UNSIGNED NOT NULL, live_event_id BIGINT UNSIGNED NOT NULL, trip_id BIGINT UNSIGNED NULL,
 title VARCHAR(150) NOT NULL, due_date DATE NULL, is_completed TINYINT(1) NOT NULL DEFAULT 0,
 todo_type VARCHAR(10) NOT NULL, template_key VARCHAR(40) NULL,
 deleted_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY todo_template_once (user_id,live_event_id,template_key),
 INDEX todo_owner_live (user_id,live_event_id,deleted_at,is_completed), INDEX todo_live (live_event_id), INDEX todo_trip (trip_id,user_id,live_event_id),
 CONSTRAINT todo_management_fk FOREIGN KEY (user_id,live_event_id) REFERENCES user_live_status(user_id,live_event_id) ON DELETE RESTRICT,
 CONSTRAINT todo_trip_owner_fk FOREIGN KEY (trip_id,user_id,live_event_id) REFERENCES trips(id,user_id,live_event_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
