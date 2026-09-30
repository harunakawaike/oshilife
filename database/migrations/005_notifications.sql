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
