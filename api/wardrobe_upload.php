<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth_middleware.php';
require_once dirname(__DIR__) . '/wardrobe_common.php';
$userID = require_auth();
if (get_method() !== 'POST') json_error('Method not allowed', 405);
$name = trim($_POST['name'] ?? ''); $category = trim($_POST['category'] ?? '');
if ($name === '' || !in_array($category, wardrobe_allowed_categories(), true)) json_error('Valid name and category are required');
$link->begin_transaction();
try {
    $stmt = $link->prepare('INSERT INTO WardrobeItems (Name, Category, ProcessingStatus, CreatedByUserID, UpdatedByUserID) VALUES (?, ?, "ready", ?, ?)');
    $stmt->bind_param('ssii', $name, $category, $userID, $userID); $stmt->execute(); $id = $stmt->insert_id; $stmt->close();
    wardrobe_store_image($link, $id, 'front', $_FILES['front'] ?? []);
    wardrobe_store_image($link, $id, 'back', $_FILES['back'] ?? []);
    $link->commit();
    json_response(['id' => $id], 201);
} catch (Throwable $e) {
    $link->rollback();
    if (!empty($id)) { $dir = wardrobe_upload_dir() . '/' . $id; foreach (glob($dir . '/*') ?: [] as $f) @unlink($f); @rmdir($dir); }
    json_error($e->getMessage(), 422);
}
