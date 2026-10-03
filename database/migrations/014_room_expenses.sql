-- Step 3-3：ルーム内共同支出。既存27テーブル・個人expenses・採番を変更しない。
-- バックアップと別DBでの復元検証後、一度だけ適用する。
CREATE TABLE room_expenses (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id BIGINT UNSIGNED NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_by_name_snapshot VARCHAR(50) NOT NULL,
    title VARCHAR(150) NOT NULL,
    category VARCHAR(20) NOT NULL,
    total_amount INT UNSIGNED NOT NULL,
    paid_by_user_id BIGINT UNSIGNED NOT NULL,
    paid_by_name_snapshot VARCHAR(50) NOT NULL,
    expense_date DATE NOT NULL,
    note TEXT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'active',
    -- 更新番号で他の人の保存後に古いフォームを上書きする事故を防ぐ。
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY room_expense_room_pair (id,room_id),
    KEY room_expense_list (room_id,status,expense_date,id),
    KEY room_expense_creator (room_id,created_by_user_id),
    KEY room_expense_payer (room_id,paid_by_user_id),
    CONSTRAINT room_expense_creator_fk FOREIGN KEY (room_id,created_by_user_id) REFERENCES room_members(room_id,user_id) ON DELETE RESTRICT,
    CONSTRAINT room_expense_payer_fk FOREIGN KEY (room_id,paid_by_user_id) REFERENCES room_members(room_id,user_id) ON DELETE RESTRICT,
    CONSTRAINT room_expense_amount CHECK (total_amount BETWEEN 1 AND 999999999),
    CONSTRAINT room_expense_category CHECK (category IN ('ticket','transportation','accommodation','food','goods','sightseeing','other')),
    CONSTRAINT room_expense_status CHECK (status IN ('active','cancelled')),
    CONSTRAINT room_expense_version CHECK (version>0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE room_expense_members (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    room_expense_id BIGINT UNSIGNED NOT NULL,
    -- room_idを両方の複合外部キーで検証し、別ルームのメンバー混入をDBでも防ぐ。
    room_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    display_name_snapshot VARCHAR(50) NOT NULL,
    share_amount INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY room_expense_member_once (room_expense_id,user_id),
    KEY room_expense_share_room (room_expense_id,room_id),
    KEY room_expense_share_member (room_id,user_id),
    CONSTRAINT room_expense_share_expense_fk FOREIGN KEY (room_expense_id,room_id) REFERENCES room_expenses(id,room_id) ON DELETE RESTRICT,
    CONSTRAINT room_expense_share_member_fk FOREIGN KEY (room_id,user_id) REFERENCES room_members(room_id,user_id) ON DELETE RESTRICT,
    CONSTRAINT room_expense_share_amount CHECK (share_amount BETWEEN 0 AND 999999999)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
