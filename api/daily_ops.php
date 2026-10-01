<?php
/**
 * /api/daily_ops.php
 * 
 * GET  ?start=YYYY-MM-DD&end=YYYY-MM-DD  — week/range of daily ops
 * GET  ?date=YYYY-MM-DD                   — single day
 * PUT  { date, ckLocation?, dcLocation?, ckWorkLocation?, dcWorkLocation?,
 *         ckOnCall?, dcOnCall?, notes? }   — upsert a day's plan
 * 
 * Also:
 * GET  ?locations=1    — list all available night locations
 * GET  ?worklocations=1 — list all available work locations
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth_middleware.php';

$userID = require_auth();
$method = get_method();

switch ($method) {
    case 'GET':
        handle_get();
        break;
    case 'PUT':
        handle_put();
        break;
    default:
        json_error('Method not allowed', 405);
}

/* ─── GET ─────────────────────────────────────────────────── */
function handle_get(): void {
    global $link;

    // Return reference data: night locations
    if (isset($_GET['locations'])) {
        // IDs that are considered "default/excluded" — not shown on calendar
        $excluded = [4, 6, 7, 10];
        $res = $link->query(
            "SELECT LocationID AS id, LocationDesc AS name, Location_Icon AS icon 
             FROM DC_Locations ORDER BY LocationID"
        );
        $locations = [];
        while ($row = $res->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['isDefault'] = in_array($row['id'], $excluded, true);
            $locations[] = $row;
        }
        json_response($locations);
    }

    // Return reference data: work locations
    if (isset($_GET['worklocations'])) {
        // IDs that are considered "default/excluded" — not shown on calendar
        $excluded = [4, 6, 7];
        $res = $link->query(
            "SELECT WorkID AS id, WLocationDesc AS name, WLocation_Icon AS icon 
             FROM DC_WorkLocation ORDER BY WorkID"
        );
        $locations = [];
        while ($row = $res->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['isDefault'] = in_array($row['id'], $excluded, true);
            $locations[] = $row;
        }
        json_response($locations);
    }

    // Single date
    if (isset($_GET['date'])) {
        $date = $_GET['date'];
        $row = fetch_ops_day($date);
        if (!$row) {
            // Return empty structure for that date
            json_response(empty_day($date));
        }
        json_response(format_ops_day($row));
    }

    // Date range
    $start = $_GET['start'] ?? null;
    $end = $_GET['end'] ?? null;

    if (!$start || !$end) {
        json_error('Provide date=YYYY-MM-DD or start+end range');
    }

    $stmt = $link->prepare(
        "SELECT p.Date, p.Notes, p.CKOnCall, p.DCOnCall,
                p.CKLocation, p.DCLocation, p.CKWorkLocation, p.DCWorkLocation,
                loc.LocationDesc AS CKLocationName, loc.Location_Icon AS CKLocationIcon,
                loc2.LocationDesc AS DCLocationName, loc2.Location_Icon AS DCLocationIcon,
                w1.WLocationDesc AS CKWorkName, w1.WLocation_Icon AS CKWorkIcon,
                w2.WLocationDesc AS DCWorkName, w2.WLocation_Icon AS DCWorkIcon
         FROM DCDailyOpsPlan p
         LEFT JOIN DC_Locations loc ON p.CKLocation = loc.LocationID
         LEFT JOIN DC_Locations loc2 ON p.DCLocation = loc2.LocationID
         LEFT JOIN DC_WorkLocation w1 ON p.CKWorkLocation = w1.WorkID
         LEFT JOIN DC_WorkLocation w2 ON p.DCWorkLocation = w2.WorkID
         WHERE p.Date BETWEEN ? AND ?
         ORDER BY p.Date ASC"
    );
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();

    $days = [];
    while ($row = $res->fetch_assoc()) {
        $days[] = format_ops_day($row);
    }
    $stmt->close();

    // Fill in any missing days in the range
    $cursor = new DateTime($start);
    $endDt = new DateTime($end);
    $existingDates = array_column($days, 'date');
    $filled = [];

    while ($cursor <= $endDt) {
        $d = $cursor->format('Y-m-d');
        $idx = array_search($d, $existingDates);
        if ($idx !== false) {
            $filled[] = $days[$idx];
        } else {
            $filled[] = empty_day($d);
        }
        $cursor->modify('+1 day');
    }

    json_response($filled);
}

/* ─── PUT (upsert) ────────────────────────────────────────── */
function handle_put(): void {
    global $link;

    $body = get_json_body();
    $date = require_field($body, 'date');

    $ckLocation = isset($body['ckLocation']) ? (int)$body['ckLocation'] : null;
    $dcLocation = isset($body['dcLocation']) ? (int)$body['dcLocation'] : null;
    $ckWorkLocation = isset($body['ckWorkLocation']) ? (int)$body['ckWorkLocation'] : null;
    $dcWorkLocation = isset($body['dcWorkLocation']) ? (int)$body['dcWorkLocation'] : null;
    $ckOnCall = isset($body['ckOnCall']) ? (int)(bool)$body['ckOnCall'] : 0;
    $dcOnCall = isset($body['dcOnCall']) ? (int)(bool)$body['dcOnCall'] : 0;
    $notes = trim($body['notes'] ?? '');

    // Check if row exists
    $existing = fetch_ops_day($date);

    if ($existing) {
        // Update
        $stmt = $link->prepare(
            "UPDATE DCDailyOpsPlan 
             SET CKLocation = ?, DCLocation = ?, CKWorkLocation = ?, DCWorkLocation = ?,
                 CKOnCall = ?, DCOnCall = ?, Notes = ?
             WHERE Date = ?"
        );
        $stmt->bind_param(
            'iiiiiiss',
            $ckLocation, $dcLocation, $ckWorkLocation, $dcWorkLocation,
            $ckOnCall, $dcOnCall, $notes, $date
        );
    } else {
        // Insert
        $stmt = $link->prepare(
            "INSERT INTO DCDailyOpsPlan (Date, CKLocation, DCLocation, CKWorkLocation, DCWorkLocation, CKOnCall, DCOnCall, Notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'siiiiiis',
            $date, $ckLocation, $dcLocation, $ckWorkLocation, $dcWorkLocation,
            $ckOnCall, $dcOnCall, $notes
        );
    }

    if (!$stmt->execute()) {
        json_error('Failed to save daily ops: ' . $stmt->error, 500);
    }
    $stmt->close();

    // Return the updated day with resolved names
    $row = fetch_ops_day($date);
    json_response($row ? format_ops_day($row) : empty_day($date));
}

/* ─── Helpers ─────────────────────────────────────────────── */
function fetch_ops_day(string $date): ?array {
    global $link;

    $stmt = $link->prepare(
        "SELECT p.Date, p.Notes, p.CKOnCall, p.DCOnCall,
                p.CKLocation, p.DCLocation, p.CKWorkLocation, p.DCWorkLocation,
                loc.LocationDesc AS CKLocationName, loc.Location_Icon AS CKLocationIcon,
                loc2.LocationDesc AS DCLocationName, loc2.Location_Icon AS DCLocationIcon,
                w1.WLocationDesc AS CKWorkName, w1.WLocation_Icon AS CKWorkIcon,
                w2.WLocationDesc AS DCWorkName, w2.WLocation_Icon AS DCWorkIcon
         FROM DCDailyOpsPlan p
         LEFT JOIN DC_Locations loc ON p.CKLocation = loc.LocationID
         LEFT JOIN DC_Locations loc2 ON p.DCLocation = loc2.LocationID
         LEFT JOIN DC_WorkLocation w1 ON p.CKWorkLocation = w1.WorkID
         LEFT JOIN DC_WorkLocation w2 ON p.DCWorkLocation = w2.WorkID
         WHERE p.Date = ?"
    );
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function format_ops_day(array $row): array {
    return [
        'date' => $row['Date'],
        'notes' => $row['Notes'] ?? '',
        'ckOnCall' => (bool)(int)($row['CKOnCall'] ?? 0),
        'dcOnCall' => (bool)(int)($row['DCOnCall'] ?? 0),
        'ckLocation' => [
            'id' => $row['CKLocation'] ? (int)$row['CKLocation'] : null,
            'name' => $row['CKLocationName'] ?? '',
            'icon' => $row['CKLocationIcon'] ?? '',
        ],
        'dcLocation' => [
            'id' => $row['DCLocation'] ? (int)$row['DCLocation'] : null,
            'name' => $row['DCLocationName'] ?? '',
            'icon' => $row['DCLocationIcon'] ?? '',
        ],
        'ckWorkLocation' => [
            'id' => $row['CKWorkLocation'] ? (int)$row['CKWorkLocation'] : null,
            'name' => $row['CKWorkName'] ?? '',
            'icon' => $row['CKWorkIcon'] ?? '',
        ],
        'dcWorkLocation' => [
            'id' => $row['DCWorkLocation'] ? (int)$row['DCWorkLocation'] : null,
            'name' => $row['DCWorkName'] ?? '',
            'icon' => $row['DCWorkIcon'] ?? '',
        ],
    ];
}

function empty_day(string $date): array {
    return [
        'date' => $date,
        'notes' => '',
        'ckOnCall' => false,
        'dcOnCall' => false,
        'ckLocation' => ['id' => null, 'name' => '', 'icon' => ''],
        'dcLocation' => ['id' => null, 'name' => '', 'icon' => ''],
        'ckWorkLocation' => ['id' => null, 'name' => '', 'icon' => ''],
        'dcWorkLocation' => ['id' => null, 'name' => '', 'icon' => ''],
    ];
}
