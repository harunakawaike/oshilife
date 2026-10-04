-- ルーム単位の現在の精算状態だけを保存する。金額・送金案は保存しない。
-- 既存行は変更しない。状態行がないルームはアプリで未精算として扱う。
CREATE TABLE room_settlements (
    room_id BIGINT UNSIGNED NOT NULL,
    settlement_status VARCHAR(10) NOT NULL DEFAULT 'unsettled',
    settled_at DATETIME NULL,
    settled_by_user_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (room_id),
    KEY room_settlement_actor (room_id,settled_by_user_id),
    CONSTRAINT room_settlement_room_fk FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE RESTRICT,
    CONSTRAINT room_settlement_actor_fk FOREIGN KEY (room_id,settled_by_user_id) REFERENCES room_members(room_id,user_id) ON DELETE RESTRICT,
    CONSTRAINT room_settlement_state CHECK (
        (settlement_status='unsettled' AND settled_at IS NULL AND settled_by_user_id IS NULL)
        OR (settlement_status='settled' AND settled_at IS NOT NULL AND settled_by_user_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
