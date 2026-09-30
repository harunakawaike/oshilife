<?php
/** notifications.php の役割：全ログイン画面共通の通知ボタン・一覧・ポップアップの置き場所。 */
?>
<div class="notification-control">
<button id="notification-toggle" type="button" class="notification-toggle" aria-expanded="false" aria-controls="notification-panel" aria-label="通知を開く"><svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9Z"/><path d="M10 21h4"/></svg><span>通知</span><span id="notification-badge" class="notification-badge" hidden></span></button>
<section id="notification-panel" class="notification-panel" aria-labelledby="notification-heading" hidden>
<div class="notification-panel-header"><h2 id="notification-heading">届いたお知らせ</h2><button id="notification-close" type="button" class="notification-close" aria-label="通知一覧を閉じる">×</button></div>
<p id="notification-status" class="caption" role="status"></p>
<div id="notification-list"></div>
<button id="notification-read" type="button" class="button secondary small" hidden>表示中の通知を既読にする</button>
</section>
</div>
