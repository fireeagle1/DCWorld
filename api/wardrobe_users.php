<?php
declare(strict_types=1);

/**
 * Wardrobe users API — returns users who share the wardrobe.
 *
 * GET /api/wardrobe_users.php
 *
 * Response: array of WardrobeUser objects matching the iOS model:
 * [
 *   { "id": 1, "name": "Charlie", "photoURL": null }
 * ]
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth_middleware.php';

$userID = require_auth();

if (get_method() !== 'GET') {
    json_error('Method not allowed', 405);
}

// Find all users who have recorded at least one wear event
$sql = "SELECT DISTINCT u.UserID, u.Name
        FROM WardrobeWearLogs wl
        JOIN DC_Users u ON u.UserID = wl.CreatedByUserID
        ORDER BY u.Name";

$result = $link->query($sql);
$users = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $users[] = [
            'id' => (int)$row['UserID'],
            'name' => $row['Name'] ?? 'Unknown',
            'photoURL' => null,
        ];
    }
}

json_response($users);
