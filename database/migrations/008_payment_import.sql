-- 支払いと会計反映を分離する。既存金額・支出・集計の列は変更しない。
ALTER TABLE user_live_status
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
UPDATE user_live_status u JOIN todos td ON td.user_id=u.user_id AND td.live_event_id=u.live_event_id
 SET u.ticket_payment_status='paid',u.updated_at=u.updated_at
 WHERE td.template_key='payment' AND td.is_completed=1 AND td.deleted_at IS NULL;
