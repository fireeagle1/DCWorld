<?php
declare(strict_types=1);

/**
 * Item matches API — shows items usually worn with a given item.
 *
 * GET /api/wardrobe_item_matches.php?id=45
 *
 * Response matches the iOS ItemMatchesResponse model:
 * {
 *   "everyone": [ { "itemID": 12, "name": "Blue Jeans", "image": "...", "count": 5 } ],
 *   "users": {
 *     "Charlie": [ { "itemID": 12, "name": "Blue Jeans", "image": "...", "count": 3 } ]
 *   }
 * }
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth_middleware.php';
require_once dirname(__DIR__) . '/wardrobe_common.php';

$userID = require_auth();

if (get_method() !== 'GET') {
    json_error('Method not allowed', 405);
}

$itemID = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($itemID < 1) {
    json_error('id parameter is required');
}

// Find all wear events that include this item, then get the other items in those events
$sql = "SELECT wli2.WardrobeItemID, wi.Name AS ItemName, wi.CatalogueImagePreference,
               u.Name AS UserName, COUNT(*) AS WornCount
        FROM WardrobeWearLogItems wli1
        JOIN WardrobeWearLogItems wli2 ON wli2.WardrobeWearLogID = wli1.WardrobeWearLogID
            AND wli2.WardrobeItemID != wli1.WardrobeItemID
        JOIN WardrobeItems wi ON wi.WardrobeItemID = wli2.WardrobeItemID AND wi.DeletedAt IS NULL
        JOIN WardrobeWearLogs wl ON wl.WardrobeWearLogID = wli1.WardrobeWearLogID
        LEFT JOIN DC_Users u ON u.UserID = wl.CreatedByUserID
        WHERE wli1.WardrobeItemID = ?
        GROUP BY wli2.WardrobeItemID, u.Name
        ORDER BY WornCount DESC, wi.Name";

$stmt = $link->prepare($sql);
$stmt->bind_param('i', $itemID);
$stmt->execute();
$result = $stmt->get_result();

$everyone = [];    // itemID => match data
$byUser = [];      // userName => [itemID => match data]

while ($row = $result->fetch_assoc()) {
    $matchItemID = (int)$row['WardrobeItemID'];
    $count = (int)$row['WornCount'];
    $pref = $row['CatalogueImagePreference'] ?? 'processed';
    $userName = $row['UserName'] ?? 'Unknown';

    $matchEntry = [
        'itemID' => $matchItemID,
        'name' => $row['ItemName'] ?? 'Untitled',
        'image' => wardrobe_image_url($matchItemID, $pref, 'front'),
        'count' => $count,
    ];

    // Aggregate for "everyone"
    if (isset($everyone[$matchItemID])) {
        $everyone[$matchItemID]['count'] += $count;
    } else {
        $everyone[$matchItemID] = $matchEntry;
    }

    // Per-user breakdown
    if (!isset($byUser[$userName])) {
        $byUser[$userName] = [];
    }
    $byUser[$userName][] = $matchEntry;
}
$stmt->close();

// Sort everyone by count descending
$everyoneList = array_values($everyone);
usort($everyoneList, static fn($a, $b) => $b['count'] - $a['count']);

// Sort each user's list by count descending
foreach ($byUser as &$userMatches) {
    usort($userMatches, static fn($a, $b) => $b['count'] - $a['count']);
}
unset($userMatches);

json_response([
    'everyone' => $everyoneList,
    'users' => (object)$byUser,
]);
