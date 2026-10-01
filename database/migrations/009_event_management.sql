-- Step 1：既存ID・値を保持してイベント名称へ移行する。006〜008適用後に一度だけ実行。
-- バックアップ復元確認と書き込み停止が前提。DDLは一括ROLLBACKできない。
-- DROP TABLE・データ再作成・FOREIGN_KEY_CHECKSの無効化は行わない。
ALTER TABLE todos DROP FOREIGN KEY todo_management_fk, DROP FOREIGN KEY todo_trip_owner_fk;
ALTER TABLE trips DROP FOREIGN KEY trips_management_fk;
ALTER TABLE expenses DROP FOREIGN KEY expenses_live_fk;
ALTER TABLE user_live_status DROP FOREIGN KEY user_live_live_fk, DROP FOREIGN KEY user_live_user_fk;
ALTER TABLE live_events DROP FOREIGN KEY live_schedule_fk, DROP FOREIGN KEY live_venue_fk;
RENAME TABLE live_events TO events, user_live_status TO user_event_status;
ALTER TABLE events
 ADD COLUMN event_type VARCHAR(30) NOT NULL DEFAULT 'live' AFTER schedule_id,
 ADD CONSTRAINT event_type_allowed CHECK (event_type IN ('live','performance','event','sports','exhibition','other')),
 DROP INDEX live_schedule_unique, ADD UNIQUE KEY event_schedule_unique (schedule_id),
 DROP INDEX live_venue, ADD INDEX event_venue (venue_id),
 DROP INDEX live_status, ADD INDEX event_status (status,schedule_id),
 ADD CONSTRAINT event_schedule_fk FOREIGN KEY (schedule_id) REFERENCES schedules(id) ON DELETE RESTRICT,
 ADD CONSTRAINT event_venue_fk FOREIGN KEY (venue_id) REFERENCES venues(id) ON DELETE RESTRICT;
-- DEFAULTによって既存行にもliveを設定。updated_atや既存IDは変更しない。
ALTER TABLE user_event_status
 CHANGE COLUMN live_event_id event_id BIGINT UNSIGNED NOT NULL,
 DROP INDEX user_live_unique, ADD UNIQUE KEY user_event_unique (user_id,event_id),
 DROP INDEX user_live_event, ADD INDEX user_event_event (event_id),
 ADD CONSTRAINT user_event_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 ADD CONSTRAINT user_event_event_fk FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT;
ALTER TABLE trips
 CHANGE COLUMN live_event_id event_id BIGINT UNSIGNED NOT NULL,
 DROP INDEX trips_user_live, ADD UNIQUE KEY trips_user_event (user_id,event_id),
 DROP INDEX trips_live, ADD INDEX trips_event (event_id),
 ADD CONSTRAINT trips_management_fk FOREIGN KEY (user_id,event_id) REFERENCES user_event_status(user_id,event_id) ON DELETE RESTRICT;
ALTER TABLE todos
 CHANGE COLUMN live_event_id event_id BIGINT UNSIGNED NOT NULL,
 DROP INDEX todo_owner_live, ADD INDEX todo_owner_event (user_id,event_id,deleted_at,is_completed),
 DROP INDEX todo_live, ADD INDEX todo_event (event_id),
 ADD CONSTRAINT todo_management_fk FOREIGN KEY (user_id,event_id) REFERENCES user_event_status(user_id,event_id) ON DELETE RESTRICT,
 ADD CONSTRAINT todo_trip_owner_fk FOREIGN KEY (trip_id,user_id,event_id) REFERENCES trips(id,user_id,event_id) ON DELETE RESTRICT;
ALTER TABLE expenses
 CHANGE COLUMN live_event_id event_id BIGINT UNSIGNED NULL,
 DROP INDEX expenses_live, ADD INDEX expenses_event (event_id),
 ADD CONSTRAINT expenses_event_fk FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT;
