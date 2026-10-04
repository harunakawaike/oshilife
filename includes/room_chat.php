<?php
/** room_chat.php の役割：同じルーム詳細内にトーク履歴と入力欄を配置する。本文はJSのtextContentで安全に表示する。 */
?>
<section id="room-talk" class="room-talk room-chat" data-room-id="<?= (int)$roomId ?>" aria-labelledby="room-talk-title">
<h2 id="room-talk-title">トーク</h2>
<p class="caption">個人情報やチケット・FC等の機密情報（会員番号・住所・電話番号・カード情報・パスワード）は送信しないでください。</p>
<div id="room-chat-history" class="room-chat-history" role="region" aria-label="トーク履歴" tabindex="0"><p id="room-chat-empty">読み込んでいます…</p></div>
<p class="caption">直近100件を表示します。</p>
<button type="button" class="button secondary" id="room-chat-latest" hidden>新しいメッセージを見る ↓</button>
<p id="room-chat-status" role="status" aria-live="polite"></p>
<p id="room-chat-closed" <?= $active?'hidden':'' ?>>このルームは終了しているため、新しいメッセージは送信できません。</p>
<form id="room-chat-form" <?= !$active?'hidden':'' ?>>
<label for="room-chat-input">メッセージ</label>
<textarea id="room-chat-input" name="message" rows="3" placeholder="メッセージを入力…" required></textarea>
<div class="room-chat-send"><span id="room-chat-count" class="caption">0 / 1000文字</span><button class="button primary" type="submit">送信</button></div>
</form>
<noscript>トークの表示・投稿にはJavaScriptを有効にしてください。</noscript>
</section>
