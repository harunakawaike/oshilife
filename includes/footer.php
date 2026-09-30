<?php
/** footer.php の役割：共通の閉じタグと、ページを閉じる（ナビはheader.phpで一度だけ出力）。 */
?>
</main>
</div>
<?php if (!($isAuthPage ?? false)): ?>
<div id="notification-toasts" class="notification-toasts" aria-live="polite" aria-relevant="additions"></div>
<?php endif; ?>
</body>
</html>
