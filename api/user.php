<?php
/**
 * /api/user.php
 * 
 * GET           — Returns the authenticated user's profile
 * GET ?household=1 — Returns both household members' basic info (name, photo)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth_middleware.php';

$userID = require_auth();
$method = get_method();

if ($method !== 'GET') {
    json_error('Method not allowed', 405);
}

// Return both household members
if (isset($_GET['household'])) {
    $res = $link->query(
        "SELECT UserID, Name, Email, IMGURL FROM DC_Users WHERE UserID IN (1, 3) ORDER BY UserID"
    );
    $members = [];
    while ($row = $res->fetch_assoc()) {
        $members[] = [
            'id' => (int)$row['UserID'],
            'name' => $row['Name'] ?? '',
            'email' => $row['Email'] ?? '',
            'photoURL' => $row['IMGURL'] ?? null,
        ];
    }
    json_response($members);
}

// Return authenticated user's profile
$stmt = $link->prepare(
    "SELECT UserID, Name, Email, PhoneNumber, IMGURL FROM DC_Users WHERE UserID = ?"
);
$stmt->bind_param('i', $userID);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    json_error('User not found', 404);
}

json_response([
    'id' => (int)$row['UserID'],
    'name' => $row['Name'] ?? '',
    'email' => $row['Email'] ?? '',
    'phone' => $row['PhoneNumber'] ?? '',
    'photoURL' => $row['IMGURL'] ?? null,
]);
