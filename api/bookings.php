<?php
/**
 * /api/bookings.php
 * 
 * GET  ?start=YYYY-MM-DD&end=YYYY-MM-DD  — bookings in date range (for calendar)
 * GET  ?id=123                            — single booking with guest details
 * 
 * Returns bookings with resolved guest names from Contacts table.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth_middleware.php';

$userID = require_auth();
$method = get_method();

switch ($method) {
    case 'GET':
        break; // fall through to existing GET logic below
    case 'POST':
        handle_create_booking();
        break;
    case 'PUT':
        handle_update_booking();
        break;
    default:
        json_error('Method not allowed', 405);
}

// Single booking
if (isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $link->prepare(
        "SELECT b.BookingID, b.StartDateTime, b.EndDateTime, b.GuestsJSON, 
                b.Occasion, b.Status, b.RoomID,
                r.Name AS RoomName
         FROM Bookings b
         LEFT JOIN HouseLocations r ON b.RoomID = r.RoomID
         WHERE b.BookingID = ?"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) json_error('Booking not found', 404);

    $guests = resolve_guests($row['GuestsJSON']);
    json_response(format_booking($row, $guests));
}

// Date range
$start = $_GET['start'] ?? null;
$end = $_GET['end'] ?? null;

if (!$start || !$end) {
    json_error('Provide id=N or start+end date range');
}

$startDt = date('Y-m-d 00:00:00', strtotime($start . ' -1 day'));
$endDt = date('Y-m-d 23:59:59', strtotime($end . ' +1 day'));

$stmt = $link->prepare(
    "SELECT b.BookingID, b.StartDateTime, b.EndDateTime, b.GuestsJSON,
            b.Occasion, b.Status, b.RoomID,
            r.Name AS RoomName
     FROM Bookings b
     LEFT JOIN HouseLocations r ON b.RoomID = r.RoomID
     WHERE b.StartDateTime <= ? AND b.EndDateTime >= ?
     ORDER BY b.StartDateTime ASC"
);
$stmt->bind_param('ss', $endDt, $startDt);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
$allGuestIDs = [];

while ($row = $res->fetch_assoc()) {
    $rows[] = $row;
    $decoded = json_decode($row['GuestsJSON'] ?? '[]', true);
    $ids = array_filter($decoded['guests'] ?? []);
    foreach ($ids as $gid) {
        $allGuestIDs[(int)$gid] = true;
    }
}
$stmt->close();

// Batch-fetch guest names
$namesById = [];
if (!empty($allGuestIDs)) {
    $in = implode(',', array_map('intval', array_keys($allGuestIDs)));
    $q = $link->query("SELECT ContactID, KnownAs, PhotoURL FROM Contacts WHERE ContactID IN ({$in})");
    while ($c = $q->fetch_assoc()) {
        $namesById[(int)$c['ContactID']] = [
            'id' => (int)$c['ContactID'],
            'name' => $c['KnownAs'],
            'photoURL' => $c['PhotoURL'],
        ];
    }
}

$bookings = [];
foreach ($rows as $row) {
    $decoded = json_decode($row['GuestsJSON'] ?? '[]', true);
    $ids = array_filter($decoded['guests'] ?? []);
    $guests = [];
    foreach ($ids as $gid) {
        $guests[] = $namesById[(int)$gid] ?? ['id' => (int)$gid, 'name' => 'Guest', 'photoURL' => null];
    }
    $bookings[] = format_booking($row, $guests);
}

json_response($bookings);

/* ─── Helpers ─────────────────────────────────────────────── */
function resolve_guests(string $json): array {
    global $link;

    $decoded = json_decode($json ?? '[]', true);
    $ids = array_filter($decoded['guests'] ?? []);

    if (empty($ids)) return [];

    $in = implode(',', array_map('intval', $ids));
    $q = $link->query("SELECT ContactID, KnownAs, PhotoURL FROM Contacts WHERE ContactID IN ({$in})");

    $guests = [];
    while ($c = $q->fetch_assoc()) {
        $guests[] = [
            'id' => (int)$c['ContactID'],
            'name' => $c['KnownAs'],
            'photoURL' => $c['PhotoURL'],
        ];
    }
    return $guests;
}

function format_booking(array $row, array $guests): array {
    return [
        'id' => (int)$row['BookingID'],
        'start' => $row['StartDateTime'],
        'end' => $row['EndDateTime'],
        'occasion' => $row['Occasion'] ?? '',
        'status' => $row['Status'] ?? '',
        'notes' => '',
        'room' => [
            'id' => $row['RoomID'] ? (int)$row['RoomID'] : null,
            'name' => $row['RoomName'] ?? '',
        ],
        'guests' => $guests,
    ];
}

/* ─── POST: Create Booking ────────────────────────────────── */
function handle_create_booking(): void {
    global $link, $userID;

    $body = get_json_body();

    $roomID = (int)require_field($body, 'roomID');
    $start = require_field($body, 'start');
    $end = require_field($body, 'end');
    $occasion = trim($body['occasion'] ?? '');
    $status = trim($body['status'] ?? 'Pencilled');
    $guestIDs = $body['guestIDs'] ?? [];

    if (empty($guestIDs)) {
        json_error('At least one guest is required');
    }

    // Validate guest IDs are integers
    $guestIDs = array_map('intval', array_filter($guestIDs));

    // Generate unique BookingRef
    do {
        $bookingRef = date('Y') . '-' . rand(100, 999);
        $check = $link->prepare("SELECT COUNT(*) AS c FROM Bookings WHERE BookingRef = ?");
        $check->bind_param('s', $bookingRef);
        $check->execute();
        $row = $check->get_result()->fetch_assoc();
        $check->close();
    } while ((int)$row['c'] > 0);

    $guestsJSON = json_encode(['guests' => $guestIDs]);

    $stmt = $link->prepare(
        "INSERT INTO Bookings (BookingRef, RoomID, StartDateTime, EndDateTime, Occasion, GuestsJSON, Status)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('sisssss', $bookingRef, $roomID, $start, $end, $occasion, $guestsJSON, $status);

    if (!$stmt->execute()) {
        json_error('Failed to create booking: ' . $stmt->error, 500);
    }

    $bookingID = $stmt->insert_id;
    $stmt->close();

    // Audit log
    $stmtAudit = $link->prepare(
        "INSERT INTO BookingsAuditLog (InitiatedBy, TargetBooking, ChangeMade, TimeStamp)
         VALUES (?, ?, 'Booking created via mobile', NOW())"
    );
    $stmtAudit->bind_param('ii', $userID, $bookingID);
    $stmtAudit->execute();
    $stmtAudit->close();

    // Fetch and return the created booking
    $stmt = $link->prepare(
        "SELECT b.BookingID, b.StartDateTime, b.EndDateTime, b.GuestsJSON,
                b.Occasion, b.Status, b.RoomID,
                r.Name AS RoomName
         FROM Bookings b
         LEFT JOIN HouseLocations r ON b.RoomID = r.RoomID
         WHERE b.BookingID = ?"
    );
    $stmt->bind_param('i', $bookingID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $guests = resolve_guests($row['GuestsJSON']);
    json_response(format_booking($row, $guests), 201);
}

/* ─── PUT: Update Booking ─────────────────────────────────── */
function handle_update_booking(): void {
    global $link, $userID;

    $body = get_json_body();
    $id = (int)require_field($body, 'id');

    // Check booking exists
    $check = $link->prepare("SELECT BookingID FROM Bookings WHERE BookingID = ?");
    $check->bind_param('i', $id);
    $check->execute();
    if ($check->get_result()->num_rows === 0) {
        $check->close();
        json_error('Booking not found', 404);
    }
    $check->close();

    // If only status is being updated (cancel operation)
    if (isset($body['status']) && !isset($body['roomID'])) {
        $status = trim($body['status']);
        $stmt = $link->prepare("UPDATE Bookings SET Status = ? WHERE BookingID = ?");
        $stmt->bind_param('si', $status, $id);

        if (!$stmt->execute()) {
            json_error('Failed to update booking: ' . $stmt->error, 500);
        }
        $stmt->close();

        // Audit log
        $stmtAudit = $link->prepare(
            "INSERT INTO BookingsAuditLog (InitiatedBy, TargetBooking, ChangeMade, TimeStamp)
             VALUES (?, ?, ?, NOW())"
        );
        $change = "Status changed to {$status} via mobile";
        $stmtAudit->bind_param('iis', $userID, $id, $change);
        $stmtAudit->execute();
        $stmtAudit->close();
    } else {
        // Full update
        $roomID = (int)require_field($body, 'roomID');
        $start = require_field($body, 'start');
        $end = require_field($body, 'end');
        $occasion = trim($body['occasion'] ?? '');
        $status = trim($body['status'] ?? 'Pencilled');
        $guestIDs = $body['guestIDs'] ?? [];

        if (empty($guestIDs)) {
            json_error('At least one guest is required');
        }

        $guestIDs = array_map('intval', array_filter($guestIDs));
        $guestsJSON = json_encode(['guests' => $guestIDs]);

        $stmt = $link->prepare(
            "UPDATE Bookings SET RoomID = ?, StartDateTime = ?, EndDateTime = ?, 
                    Occasion = ?, GuestsJSON = ?, Status = ?
             WHERE BookingID = ?"
        );
        $stmt->bind_param('isssssi', $roomID, $start, $end, $occasion, $guestsJSON, $status, $id);

        if (!$stmt->execute()) {
            json_error('Failed to update booking: ' . $stmt->error, 500);
        }
        $stmt->close();

        // Audit log
        $stmtAudit = $link->prepare(
            "INSERT INTO BookingsAuditLog (InitiatedBy, TargetBooking, ChangeMade, TimeStamp)
             VALUES (?, ?, 'Booking updated via mobile', NOW())"
        );
        $stmtAudit->bind_param('ii', $userID, $id);
        $stmtAudit->execute();
        $stmtAudit->close();
    }

    // Return the updated booking
    $stmt = $link->prepare(
        "SELECT b.BookingID, b.StartDateTime, b.EndDateTime, b.GuestsJSON,
                b.Occasion, b.Status, b.RoomID,
                r.Name AS RoomName
         FROM Bookings b
         LEFT JOIN HouseLocations r ON b.RoomID = r.RoomID
         WHERE b.BookingID = ?"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $guests = resolve_guests($row['GuestsJSON']);
    json_response(format_booking($row, $guests));
}
