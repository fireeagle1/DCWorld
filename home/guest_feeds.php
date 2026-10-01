<?php
/*  guest_feeds.php — JSON endpoints for the Guest Manager page
    ----------------------------------------------------------------
    Session-authenticated (same cookie/login as the rest of the web
    app — NOT the JWT mobile API under /api). Split out of
    guest_manager.php so the page is a view and this is its controller.

      GET ?feed=calendar&start=…&end=…   calendar events in range
      GET ?feed=search&q=…               global booking search (dropdown)

    Both responses are arrays of plain objects consumed by
    assets/guest_manager.js. Requires MySQL 8+ (JSON_TABLE).
    ---------------------------------------------------------------- */

declare(strict_types=1);

require '../auth.php';            // session_start + config.php ($link) + login gate
require __DIR__ . '/guest_helpers.php';

header('Content-Type: application/json; charset=utf-8');

/** Emit a JSON payload and stop. */
function gm_json(mixed $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$feed = $_GET['feed'] ?? '';

switch ($feed) {
    case 'calendar':
        gm_feed_calendar($link);
        break;
    case 'search':
        gm_feed_search($link);
        break;
    default:
        gm_json(['error' => 'Unknown feed'], 400);
}

/* ─── calendar: events overlapping [start, end) ───────────────── */
function gm_feed_calendar(mysqli $link): never
{
    try {
        $start = isset($_GET['start']) ? new DateTime($_GET['start']) : null;
        $end   = isset($_GET['end'])   ? new DateTime($_GET['end'])   : null;
    } catch (Exception $e) {
        gm_json(['error' => 'Invalid date range'], 400);
    }

    if (!$start || !$end) {
        gm_json(['error' => 'Missing start/end'], 400);
    }

    $sql = "
        SELECT
            b.BookingID,
            b.StartDateTime,
            b.EndDateTime,
            b.Status,
            b.Occasion,
            r.Name AS RoomName,
            GROUP_CONCAT(c.KnownAs ORDER BY c.KnownAs SEPARATOR ', ') AS GuestNames,
            COUNT(*) AS GuestCount
        FROM Bookings b
        LEFT JOIN HouseLocations r ON b.RoomID = r.RoomID
        JOIN JSON_TABLE(
            b.GuestsJSON,
            '$.guests[*]' COLUMNS (guest_id INT PATH '$')
        ) jt
        JOIN Contacts c ON c.ContactID = jt.guest_id
        WHERE b.StartDateTime < ?
          AND b.EndDateTime   > ?
        GROUP BY b.BookingID
        ORDER BY b.StartDateTime ASC
    ";

    $stmt = $link->prepare($sql);
    if (!$stmt) {
        gm_json(['error' => 'Database error'], 500);
    }

    $endStr   = $end->format('Y-m-d H:i:s');
    $startStr = $start->format('Y-m-d H:i:s');
    $stmt->bind_param('ss', $endStr, $startStr);
    $stmt->execute();
    $res = $stmt->get_result();

    $events = [];
    while ($row = $res->fetch_assoc()) {
        $events[] = [
            'start'      => $row['StartDateTime'],
            'end'        => $row['EndDateTime'],
            'bookingID'  => (int)$row['BookingID'],
            'color'      => gm_status_color($row['Status']),
            'status'     => (string)($row['Status'] ?? ''),
            'room'       => (string)($row['RoomName'] ?? ''),
            'occasion'   => (string)($row['Occasion'] ?? ''),
            'guestNames' => (string)($row['GuestNames'] ?? ''),
            'guestCount' => (int)($row['GuestCount'] ?? 0),
        ];
    }
    $stmt->close();

    gm_json($events);
}

/* ─── search: all bookings by guest name OR occasion ──────────── */
function gm_feed_search(mysqli $link): never
{
    $needle = isset($_GET['q']) ? trim($_GET['q']) : '';
    if ($needle === '') {
        gm_json([]);
    }

    $limit = 20;

    $sql = "
        SELECT
            b.BookingID,
            b.StartDateTime,
            b.EndDateTime,
            b.Status,
            b.Occasion,
            r.Name AS RoomName,
            GROUP_CONCAT(c.KnownAs ORDER BY c.KnownAs SEPARATOR ', ') AS GuestNames,
            COUNT(*) AS GuestCount
        FROM Bookings b
        LEFT JOIN HouseLocations r ON b.RoomID = r.RoomID
        JOIN JSON_TABLE(
            b.GuestsJSON,
            '$.guests[*]' COLUMNS (guest_id INT PATH '$')
        ) jt
        JOIN Contacts c ON c.ContactID = jt.guest_id
        WHERE
            (b.Occasion LIKE CONCAT('%', ?, '%'))
            OR EXISTS (
                SELECT 1
                FROM JSON_TABLE(b.GuestsJSON, '$.guests[*]' COLUMNS (gid INT PATH '$')) jt2
                JOIN Contacts c2 ON c2.ContactID = jt2.gid
                WHERE c2.KnownAs LIKE CONCAT('%', ?, '%')
            )
        GROUP BY b.BookingID
        ORDER BY b.StartDateTime DESC
        LIMIT ?
    ";

    $stmt = $link->prepare($sql);
    if (!$stmt) {
        gm_json(['error' => 'Database error'], 500);
    }
    $stmt->bind_param('ssi', $needle, $needle, $limit);
    $stmt->execute();
    $res = $stmt->get_result();

    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = [
            'bookingID'  => (int)$row['BookingID'],
            'start'      => $row['StartDateTime'],
            'end'        => $row['EndDateTime'],
            'status'     => (string)($row['Status'] ?? ''),
            'room'       => (string)($row['RoomName'] ?? ''),
            'occasion'   => (string)($row['Occasion'] ?? ''),
            'guests'     => (string)($row['GuestNames'] ?? ''),
            'guestCount' => (int)($row['GuestCount'] ?? 0),
        ];
    }
    $stmt->close();

    gm_json($out);
}
