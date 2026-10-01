<?php
declare(strict_types=1);
session_start();
require 'config.php';
require 'auth.php';
require_once 'wardrobe_ai.php';
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id < 1) { header('Location: wardrobe.php'); exit; }
$userId = (int)($_SESSION['userID'] ?? 0);
try {
    wardrobe_ai_analyse($link, $id, $userId);
    $_SESSION['wardrobe_notice'] = 'Analysis complete. Review the suggested metadata before confirming it.';
} catch (Throwable $e) {
    wardrobe_ai_record_failure($link, $id, $userId, $e);
    $_SESSION['wardrobe_error'] = $e->getMessage();
}
header('Location: wardrobe_item_edit.php?id=' . $id);
exit;
