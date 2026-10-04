-- Oshilife v2: 空の選択済みDB専用 / MySQL 8.0.16以上 / 構造のみ
-- 2026-10-04 / HEAD 58d71f5 / 元DB MariaDB 10.4.28: SHOW CREATE TABLE
-- 実データ・開発DBの採番位置・DB名の指定は含めない。
-- 全テーブルの作成後に外部キーを追加し、循環参照に対応する。
-- 途中で失敗した場合は停止し、部分適用された状態へ再実行しない。
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET SESSION FOREIGN_KEY_CHECKS = 1;

-- 第1段階: 列、主キー、一意制約、インデックス、CHECKを作成。
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `display_name` varchar(50) NOT NULL,
  `email` varchar(254) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `theme_color` varchar(7) NOT NULL DEFAULT '#F8F5F1',
  `accent_color` varchar(7) NOT NULL DEFAULT '#986879',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_settings_user_unique` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `oshis` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `oshi_type` enum('group','solo','actor','other') NOT NULL,
  `emoji` varchar(32) NOT NULL,
  `theme_color` varchar(7) NOT NULL DEFAULT '#986879',
  `created_by_user_id` bigint unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `oshis_name_type_unique` (`name`,`oshi_type`),
  KEY `oshis_creator_fk` (`created_by_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `oshi_id` bigint unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `color_name` varchar(40) NOT NULL,
  `heart_emoji` varchar(8) NOT NULL,
  `hex_color` varchar(7) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `members_oshi_name_unique` (`oshi_id`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_oshis` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `oshi_id` bigint unsigned NOT NULL,
  `registered_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_oshis_user_oshi_unique` (`user_id`,`oshi_id`),
  KEY `user_oshis_oshi_fk` (`oshi_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `schedules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `created_by_user_id` bigint unsigned NOT NULL,
  `oshi_id` bigint unsigned NOT NULL,
  `title` varchar(150) NOT NULL,
  `category` varchar(20) NOT NULL,
  `schedule_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `is_all_day` tinyint(1) NOT NULL DEFAULT 0,
  `visibility` enum('public','private') NOT NULL DEFAULT 'private',
  `status` enum('active','cancelled','deleted') NOT NULL DEFAULT 'active',
  `note` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `schedules_owner_date` (`created_by_user_id`,`schedule_date`),
  KEY `schedules_public_date` (`visibility`,`status`,`schedule_date`),
  KEY `schedules_oshi_date` (`oshi_id`,`schedule_date`,`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `schedule_members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `schedule_id` bigint unsigned NOT NULL,
  `member_id` bigint unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `schedule_members_unique` (`schedule_id`,`member_id`),
  KEY `schedule_members_member` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `schedule_sources` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `schedule_id` bigint unsigned NOT NULL,
  `source_type` varchar(30) NOT NULL,
  `source_url` varchar(2048) DEFAULT NULL,
  `note` varchar(500) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `schedule_sources_schedule` (`schedule_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_schedules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `schedule_id` bigint unsigned NOT NULL,
  `sync_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `custom_title` varchar(150) DEFAULT NULL,
  `custom_date` date DEFAULT NULL,
  `custom_start_time` time DEFAULT NULL,
  `custom_end_time` time DEFAULT NULL,
  `custom_is_all_day` tinyint(1) DEFAULT NULL,
  `custom_note` text NULL,
  `added_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_schedules_unique` (`user_id`,`schedule_id`),
  KEY `user_schedules_schedule` (`schedule_id`),
  KEY `user_schedules_custom_date` (`user_id`,`sync_enabled`,`custom_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `correction_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `schedule_id` bigint unsigned NOT NULL,
  `requested_by_user_id` bigint unsigned NOT NULL,
  `field_name` varchar(30) NOT NULL,
  `old_value` text NULL,
  `new_value` text NULL,
  `reason` varchar(1000) NOT NULL,
  `source_url` varchar(2048) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `corrections_schedule_status` (`schedule_id`,`status`,`created_at`),
  KEY `corrections_requester` (`requested_by_user_id`,`created_at`),
  KEY `corrections_status` (`status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `reactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `schedule_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `reaction_type` enum('helped','thanks') NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `reactions_unique` (`schedule_id`,`user_id`,`reaction_type`),
  KEY `reactions_user` (`user_id`,`created_at`),
  KEY `reactions_type_date` (`reaction_type`,`created_at`),
  KEY `reactions_schedule_date` (`schedule_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `recipient_user_id` bigint unsigned NOT NULL,
  `schedule_id` bigint unsigned NOT NULL,
  `kind` enum('helped','thanks','correction') NOT NULL,
  `event_key` varchar(100) NOT NULL,
  `actor_user_id` bigint unsigned NOT NULL,
  `correction_id` bigint unsigned DEFAULT NULL,
  `shown_at` datetime DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `notifications_event` (`recipient_user_id`,`event_key`),
  KEY `notifications_inbox` (`recipient_user_id`,`read_at`,`id`),
  KEY `notifications_actor_fk` (`actor_user_id`),
  KEY `notifications_schedule_fk` (`schedule_id`),
  KEY `notifications_correction_fk` (`correction_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `venues` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `prefecture` varchar(20) NOT NULL,
  `address` varchar(255) NOT NULL DEFAULT '',
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `venues_identity` (`name`,`prefecture`,`address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `schedule_id` bigint unsigned NOT NULL,
  `event_type` varchar(30) NOT NULL DEFAULT 'live',
  `venue_id` bigint unsigned NOT NULL,
  `open_time` time DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'scheduled',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `event_schedule_unique` (`schedule_id`),
  KEY `event_venue` (`venue_id`),
  KEY `event_status` (`status`,`schedule_id`),
  CONSTRAINT `event_type_allowed` CHECK (`event_type` in ('live','performance','event','sports','exhibition','other'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `user_event_status` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `entry_method` varchar(30) NOT NULL DEFAULT 'unknown',
  `application_status` varchar(30) NOT NULL DEFAULT 'not_applied',
  `lottery_status` varchar(30) NOT NULL DEFAULT 'not_applicable',
  `participation_status` varchar(30) NOT NULL DEFAULT 'considering',
  `sales_type` varchar(30) NOT NULL DEFAULT 'none',
  `legacy_lottery_status` varchar(30) DEFAULT NULL,
  `trip_type` varchar(10) DEFAULT NULL,
  `note` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ticket_amount` decimal(12,2) DEFAULT NULL,
  `ticket_payment_status` varchar(10) NOT NULL DEFAULT 'unpaid',
  `ticket_paid_date` date DEFAULT NULL,
  `ticket_expense_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_event_unique` (`user_id`,`event_id`),
  UNIQUE KEY `ticket_expense_once` (`ticket_expense_id`),
  KEY `user_event_event` (`event_id`),
  CONSTRAINT `entry_method_allowed` CHECK (`entry_method` in ('lottery','first_come','reservation','no_application','unknown')),
  CONSTRAINT `participation_status_allowed` CHECK (`participation_status` in ('considering','confirmed','not_attending','cancelled')),
  CONSTRAINT `sales_type_allowed` CHECK (`sales_type` in ('fanclub','general_sale','production_release','official_presale','other','none')),
  CONSTRAINT `lottery_status_allowed` CHECK (`lottery_status` in ('not_applicable','pending','won','lost')),
  CONSTRAINT `application_status_allowed` CHECK (`application_status` in ('not_applied','applying','applied','not_required'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `trips` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `trip_name` varchar(150) NOT NULL,
  `departure_date` date NOT NULL,
  `return_date` date NOT NULL,
  `note` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `trips_owner_identity` (`id`,`user_id`,`event_id`),
  UNIQUE KEY `trips_user_event` (`user_id`,`event_id`),
  KEY `trips_event` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `transportations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `trip_id` bigint unsigned NOT NULL,
  `transport_type` varchar(30) NOT NULL,
  `transport_type_other` varchar(100) DEFAULT NULL,
  `departure_place` varchar(150) NOT NULL,
  `arrival_place` varchar(150) NOT NULL,
  `departure_at` datetime NOT NULL,
  `arrival_at` datetime NOT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  `reservation_status` varchar(20) NOT NULL,
  `note` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `payment_status` varchar(10) NOT NULL DEFAULT 'unpaid',
  `paid_date` date DEFAULT NULL,
  `expense_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `transportation_expense_once` (`expense_id`),
  KEY `transport_trip` (`trip_id`,`departure_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `accommodations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `trip_id` bigint unsigned NOT NULL,
  `hotel_name` varchar(150) NOT NULL,
  `check_in_at` datetime NOT NULL,
  `check_out_at` datetime NOT NULL,
  `amount` decimal(12,2) DEFAULT NULL,
  `reservation_status` varchar(20) NOT NULL,
  `address` varchar(255) NOT NULL DEFAULT '',
  `url` varchar(2048) DEFAULT NULL,
  `note` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `payment_status` varchar(10) NOT NULL DEFAULT 'unpaid',
  `paid_date` date DEFAULT NULL,
  `expense_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `accommodation_expense_once` (`expense_id`),
  KEY `accommodation_trip` (`trip_id`,`check_in_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `todos` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `trip_id` bigint unsigned DEFAULT NULL,
  `title` varchar(150) NOT NULL,
  `due_date` date DEFAULT NULL,
  `is_completed` tinyint(1) NOT NULL DEFAULT 0,
  `todo_type` varchar(10) NOT NULL,
  `template_key` varchar(40) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `todo_template_once` (`user_id`,`event_id`,`template_key`),
  KEY `todo_trip` (`trip_id`,`user_id`,`event_id`),
  KEY `todo_owner_event` (`user_id`,`event_id`,`deleted_at`,`is_completed`),
  KEY `todo_event` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `savings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `oshi_id` bigint unsigned DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `saving_date` date NOT NULL,
  `note` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `savings_owner_date` (`user_id`,`saving_date`),
  KEY `savings_owner_oshi_date` (`user_id`,`oshi_id`,`saving_date`),
  KEY `savings_oshi` (`oshi_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `expenses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `oshi_id` bigint unsigned DEFAULT NULL,
  `event_id` bigint unsigned DEFAULT NULL,
  `trip_id` bigint unsigned DEFAULT NULL,
  `room_expense_id` bigint unsigned DEFAULT NULL,
  `title` varchar(150) NOT NULL,
  `category` varchar(30) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `expense_date` date NOT NULL,
  `special_effect_eligible` tinyint(1) NOT NULL,
  `source_type` varchar(30) NOT NULL DEFAULT 'manual',
  `source_id` bigint unsigned DEFAULT NULL,
  `note` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `expense_source_once` (`user_id`,`source_type`,`source_id`),
  KEY `expenses_owner_date` (`user_id`,`expense_date`),
  KEY `expenses_owner_oshi_date` (`user_id`,`oshi_id`,`expense_date`),
  KEY `expenses_owner_category_date` (`user_id`,`category`,`expense_date`),
  KEY `expenses_owner_effect_date` (`user_id`,`special_effect_eligible`,`expense_date`),
  KEY `expenses_oshi` (`oshi_id`),
  KEY `expenses_trip` (`trip_id`),
  KEY `expenses_event` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `event_participants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `name` varchar(150) NOT NULL,
  `note` text NOT NULL,
  `self_marker` tinyint unsigned DEFAULT NULL,
  `archived_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `participant_scope` (`id`,`user_id`,`event_id`),
  UNIQUE KEY `participant_self_once` (`user_id`,`event_id`,`self_marker`),
  KEY `participant_active` (`user_id`,`event_id`,`archived_at`,`id`),
  CONSTRAINT `participant_self_allowed` CHECK (`self_marker` is null or `self_marker` = 1),
  CONSTRAINT `participant_self_active` CHECK (`self_marker` is null or `archived_at` is null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `rooms` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `owner_user_id` bigint unsigned NOT NULL,
  `name` varchar(150) NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `room_owner_pair` (`id`,`owner_user_id`),
  KEY `room_owner` (`owner_user_id`),
  CONSTRAINT `room_status_allowed` CHECK (`status` in ('active','closed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `room_members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `room_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `room_owner_user_id` bigint unsigned NOT NULL,
  `role` varchar(10) NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'active',
  `owner_marker` tinyint GENERATED ALWAYS AS (case when `role` = 'owner' then 1 else NULL end) STORED,
  `joined_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `room_member_once` (`room_id`,`user_id`),
  UNIQUE KEY `room_single_owner` (`room_id`,`owner_marker`),
  KEY `room_member_list` (`user_id`,`status`,`room_id`),
  KEY `room_member_owner` (`room_id`,`room_owner_user_id`),
  CONSTRAINT `room_member_role_allowed` CHECK (`role` in ('owner','member')),
  CONSTRAINT `room_member_status_allowed` CHECK (`status` in ('active','left')),
  CONSTRAINT `room_member_owner_matches` CHECK (`role` = 'owner' and `user_id` = `room_owner_user_id` and `status` = 'active' or `role` = 'member' and `user_id` <> `room_owner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `room_invitations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `room_id` bigint unsigned NOT NULL,
  `inviter_user_id` bigint unsigned NOT NULL,
  `invited_user_id` bigint unsigned NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'pending',
  `pending_marker` tinyint GENERATED ALWAYS AS (case when `status` = 'pending' then 1 else NULL end) STORED,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `responded_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `room_invitation_pending_once` (`room_id`,`invited_user_id`,`pending_marker`),
  KEY `room_invitation_inbox` (`invited_user_id`,`status`,`id`),
  KEY `room_invitation_owner` (`room_id`,`inviter_user_id`),
  CONSTRAINT `room_invitation_status_allowed` CHECK (`status` in ('pending','accepted','declined','cancelled')),
  CONSTRAINT `room_invitation_not_self` CHECK (`invited_user_id` <> `inviter_user_id`),
  CONSTRAINT `room_invitation_response` CHECK (`status` = 'pending' and `responded_at` is null or `status` <> 'pending' and `responded_at` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `room_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `room_id` bigint unsigned NOT NULL,
  `event_id` bigint unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `room_event_once` (`room_id`,`event_id`),
  KEY `room_event_event` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `room_invite_links` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `room_id` bigint unsigned NOT NULL,
  `created_by_user_id` bigint unsigned NOT NULL,
  `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expires_at` datetime NOT NULL,
  `max_uses` int unsigned DEFAULT NULL,
  `used_count` int unsigned NOT NULL DEFAULT 0,
  `status` varchar(10) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `room_invite_token` (`token_hash`),
  KEY `room_invite_owner` (`room_id`,`created_by_user_id`),
  KEY `room_invite_listing` (`room_id`,`status`,`expires_at`),
  CONSTRAINT `room_invite_link_status` CHECK (`status` in ('active','revoked','expired')),
  CONSTRAINT `room_invite_link_limit` CHECK (`max_uses` is null or `max_uses` > 0 and `used_count` <= `max_uses`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `room_expenses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `room_id` bigint unsigned NOT NULL,
  `created_by_user_id` bigint unsigned NOT NULL,
  `created_by_name_snapshot` varchar(50) NOT NULL,
  `title` varchar(150) NOT NULL,
  `category` varchar(20) NOT NULL,
  `total_amount` int unsigned NOT NULL,
  `paid_by_user_id` bigint unsigned NOT NULL,
  `paid_by_name_snapshot` varchar(50) NOT NULL,
  `expense_date` date NOT NULL,
  `note` text NULL,
  `status` varchar(10) NOT NULL DEFAULT 'active',
  `version` int unsigned NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `room_expense_room_pair` (`id`,`room_id`),
  KEY `room_expense_list` (`room_id`,`status`,`expense_date`,`id`),
  KEY `room_expense_creator` (`room_id`,`created_by_user_id`),
  KEY `room_expense_payer` (`room_id`,`paid_by_user_id`),
  CONSTRAINT `room_expense_amount` CHECK (`total_amount` between 1 and 999999999),
  CONSTRAINT `room_expense_category` CHECK (`category` in ('ticket','transportation','accommodation','food','goods','sightseeing','other')),
  CONSTRAINT `room_expense_status` CHECK (`status` in ('active','cancelled')),
  CONSTRAINT `room_expense_version` CHECK (`version` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `room_expense_members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `room_expense_id` bigint unsigned NOT NULL,
  `room_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `display_name_snapshot` varchar(50) NOT NULL,
  `share_amount` int unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `room_expense_member_once` (`room_expense_id`,`user_id`),
  KEY `room_expense_share_room` (`room_expense_id`,`room_id`),
  KEY `room_expense_share_member` (`room_id`,`user_id`),
  CONSTRAINT `room_expense_share_amount` CHECK (`share_amount` between 0 and 999999999)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `room_settlements` (
  `room_id` bigint unsigned NOT NULL,
  `settlement_status` varchar(10) NOT NULL DEFAULT 'unsettled',
  `settled_at` datetime DEFAULT NULL,
  `settled_by_user_id` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`room_id`),
  KEY `room_settlement_actor` (`room_id`,`settled_by_user_id`),
  CONSTRAINT `room_settlement_state` CHECK (`settlement_status` = 'unsettled' and `settled_at` is null and `settled_by_user_id` is null or `settlement_status` = 'settled' and `settled_at` is not null and `settled_by_user_id` is not null)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `room_messages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `room_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `display_name_snapshot` varchar(50) NOT NULL,
  `message` text NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `room_message_timeline` (`room_id`,`id`),
  KEY `room_message_author` (`room_id`,`user_id`),
  KEY `room_message_rate` (`user_id`,`created_at`),
  CONSTRAINT `room_message_status` CHECK (`status` in ('active','deleted')),
  CONSTRAINT `room_message_length` CHECK (char_length(`message`) between 1 and 1000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 第2段階: 親テーブルがすべて存在する状態で外部キーを追加。
ALTER TABLE `user_settings` ADD CONSTRAINT `user_settings_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `oshis` ADD CONSTRAINT `oshis_creator_fk` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `members` ADD CONSTRAINT `members_oshi_fk` FOREIGN KEY (`oshi_id`) REFERENCES `oshis` (`id`);
ALTER TABLE `user_oshis` ADD CONSTRAINT `user_oshis_oshi_fk` FOREIGN KEY (`oshi_id`) REFERENCES `oshis` (`id`);
ALTER TABLE `user_oshis` ADD CONSTRAINT `user_oshis_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `schedules` ADD CONSTRAINT `schedules_oshi_fk` FOREIGN KEY (`oshi_id`) REFERENCES `oshis` (`id`);
ALTER TABLE `schedules` ADD CONSTRAINT `schedules_owner_fk` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`);
ALTER TABLE `schedule_members` ADD CONSTRAINT `schedule_members_member_fk` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`);
ALTER TABLE `schedule_members` ADD CONSTRAINT `schedule_members_schedule_fk` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`id`) ON DELETE CASCADE;
ALTER TABLE `schedule_sources` ADD CONSTRAINT `schedule_sources_schedule_fk` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`id`) ON DELETE CASCADE;
ALTER TABLE `user_schedules` ADD CONSTRAINT `user_schedules_schedule_fk` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`id`);
ALTER TABLE `user_schedules` ADD CONSTRAINT `user_schedules_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `correction_requests` ADD CONSTRAINT `corrections_schedule_fk` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`id`);
ALTER TABLE `correction_requests` ADD CONSTRAINT `corrections_user_fk` FOREIGN KEY (`requested_by_user_id`) REFERENCES `users` (`id`);
ALTER TABLE `reactions` ADD CONSTRAINT `reactions_schedule_fk` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`id`);
ALTER TABLE `reactions` ADD CONSTRAINT `reactions_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `notifications` ADD CONSTRAINT `notifications_actor_fk` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `notifications` ADD CONSTRAINT `notifications_correction_fk` FOREIGN KEY (`correction_id`) REFERENCES `correction_requests` (`id`) ON DELETE CASCADE;
ALTER TABLE `notifications` ADD CONSTRAINT `notifications_recipient_fk` FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `notifications` ADD CONSTRAINT `notifications_schedule_fk` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`id`) ON DELETE CASCADE;
ALTER TABLE `events` ADD CONSTRAINT `event_schedule_fk` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`id`);
ALTER TABLE `events` ADD CONSTRAINT `event_venue_fk` FOREIGN KEY (`venue_id`) REFERENCES `venues` (`id`);
ALTER TABLE `user_event_status` ADD CONSTRAINT `ticket_expense_fk` FOREIGN KEY (`ticket_expense_id`) REFERENCES `expenses` (`id`) ON DELETE SET NULL;
ALTER TABLE `user_event_status` ADD CONSTRAINT `user_event_event_fk` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`);
ALTER TABLE `user_event_status` ADD CONSTRAINT `user_event_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `trips` ADD CONSTRAINT `trips_management_fk` FOREIGN KEY (`user_id`, `event_id`) REFERENCES `user_event_status` (`user_id`, `event_id`);
ALTER TABLE `transportations` ADD CONSTRAINT `transport_trip_fk` FOREIGN KEY (`trip_id`) REFERENCES `trips` (`id`) ON DELETE CASCADE;
ALTER TABLE `transportations` ADD CONSTRAINT `transportation_expense_fk` FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE SET NULL;
ALTER TABLE `accommodations` ADD CONSTRAINT `accommodation_expense_fk` FOREIGN KEY (`expense_id`) REFERENCES `expenses` (`id`) ON DELETE SET NULL;
ALTER TABLE `accommodations` ADD CONSTRAINT `accommodation_trip_fk` FOREIGN KEY (`trip_id`) REFERENCES `trips` (`id`) ON DELETE CASCADE;
ALTER TABLE `todos` ADD CONSTRAINT `todo_management_fk` FOREIGN KEY (`user_id`, `event_id`) REFERENCES `user_event_status` (`user_id`, `event_id`);
ALTER TABLE `todos` ADD CONSTRAINT `todo_trip_owner_fk` FOREIGN KEY (`trip_id`, `user_id`, `event_id`) REFERENCES `trips` (`id`, `user_id`, `event_id`);
ALTER TABLE `savings` ADD CONSTRAINT `savings_oshi_fk` FOREIGN KEY (`oshi_id`) REFERENCES `oshis` (`id`);
ALTER TABLE `savings` ADD CONSTRAINT `savings_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `expenses` ADD CONSTRAINT `expenses_event_fk` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`);
ALTER TABLE `expenses` ADD CONSTRAINT `expenses_oshi_fk` FOREIGN KEY (`oshi_id`) REFERENCES `oshis` (`id`);
ALTER TABLE `expenses` ADD CONSTRAINT `expenses_trip_fk` FOREIGN KEY (`trip_id`) REFERENCES `trips` (`id`);
ALTER TABLE `expenses` ADD CONSTRAINT `expenses_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `event_participants` ADD CONSTRAINT `participant_management_fk` FOREIGN KEY (`user_id`, `event_id`) REFERENCES `user_event_status` (`user_id`, `event_id`);
ALTER TABLE `rooms` ADD CONSTRAINT `room_owner_fk` FOREIGN KEY (`owner_user_id`) REFERENCES `users` (`id`);
ALTER TABLE `room_members` ADD CONSTRAINT `room_member_room_fk` FOREIGN KEY (`room_id`, `room_owner_user_id`) REFERENCES `rooms` (`id`, `owner_user_id`);
ALTER TABLE `room_members` ADD CONSTRAINT `room_member_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);
ALTER TABLE `room_invitations` ADD CONSTRAINT `room_invitation_owner_fk` FOREIGN KEY (`room_id`, `inviter_user_id`) REFERENCES `rooms` (`id`, `owner_user_id`);
ALTER TABLE `room_invitations` ADD CONSTRAINT `room_invitation_user_fk` FOREIGN KEY (`invited_user_id`) REFERENCES `users` (`id`);
ALTER TABLE `room_events` ADD CONSTRAINT `room_event_event_fk` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`);
ALTER TABLE `room_events` ADD CONSTRAINT `room_event_room_fk` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`);
ALTER TABLE `room_invite_links` ADD CONSTRAINT `room_invite_link_owner_fk` FOREIGN KEY (`room_id`, `created_by_user_id`) REFERENCES `rooms` (`id`, `owner_user_id`);
ALTER TABLE `room_expenses` ADD CONSTRAINT `room_expense_creator_fk` FOREIGN KEY (`room_id`, `created_by_user_id`) REFERENCES `room_members` (`room_id`, `user_id`);
ALTER TABLE `room_expenses` ADD CONSTRAINT `room_expense_payer_fk` FOREIGN KEY (`room_id`, `paid_by_user_id`) REFERENCES `room_members` (`room_id`, `user_id`);
ALTER TABLE `room_expense_members` ADD CONSTRAINT `room_expense_share_expense_fk` FOREIGN KEY (`room_expense_id`, `room_id`) REFERENCES `room_expenses` (`id`, `room_id`);
ALTER TABLE `room_expense_members` ADD CONSTRAINT `room_expense_share_member_fk` FOREIGN KEY (`room_id`, `user_id`) REFERENCES `room_members` (`room_id`, `user_id`);
ALTER TABLE `room_settlements` ADD CONSTRAINT `room_settlement_actor_fk` FOREIGN KEY (`room_id`, `settled_by_user_id`) REFERENCES `room_members` (`room_id`, `user_id`);
ALTER TABLE `room_settlements` ADD CONSTRAINT `room_settlement_room_fk` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`);
ALTER TABLE `room_messages` ADD CONSTRAINT `room_message_member_fk` FOREIGN KEY (`room_id`, `user_id`) REFERENCES `room_members` (`room_id`, `user_id`);

