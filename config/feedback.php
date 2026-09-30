<?php
/** feedback.php の役割：修正できる単一項目、提案状態、感謝の表示ラベルを共通化する。 */
declare(strict_types=1);
const CORRECTION_FIELDS = [
    'title' => 'タイトル', 'schedule_date' => '日付', 'start_time' => '開始時間',
    'end_time' => '終了時間', 'category' => 'カテゴリ', 'note' => 'メモ',
];
const CORRECTION_STATUSES = ['pending' => '確認待ち', 'approved' => '承認済み', 'rejected' => '却下'];
const REACTION_LABELS = ['helped' => '🙌 助かった！', 'thanks' => '♡ ありがとう！'];
