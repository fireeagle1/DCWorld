<?php
declare(strict_types=1);

/**
 * Matching items API — suggests compatible items to wear with a given item,
 * grouped by complementary categories.
 *
 * GET /api/wardrobe_matching_items.php?id=45
 *
 * Response matches the iOS MatchingItemsResponse model (decoded as [String: [WardrobeItem]]):
 * {
 *   "Bottoms": [ ...wardrobe items... ],
 *   "Shoes": [ ...wardrobe items... ]
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

// Get the item's category to determine complementary categories
$stmt = $link->prepare('SELECT Category FROM WardrobeItems WHERE WardrobeItemID = ? AND DeletedAt IS NULL');
$stmt->bind_param('i', $itemID);
$stmt->execute();
$stmt->bind_result($itemCategory);
if (!$stmt->fetch()) {
    $stmt->close();
    json_error('Item not found', 404);
}
$stmt->close();

// Determine which categories complement this item
$complementMap = [
    'tops' => ['bottoms', 'outerwear', 'shoes', 'accessories'],
    'bottoms' => ['tops', 'outerwear', 'shoes', 'accessories'],
    'outerwear' => ['tops', 'bottoms', 'shoes', 'accessories'],
    'dresses' => ['outerwear', 'shoes', 'accessories'],
    'shoes' => ['tops', 'bottoms', 'outerwear', 'accessories'],
    'accessories' => ['tops', 'bottoms', 'outerwear', 'shoes'],
    'activewear' => ['shoes', 'accessories'],
    'sleepwear' => [],
    'underwear' => [],
    'other' => wardrobe_allowed_categories(),
];

$complementCategories = $complementMap[$itemCategory] ?? wardrobe_allowed_categories();

if (empty($complementCategories)) {
    json_response(new stdClass()); // Empty object
}

// Fetch items from complementary categories (excluding the current item)
$placeholders = implode(',', array_fill(0, count($complementCategories), '?'));
$types = str_repeat('s', count($complementCategories)) . 'i';
$params = array_merge($complementCategories, [$itemID]);

$sql = "SELECT * FROM WardrobeItems
        WHERE Category IN ($placeholders)
          AND WardrobeItemID != ?
          AND DeletedAt IS NULL
        ORDER BY Favourite DESC, UpdatedAt DESC";

$stmt = $link->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$grouped = [];
while ($row = $result->fetch_assoc()) {
    $cat = ucwords($row['Category']);
    if (!isset($grouped[$cat])) {
        $grouped[$cat] = [];
    }
    // Limit to 10 items per category
    if (count($grouped[$cat]) < 10) {
        $grouped[$cat][] = wardrobe_item_to_array($row);
    }
}
$stmt->close();

json_response((object)$grouped);
