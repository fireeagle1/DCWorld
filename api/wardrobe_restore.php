<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php'; require_once __DIR__ . '/helpers.php'; require_once __DIR__ . '/auth_middleware.php';
$userID = require_auth(); if (get_method() !== 'POST') json_error('Method not allowed', 405);
$data = get_json_body(); $id = (int)($data['id'] ?? 0); if ($id < 1) json_error('Item id required');
$stmt = $link->prepare('UPDATE WardrobeItems SET DeletedAt = NULL, UpdatedByUserID = ? WHERE WardrobeItemID = ? AND DeletedAt IS NOT NULL');
$stmt->bind_param('ii', $userID, $id); $stmt->execute();
if ($stmt->affected_rows === 0) json_error('Deleted item not found', 404);
json_response(['restored' => true]);
