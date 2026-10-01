<?php
/**
 * /api/events.php
 * 
 * GET    ?start=YYYY-MM-DD&end=YYYY-MM-DD  — list events in range
 * GET    ?id=123                            — single event
 * POST   { title, start, end?, location?, allDay?, source? }  — create
 * PUT    { id, title, start, end?, location?, allDay?, source? }  — update
 * DELETE ?id=123                            — delete
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
    case 'POST':
        handle_post();
        break;
    case 'PUT':
        handle_put();
        break;
    case 'DELETE':
        handle_delete();
        break;
    default:
        json_error('Method not allowed', 405);
}

/* ─── GET ─────────────────────────────────────────────────── */
function handle_get(): void {
    global $link;

    // Single event by ID
    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $stmt = $link->prepare(
            "SELECT EventID, EventTitle, StartDateTime, EndDateTime, Location, AllDay, Source, userID
             FROM Events WHERE EventID = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if (!$row) json_error('Event not found', 404);

        json_response(format_event($row));
    }

    // List events in date range
    $start = $_GET['start'] ?? null;
    $end = $_GET['end'] ?? null;

    if (!$start || !$end) {
        json_error('start and end query parameters are required');
    }

    $startDt = date('Y-m-d 00:00:00', strtotime($start));
    $endDt = date('Y-m-d 23:59:59', strtotime($end));

    $stmt = $link->prepare(
        "SELECT EventID, EventTitle, StartDateTime, EndDateTime, Location, AllDay, Source, userID
         FROM Events
         WHERE StartDateTime <= ? AND (EndDateTime IS NULL OR EndDateTime >= ?)
         ORDER BY StartDateTime ASC"
    );
    $stmt->bind_param('ss', $endDt, $startDt);
    $stmt->execute();
    $res = $stmt->get_result();

    $events = [];
    while ($row = $res->fetch_assoc()) {
        $events[] = format_event($row);
    }
    $stmt->close();

    json_response($events);
}

/* ─── POST ────────────────────────────────────────────────── */
function handle_post(): void {
    global $link;

    $body = get_json_body();

    $title = trim(require_field($body, 'title'));
    $start = require_field($body, 'start');
    $end = $body['end'] ?? null;
    $location = trim($body['location'] ?? '');
    $allDay = (int)($body['allDay'] ?? 0);
    $source = trim($body['source'] ?? 'Mobile');
    $eventUserID = (int)($body['userID'] ?? 0); // 0=joint, 1=CK, 3=DC

    $stmt = $link->prepare(
        "INSERT INTO Events (EventTitle, StartDateTime, EndDateTime, Location, AllDay, Source, userID)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('ssssisi', $title, $start, $end, $location, $allDay, $source, $eventUserID);

    if (!$stmt->execute()) {
        json_error('Failed to create event: ' . $stmt->error, 500);
    }

    $newID = $stmt->insert_id;
    $stmt->close();

    // Fetch the newly created event
    $stmt = $link->prepare(
        "SELECT EventID, EventTitle, StartDateTime, EndDateTime, Location, AllDay, Source, userID
         FROM Events WHERE EventID = ?"
    );
    $stmt->bind_param('i', $newID);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    json_response(format_event($row), 201);
}

/* ─── PUT ─────────────────────────────────────────────────── */
function handle_put(): void {
    global $link;

    $body = get_json_body();
    $id = (int)require_field($body, 'id');
    $title = trim(require_field($body, 'title'));
    $start = require_field($body, 'start');
    $end = $body['end'] ?? null;
    $location = trim($body['location'] ?? '');
    $allDay = (int)($body['allDay'] ?? 0);
    $source = trim($body['source'] ?? 'Mobile');
    $eventUserID = (int)($body['userID'] ?? 0);

    $stmt = $link->prepare(
        "UPDATE Events SET EventTitle = ?, StartDateTime = ?, EndDateTime = ?, Location = ?, AllDay = ?, Source = ?, userID = ?
         WHERE EventID = ?"
    );
    $stmt->bind_param('ssssissi', $title, $start, $end, $location, $allDay, $source, $eventUserID, $id);

    if (!$stmt->execute()) {
        json_error('Failed to update event: ' . $stmt->error, 500);
    }

    if ($stmt->affected_rows === 0) {
        $stmt->close();
        json_error('Event not found', 404);
    }
    $stmt->close();

    // Return updated event
    $stmt = $link->prepare(
        "SELECT EventID, EventTitle, StartDateTime, EndDateTime, Location, AllDay, Source, userID
         FROM Events WHERE EventID = ?"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    json_response(format_event($row));
}

/* ─── DELETE ──────────────────────────────────────────────── */
function handle_delete(): void {
    global $link;

    $id = (int)($_GET['id'] ?? 0);
    if ($id === 0) {
        json_error('Event ID is required');
    }

    $stmt = $link->prepare("DELETE FROM Events WHERE EventID = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();

    if ($stmt->affected_rows === 0) {
        $stmt->close();
        json_error('Event not found', 404);
    }
    $stmt->close();

    json_response(['deleted' => true, 'id' => $id]);
}

/* ─── Helpers ─────────────────────────────────────────────── */
function format_event(array $row): array {
    $uid = isset($row['userID']) ? (int)$row['userID'] : 0;
    // 0 = Joint, 1 = CK, 3 = DC
    $ownership = 'joint';
    if ($uid === 1) $ownership = 'ck';
    elseif ($uid === 3) $ownership = 'dc';

    return [
        'id' => (int)$row['EventID'],
        'title' => $row['EventTitle'],
        'start' => $row['StartDateTime'],
        'end' => $row['EndDateTime'],
        'location' => $row['Location'] ?? '',
        'allDay' => (bool)(int)$row['AllDay'],
        'source' => $row['Source'] ?? '',
        'userID' => $uid,
        'ownership' => $ownership,
    ];
}
