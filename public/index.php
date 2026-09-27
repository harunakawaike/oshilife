<?php
/** index.php の役割：ログイン状態に応じてホームかログイン画面へ案内する。 */
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
redirectTo(isset($_SESSION['user_id']) ? 'home.php' : 'login.php');
