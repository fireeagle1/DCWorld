<?php
declare(strict_types=1);

/**
 * Single outfit/wear event detail API.
 *
 * GET /api/wardrobe_outfit.php?id=5
 *
 * Response: a single WearEvent object.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth_middleware.php';
require_once dirname(__DIR__) . '/wardrobe_common.php';

$userID = require_auth();

if (get_method() !== 'GET') {
    json_error('Method not allowed', 405);
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id < 1) {
    json_error('id parameter is required');
}

$sql = "SELECT wl.WardrobeWearLogID, wl.WornDate, wl.CreatedByUserID, u.Name AS UserName,
               wli.WardrobeItemID, wli.IsPrimary,
               wi.Name AS ItemName, wi.CatalogueImagePreference
        FROM WardrobeWearLogs wl
        LEFT JOIN DC_Users u ON u.UserID = wl.CreatedByUserID
        JOIN WardrobeWearLogItems wli ON wli.WardrobeWearLogID = wl.WardrobeWearLogID
        JOIN WardrobeItems wi ON wi.WardrobeItemID = wli.WardrobeItemID
        WHERE wl.WardrobeWearLogID = ? AND wi.DeletedAt IS NULL
        ORDER BY wli.IsPrimary DESC, wi.Name";

$stmt = $link->prepare($sql);
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();

$event = null;
while ($row = $result->fetch_assoc()) {
    $itemId = (int)$row['WardrobeItemID'];
    $pref = $row['CatalogueImagePreference'] ?? 'processed';

    if ($event === null) {
        $event = [
            'wearEventID' => (int)$row['WardrobeWearLogID'],
            'userID' => (int)$row['CreatedByUserID'],
            'userName' => $row['UserName'] ?? 'Unknown',
            'dateWorn' => $row['WornDate'],
            'items' => [],
        ];
    }

    $event['items'][] = [
        'itemID' => $itemId,
        'name' => $row['ItemName'] ?? 'Untitled',
        'image' => wardrobe_image_url($itemId, $pref, 'front'),
    ];
}
$stmt->close();

if ($event === null) {
    json_error('Outfit not found', 404);
}

json_response($event);
