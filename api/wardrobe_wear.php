<?php
declare(strict_types=1);

/**
 * Record a wear event (outfit worn today).
 *
 * POST /api/wardrobe_wear.php
 *
 * Request:
 * {
 *     "primaryItemID": 123,
 *     "linkedItemIDs": [45, 67, 82],
 *     "wornDate": "2026-07-21"
 * }
 *
 * The authenticated user is recorded as the wearer (from JWT).
 * linkedItemIDs is optional — an empty array records a single-item wear event.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth_middleware.php';

$userID = require_auth();

if (get_method() !== 'POST') {
    json_error('Method not allowed', 405);
}

$data = get_json_body();

// Validate required fields
$primaryItemID = isset($data['primaryItemID']) ? (int)$data['primaryItemID'] : 0;
$linkedItemIDs = isset($data['linkedItemIDs']) && is_array($data['linkedItemIDs'])
    ? array_map('intval', $data['linkedItemIDs'])
    : [];
$wornDate = trim((string)($data['wornDate'] ?? ''));

if ($primaryItemID < 1) {
    json_error('primaryItemID is required');
}

// Default to today if no date provided
if ($wornDate === '' || $wornDate === 'today') {
    $wornDate = date('Y-m-d');
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $wornDate)) {
    json_error('wornDate must be YYYY-MM-DD format');
}

// Remove duplicates and prevent primary appearing in linked
$linkedItemIDs = array_values(array_unique(array_filter($linkedItemIDs, static fn($id) => $id > 0 && $id !== $primaryItemID)));

// Validate primary item exists
$stmt = $link->prepare('SELECT WardrobeItemID FROM WardrobeItems WHERE WardrobeItemID = ? AND DeletedAt IS NULL');
$stmt->bind_param('i', $primaryItemID);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows === 0) {
    $stmt->close();
    json_error('Primary item not found', 404);
}
$stmt->close();

// Validate all linked items exist
if (!empty($linkedItemIDs)) {
    $placeholders = implode(',', array_fill(0, count($linkedItemIDs), '?'));
    $types = str_repeat('i', count($linkedItemIDs));
    $stmt = $link->prepare("SELECT WardrobeItemID FROM WardrobeItems WHERE WardrobeItemID IN ($placeholders) AND DeletedAt IS NULL");
    $stmt->bind_param($types, ...$linkedItemIDs);
    $stmt->execute();
    $stmt->store_result();
    if ($stmt->num_rows !== count($linkedItemIDs)) {
        $stmt->close();
        json_error('One or more linked items not found', 404);
    }
    $stmt->close();
}

// Begin transaction
$link->begin_transaction();

try {
    // Create the wear log
    $stmt = $link->prepare('INSERT INTO WardrobeWearLogs (CreatedByUserID, WornDate) VALUES (?, ?)');
    $stmt->bind_param('is', $userID, $wornDate);
    $stmt->execute();
    $wearLogID = $stmt->insert_id;
    $stmt->close();

    if (!$wearLogID) {
        throw new RuntimeException('Failed to create wear log');
    }

    // Link the primary item
    $stmt = $link->prepare('INSERT INTO WardrobeWearLogItems (WardrobeWearLogID, WardrobeItemID, IsPrimary) VALUES (?, ?, 1)');
    $stmt->bind_param('ii', $wearLogID, $primaryItemID);
    $stmt->execute();
    $stmt->close();

    // Link additional items
    if (!empty($linkedItemIDs)) {
        $stmt = $link->prepare('INSERT INTO WardrobeWearLogItems (WardrobeWearLogID, WardrobeItemID, IsPrimary) VALUES (?, ?, 0)');
        foreach ($linkedItemIDs as $linkedID) {
            $stmt->bind_param('ii', $wearLogID, $linkedID);
            $stmt->execute();
        }
        $stmt->close();
    }

    $link->commit();

    json_response([
        'success' => true,
        'wearEventID' => $wearLogID
    ], 201);

} catch (Throwable $e) {
    $link->rollback();
    json_error('Failed to save outfit: ' . $e->getMessage(), 500);
}
