<?php
declare(strict_types=1);

/**
 * Outfit feed API — returns wear events (outfits) as JSON.
 *
 * GET /api/wardrobe_outfits.php          — all outfits (everyone)
 * GET /api/wardrobe_outfits.php?user=2   — outfits for a specific user
 *
 * Response: array of WearEvent objects matching the iOS model:
 * [
 *   {
 *     "wearEventID": 1,
 *     "userID": 2,
 *     "userName": "Charlie",
 *     "dateWorn": "2026-07-21",
 *     "items": [
 *       { "itemID": 45, "name": "Navy T-Shirt", "image": "wardrobe_image.php?id=45&view=processed_front" }
 *     ]
 *   }
 * ]
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth_middleware.php';
require_once dirname(__DIR__) . '/wardrobe_common.php';

$userID = require_auth();

if (get_method() !== 'GET') {
    json_error('Method not allowed', 405);
}

$filterUser = isset($_GET['user']) ? (int)$_GET['user'] : 0;

// Query wear logs with their items
$sql = "SELECT wl.WardrobeWearLogID, wl.WornDate, wl.CreatedByUserID, u.Name AS UserName,
               wli.WardrobeItemID, wli.IsPrimary,
               wi.Name AS ItemName, wi.CatalogueImagePreference
        FROM WardrobeWearLogs wl
        LEFT JOIN DC_Users u ON u.UserID = wl.CreatedByUserID
        JOIN WardrobeWearLogItems wli ON wli.WardrobeWearLogID = wl.WardrobeWearLogID
        JOIN WardrobeItems wi ON wi.WardrobeItemID = wli.WardrobeItemID
        WHERE wi.DeletedAt IS NULL";

$types = '';
$params = [];

if ($filterUser > 0) {
    $sql .= ' AND wl.CreatedByUserID = ?';
    $types .= 'i';
    $params[] = $filterUser;
}

$sql .= ' ORDER BY wl.WornDate DESC, wl.WardrobeWearLogID DESC, wli.IsPrimary DESC, wi.Name';

$stmt = $link->prepare($sql);
if ($types !== '') {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Group items by wear log
$outfits = [];
while ($row = $result->fetch_assoc()) {
    $logId = (int)$row['WardrobeWearLogID'];
    $itemId = (int)$row['WardrobeItemID'];
    $pref = $row['CatalogueImagePreference'] ?? 'processed';

    if (!isset($outfits[$logId])) {
        $outfits[$logId] = [
            'wearEventID' => $logId,
            'userID' => (int)$row['CreatedByUserID'],
            'userName' => $row['UserName'] ?? 'Unknown',
            'dateWorn' => $row['WornDate'],
            'items' => [],
        ];
    }

    $outfits[$logId]['items'][] = [
        'itemID' => $itemId,
        'name' => $row['ItemName'] ?? 'Untitled',
        'image' => wardrobe_image_url($itemId, $pref, 'front'),
    ];
}
$stmt->close();

// Return as a flat array (ordered by date descending)
json_response(array_values($outfits));
