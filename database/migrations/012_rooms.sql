-- 新Step 3-2：連番ルーム・招待・参加。旧012_shared_expenses.sqlは取り消し済み。
-- バックアップと復元確認後に一度だけ実行。既存22テーブルの行・採番・定義を変更しない。
CREATE TABLE rooms (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY room_owner_pair (id, owner_user_id),
    KEY room_owner (owner_user_id),
    CONSTRAINT room_owner_fk FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT room_status_allowed CHECK (status IN ('active','closed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE room_members (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    room_owner_user_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(10) NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'active',
    -- 生成列により、ownerだけをルーム内で一意にする。memberのNULLは複数許可。
    owner_marker TINYINT GENERATED ALWAYS AS (CASE WHEN role='owner' THEN 1 ELSE NULL END) STORED,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY room_member_once (room_id,user_id),
    UNIQUE KEY room_single_owner (room_id,owner_marker),
    KEY room_member_list (user_id,status,room_id),
    KEY room_member_owner (room_id,room_owner_user_id),
    CONSTRAINT room_member_room_fk FOREIGN KEY (room_id,room_owner_user_id) REFERENCES rooms(id,owner_user_id) ON DELETE RESTRICT,
    CONSTRAINT room_member_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT room_member_role_allowed CHECK (role IN ('owner','member')),
    CONSTRAINT room_member_status_allowed CHECK (status IN ('active','left')),
    CONSTRAINT room_member_owner_matches CHECK (
        (role='owner' AND user_id=room_owner_user_id AND status='active') OR
        (role='member' AND user_id<>room_owner_user_id)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE room_invitations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id BIGINT UNSIGNED NOT NULL,
    inviter_user_id BIGINT UNSIGNED NOT NULL,
    invited_user_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'pending',
    pending_marker TINYINT GENERATED ALWAYS AS (CASE WHEN status='pending' THEN 1 ELSE NULL END) STORED,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY room_invitation_pending_once (room_id,invited_user_id,pending_marker),
    KEY room_invitation_inbox (invited_user_id,status,id),
    KEY room_invitation_owner (room_id,inviter_user_id),
    CONSTRAINT room_invitation_owner_fk FOREIGN KEY (room_id,inviter_user_id) REFERENCES rooms(id,owner_user_id) ON DELETE RESTRICT,
    CONSTRAINT room_invitation_user_fk FOREIGN KEY (invited_user_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT room_invitation_status_allowed CHECK (status IN ('pending','accepted','declined','cancelled')),
    CONSTRAINT room_invitation_not_self CHECK (invited_user_id<>inviter_user_id),
    CONSTRAINT room_invitation_response CHECK ((status='pending' AND responded_at IS NULL) OR (status<>'pending' AND responded_at IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 中間テーブル：1ルームに複数イベント、1イベントに複数ルームを紐付けられる。
CREATE TABLE room_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id BIGINT UNSIGNED NOT NULL,
    event_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY room_event_once (room_id,event_id),
    KEY room_event_event (event_id),
    CONSTRAINT room_event_room_fk FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE RESTRICT,
    CONSTRAINT room_event_event_fk FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
