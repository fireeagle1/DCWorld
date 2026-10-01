<?php
/**
 * /api/brand_images.php
 * 
 * GET — Returns all brand images with their keywords for event background matching.
 * 
 * Response:
 * [
 *   { "id": 1, "url": "https://...", "keywords": "default", "isDefault": true, "isDuty": false },
 *   { "id": 2, "url": "https://...", "keywords": "duty", "isDefault": false, "isDuty": true },
 *   ...
 * ]
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth_middleware.php';

$userID = require_auth();

if (get_method() !== 'GET') {
    json_error('Method not allowed', 405);
}

$res = $link->query("SELECT brandimageID, BrandImageURL, Keywords FROM BrandImages ORDER BY brandimageID");

$images = [];
while ($row = $res->fetch_assoc()) {
    $images[] = [
        'id' => (int)$row['brandimageID'],
        'url' => $row['BrandImageURL'] ?? '',
        'keywords' => $row['Keywords'] ?? '',
        'isDefault' => ((int)$row['brandimageID'] === 1),
        'isDuty' => ((int)$row['brandimageID'] === 2),
    ];
}

json_response($images);
