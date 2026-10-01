<?php
/**
 * /api/rooms.php
 * 
 * GET — Returns all house locations (rooms) for booking creation.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth_middleware.php';

$userID = require_auth();

if (get_method() !== 'GET') {
    json_error('Method not allowed', 405);
}

$res = $link->query("SELECT RoomID, Name FROM HouseLocations ORDER BY RoomID");

$rooms = [];
while ($row = $res->fetch_assoc()) {
    $rooms[] = [
        'id' => (int)$row['RoomID'],
        'name' => $row['Name'],
    ];
}

json_response($rooms);
