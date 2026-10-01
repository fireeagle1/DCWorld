<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth_middleware.php';
require_once dirname(__DIR__) . '/wardrobe_ai.php';
$userId = require_auth();
if (get_method() !== 'POST') json_error('Method not allowed', 405);
$data = get_json_body();
$id = (int)($data['id'] ?? $_GET['id'] ?? 0);
if ($id < 1) json_error('A valid wardrobe item id is required');
try {
    $analysis = wardrobe_ai_analyse($link, $id, $userId);
    json_response(['id' => $id, 'analysis' => $analysis]);
} catch (Throwable $e) {
    wardrobe_ai_record_failure($link, $id, $userId, $e);
    json_error($e->getMessage(), 422);
}
