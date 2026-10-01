-- Step 2：抽選結果と参加状況を分離する。009適用済みDBにバックアップ確認後、一度だけ適用。
-- 既存ID・金額・支出・TODOは変更しない。元の抽選値を保存し、販売区分から参加確定を推測しない。
ALTER TABLE user_event_status
 ADD COLUMN entry_method VARCHAR(30) NOT NULL DEFAULT 'unknown' AFTER event_id,
 ADD COLUMN participation_status VARCHAR(30) NOT NULL DEFAULT 'considering' AFTER lottery_status,
 ADD COLUMN sales_type VARCHAR(30) NOT NULL DEFAULT 'none' AFTER participation_status,
 ADD COLUMN legacy_lottery_status VARCHAR(30) NULL AFTER sales_type;
UPDATE user_event_status SET
 legacy_lottery_status=lottery_status,
 entry_method=CASE WHEN lottery_status IN ('pending','won','lost') THEN 'lottery' ELSE 'unknown' END,
 participation_status=CASE lottery_status WHEN 'won' THEN 'confirmed' WHEN 'lost' THEN 'not_attending' ELSE 'considering' END,
 sales_type=CASE WHEN lottery_status IN ('general_sale','production_release','other') THEN lottery_status ELSE 'none' END,
 lottery_status=CASE WHEN lottery_status IN ('pending','won','lost') THEN lottery_status ELSE 'not_applicable' END,
 updated_at=updated_at;
ALTER TABLE user_event_status
 MODIFY lottery_status VARCHAR(30) NOT NULL DEFAULT 'not_applicable',
 ADD CONSTRAINT entry_method_allowed CHECK (entry_method IN ('lottery','first_come','reservation','no_application','unknown')),
 ADD CONSTRAINT participation_status_allowed CHECK (participation_status IN ('considering','confirmed','not_attending','cancelled')),
 ADD CONSTRAINT sales_type_allowed CHECK (sales_type IN ('fanclub','general_sale','production_release','official_presale','other','none')),
 ADD CONSTRAINT lottery_status_allowed CHECK (lottery_status IN ('not_applicable','pending','won','lost')),
 ADD CONSTRAINT application_status_allowed CHECK (application_status IN ('not_applied','applying','applied','not_required'));
