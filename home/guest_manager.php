<?php
/*  guest_manager.php — bookings dashboard (direct-open, month nav)
    -----------------------------------------------------------------
    Features included:
    - Top bar: global booking search (guest name OR occasion) with dropdown results (opens view_booking.php)
    - Calendar: range-fed (no loading ALL bookings), improved legibility + truncation + occasion displayed
    - Upcoming rail: SQL pagination + guest-name filter (q), excludes Cancelled
    - Upcoming cards: long guest lists show "A, B and N others" + occasion displayed
    - Reports button
    -----------------------------------------------------------------*/

require '../auth.php';

/* ── helpers ───────────────────────────────────────────────────── */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* ── absolute base URL (directory of this script) ─────────────── */
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$scheme = $https ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$dir    = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$BASE   = $scheme . '://' . $host . ($dir ? $dir : '');   // e.g. https://site.tld/home

/* ── default avatar ────────────────────────────────────────────── */
$defaultAvatar = 'https://assets.dcworld.uk/images/contacts/Black%20and%20white%20organic%20farmhouse%20mountain%20landscape%20hand%20drawn%20logo.png';

/* ── inputs (GET) ──────────────────────────────────────────────── */
$q = isset($_GET['q']) ? trim($_GET['q']) : ''; // upcoming guest-name search only (rail)

/* ────────────────────────────────────────────────────────────────
   Calendar feed endpoint — range-based
   guest_manager.php?calendar_feed=1&start=...&end=...
   Returns minimal fields; UI builds short guest display to avoid overflow.
   NOTE: Requires MySQL 8+ for JSON_TABLE.
   ──────────────────────────────────────────────────────────────── */
if (isset($_GET['calendar_feed'])) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $start = isset($_GET['start']) ? new DateTime($_GET['start']) : null;
        $end   = isset($_GET['end'])   ? new DateTime($_GET['end'])   : null;
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date range']);
        exit;
    }

    if (!$start || !$end) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing start/end']);
        exit;
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
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
        exit;
    }

    $endStr   = $end->format('Y-m-d H:i:s');
    $startStr = $start->format('Y-m-d H:i:s');
    $stmt->bind_param('ss', $endStr, $startStr);
    $stmt->execute();
    $res = $stmt->get_result();

    $events = [];
    while ($row = $res->fetch_assoc()) {
        $statusColor = match ($row['Status']) {
            'Confirmed' => '#28a745',
            'Tentative' => '#f0ad4e',
            'Cancelled' => '#dc3545',
            default     => '#6c757d',
        };

        $room = (string)($row['RoomName'] ?? '');
        $events[] = [
            // Keep title short-ish; UI will render properly anyway.
            'title'      => 'Booking',
            'start'      => $row['StartDateTime'],
            'end'        => $row['EndDateTime'],
            'bookingID'  => (int)$row['BookingID'],
            'color'      => $statusColor,
            'status'     => $row['Status'],
            'room'       => $room,
            'occasion'   => (string)($row['Occasion'] ?? ''),
            'guestNames' => (string)($row['GuestNames'] ?? ''),
            'guestCount' => (int)($row['GuestCount'] ?? 0),
        ];
    }

    echo json_encode($events, JSON_UNESCAPED_SLASHES);
    exit;
}

/* ────────────────────────────────────────────────────────────────
   Global booking search endpoint (dropdown)
   guest_manager.php?booking_search=1&q=...
   Searches ALL bookings by guest name OR occasion.
   NOTE: Requires MySQL 8+ for JSON_TABLE.
   ──────────────────────────────────────────────────────────────── */
if (isset($_GET['booking_search'])) {
    header('Content-Type: application/json; charset=utf-8');

    $needle = isset($_GET['q']) ? trim($_GET['q']) : '';
    if ($needle === '') {
        echo json_encode([]);
        exit;
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
        LIMIT $limit
    ";

    $stmt = $link->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
        exit;
    }
    $stmt->bind_param('ss', $needle, $needle);
    $stmt->execute();
    $res = $stmt->get_result();

    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[] = [
            'bookingID'  => (int)$row['BookingID'],
            'start'      => $row['StartDateTime'],
            'end'        => $row['EndDateTime'],
            'status'     => $row['Status'],
            'room'       => (string)($row['RoomName'] ?? ''),
            'occasion'   => (string)($row['Occasion'] ?? ''),
            'guests'     => (string)($row['GuestNames'] ?? ''),
            'guestCount' => (int)($row['GuestCount'] ?? 0),
        ];
    }

    echo json_encode($out, JSON_UNESCAPED_SLASHES);
    exit;
}

/* ── prefetch rooms (names for labels) ────────────────────────── */
$rooms = [];
$roomRes = $link->query("SELECT RoomID, Name FROM HouseLocations ORDER BY Name ASC");
while ($r = $roomRes->fetch_assoc()) { $rooms[(int)$r['RoomID']] = $r['Name']; }

/* ────────────────────────────────────────────────────────────────
   Upcoming rail: filtering + pagination in SQL
   - excludes Cancelled
   - supports guest-name search (q) (rail only)
   ──────────────────────────────────────────────────────────────── */
$now = new DateTime();
$nowStr = $now->format('Y-m-d H:i:s');

$perPage = 7;
$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset  = ($page - 1) * $perPage;

$where = " WHERE b.EndDateTime >= ? AND (b.Status IS NULL OR b.Status <> 'Cancelled') ";
$params = [$nowStr];
$types  = "s";

if ($q !== '') {
    $where .= "
        AND EXISTS (
            SELECT 1
            FROM JSON_TABLE(b.GuestsJSON, '$.guests[*]' COLUMNS (guest_id INT PATH '$')) jt2
            JOIN Contacts c2 ON c2.ContactID = jt2.guest_id
            WHERE c2.KnownAs LIKE CONCAT('%', ?, '%')
        )
    ";
    $params[] = $q;
    $types   .= "s";
}

/* total count */
$countSql = "SELECT COUNT(*) AS cnt FROM Bookings b " . $where;
$countStmt = $link->prepare($countSql);
$total = 0;
if ($countStmt) {
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $countRes = $countStmt->get_result();
    $totalRow = $countRes->fetch_assoc();
    $total    = (int)($totalRow['cnt'] ?? 0);
}

/* page rows */
$listSql = "
    SELECT b.*, b.Occasion, r.Name AS RoomName
    FROM Bookings b
    LEFT JOIN HouseLocations r ON b.RoomID = r.RoomID
    $where
    ORDER BY b.StartDateTime ASC
    LIMIT ? OFFSET ?
";
$listStmt = $link->prepare($listSql);

$display  = [];
if ($listStmt) {
    $types2  = $types . "ii";
    $params2 = array_merge($params, [$perPage, $offset]);
    $listStmt->bind_param($types2, ...$params2);
    $listStmt->execute();
    $listRes = $listStmt->get_result();

    // Hydrate guests for displayed rows only (7/page).
    while ($row = $listRes->fetch_assoc()) {
        $decoded  = json_decode($row['GuestsJSON'] ?? '[]', true) ?: [];
        $guestIDs = array_map('intval', array_filter(($decoded['guests'] ?? []), static fn($x)=>$x));

        if (!$guestIDs) continue;

        $ids = implode(',', $guestIDs);
        $guestRes = $link->query(
            "SELECT ContactID, KnownAs, PhotoURL
             FROM Contacts
             WHERE ContactID IN ($ids)"
        );

        $guests = [];
        while ($g = $guestRes->fetch_assoc()) { $guests[] = $g; }
        $row['guests'] = $guests;

        $row['startDT'] = new DateTime($row['StartDateTime']);
        $row['endDT']   = new DateTime($row['EndDateTime']);
        $row['nights']  = max(1, (int)$row['startDT']->diff($row['endDT'])->format('%a'));

        $display[] = $row;
    }
}

$totalPages = max(1, (int)ceil(max(0, $total) / $perPage));
$page       = min($page, $totalPages);
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Guest Manager</title>

  <base target="_self">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body      { background:#f6f7fb; }
    .card     { border:0; border-radius:16px; }
    .shadow-soft { box-shadow: 0 1px 2px rgba(16,24,40,.04), 0 8px 24px rgba(16,24,40,.08); }
    .avatar   { width:42px;height:42px;border-radius:50%;object-fit:cover;background:#e9ecef; }
    .avatar-group { display:flex; }
    .avatar-group .avatar:not(:first-child){ margin-left:-10px;border:2px solid #fff; }
    .avatar-more{ width:42px;height:42px;border-radius:50%;background:#dee2e6;
      display:flex;align-items:center;justify-content:center;font-weight:600;color:#495057;margin-left:-10px;border:2px solid #fff;}
    .chip { display:inline-flex; align-items:center; gap:.35rem; padding:.25rem .6rem; border-radius:999px; font-size:.75rem; }
    .chip-room { background:#eef2ff; color:#3730a3; }
    .chip-occasion { background:#e7f0ff; color:#0b57d0; }
    .chip-status-confirmed { background:#e9f7ef; color:#1e7e34; }
    .chip-status-tentative { background:#fff4e5; color:#9a6700; }
    .chip-status-cancelled { background:#fdecea; color:#a71d2a; }
    .progress-thin { height: 4px; }

    /* Calendar tweaks */
    .fc .fc-toolbar-title { font-size: 1rem; }
    .fc-event a { text-decoration: none; color: inherit; }
    .cal-legend { display:flex; gap:12px; flex-wrap:wrap; align-items:center; }
    .cal-legend .item { display:inline-flex; align-items:center; gap:6px; font-size:.85rem; color:#6c757d; }
    .cal-legend .dot { width:10px; height:10px; border-radius:999px; display:inline-block; }

    /* Strong truncation to prevent overflow in month cells */
    .fc .fc-daygrid-event .fc-event-title,
    .fc .fc-daygrid-event .fc-event-title-container,
    .fc .fc-daygrid-event .fc-event-time {
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      max-width: 100%;
      display: block;
    }
    .event-line { display:block; max-width:100%; }
    .event-title { font-weight:600; font-size:.85rem; line-height:1.1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .event-meta  { font-size:.75rem; opacity:.9; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

    /* Top search dropdown */
    .search-wrap { position: relative; }
    .search-dd {
      position: absolute; top: 100%; left: 0; right: 0;
      background: #fff; border: 1px solid rgba(0,0,0,.12);
      border-radius: 12px; box-shadow: 0 8px 24px rgba(16,24,40,.12);
      z-index: 1050; overflow: hidden; display: none;
      max-height: 360px; overflow-y: auto;
    }
    .search-dd .item {
      padding: .65rem .75rem; cursor: pointer;
      border-bottom: 1px solid rgba(0,0,0,.06);
    }
    .search-dd .item:last-child { border-bottom: 0; }
    .search-dd .item:hover { background: #f6f7fb; }
    .search-dd .title { font-weight: 600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .search-dd .meta { font-size: .85rem; color: #6c757d; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .search-dd .empty { padding: .75rem; color: #6c757d; }
  </style>
</head>
<body>

<?php include '../header.php'; ?>
<?php include 'subheader.php'; ?>

<div class="container py-4">

  <!-- Top bar: global booking search (dropdown) -->
  <div class="card shadow-soft mb-3">
    <div class="card-body">
      <div class="d-flex flex-wrap align-items-center gap-2">
        <div class="flex-grow-1 search-wrap" style="min-width: 280px;">
          <label class="form-label mb-1 small text-muted" for="globalSearch">Search all bookings (guest name or occasion)</label>
          <input type="search" class="form-control" id="globalSearch" placeholder="Type a guest name or occasion…">
          <div class="search-dd" id="globalSearchDd" role="listbox" aria-label="Search results"></div>
        </div>

        <!-- actions -->
        <div class="d-flex flex-wrap gap-2 align-self-end">
          <a href="add_booking.php" class="btn btn-success"><span class="me-1">+</span> Add Booking</a>
          <a href="room_settings.php" class="btn btn-outline-secondary">Room Settings</a>
          <a href="reports.php" class="btn btn-outline-primary">Reports</a>
        </div>
      </div>
    </div>
  </div>

  <!-- calendar + upcoming -->
  <div class="row g-4">
    <div class="col-lg-8">
      <div class="card shadow-soft">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="card-title mb-0">Booking Calendar</h5>
            <div class="btn-group" role="group" aria-label="Calendar navigation">
              <button class="btn btn-outline-primary btn-sm" id="prevBtn"  type="button">‹ Prev</button>
              <button class="btn btn-outline-secondary btn-sm" id="todayBtn" type="button">Today</button>
              <button class="btn btn-outline-primary btn-sm" id="nextBtn"  type="button">Next ›</button>
            </div>
            <div class="btn-group" role="group" aria-label="Calendar view toggle">
              <button class="btn btn-outline-primary btn-sm" id="viewMonth" type="button" aria-pressed="true">Month</button>
              <button class="btn btn-outline-primary btn-sm" id="viewList"  type="button" aria-pressed="false">List</button>
            </div>
          </div>

          <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mt-2">
            <div class="text-muted small" id="calLabel" aria-live="polite"></div>
            <div class="cal-legend" aria-label="Status legend">
              <span class="item"><span class="dot" style="background:#28a745"></span> Confirmed</span>
              <span class="item"><span class="dot" style="background:#f0ad4e"></span> Tentative</span>
              <span class="item"><span class="dot" style="background:#dc3545"></span> Cancelled</span>
              <span class="item"><span class="dot" style="background:#6c757d"></span> Other</span>
            </div>
          </div>

          <div class="mt-2" id="calendar" aria-label="Bookings calendar"></div>
        </div>
      </div>
    </div>

    <div class="col-lg-4" id="upcoming">
      <div class="card shadow-soft h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">Upcoming Visits</h5>
            <span class="badge bg-primary" aria-label="Total upcoming"><?= (int)$total ?></span>
          </div>

          <!-- Upcoming guest search (rail) -->
          <form class="mt-3" method="get" action="#upcoming" role="search" aria-label="Search guests">
            <div class="input-group">
              <span class="input-group-text" id="searchLabel">🔎</span>
              <input type="search" class="form-control" name="q" aria-labelledby="searchLabel" placeholder="Filter upcoming by guest name…" value="<?= h($q) ?>">
              <button class="btn btn-primary" type="submit">Search</button>
              <?php if ($q !== ''): ?>
                <a class="btn btn-outline-secondary" href="<?= h($BASE) ?>/guest_manager.php#upcoming">Clear</a>
              <?php endif; ?>
            </div>
          </form>

          <?php if (!$display): ?>
            <div class="text-center py-4">
              <p class="text-muted mb-0">No upcoming bookings<?= $q!=='' ? ' for “'.h($q).'”' : '' ?>.</p>
            </div>
          <?php else: ?>

            <?php foreach ($display as $visit): ?>
              <?php
                $totalGuests = count($visit['guests']);
                $show        = array_slice($visit['guests'], 0, 5);

                // "Name, Name and N others" for long lists
                $names = array_values(array_filter(array_column($visit['guests'], 'KnownAs')));
                if (count($names) <= 2) {
                    $headline = implode(', ', $names);
                } else {
                    $headline = $names[0] . ', ' . $names[1] . ' and ' . (count($names) - 2) . ' others';
                }

                $statusClass = match ($visit['Status']) {
                  'Confirmed' => 'chip-status-confirmed',
                  'Tentative' => 'chip-status-tentative',
                  'Cancelled' => 'chip-status-cancelled',
                  default     => 'bg-secondary text-white',
                };

                $occasion = trim((string)($visit['Occasion'] ?? ''));

                $nights  = (int)$visit['nights'];
                $startTs = $visit['startDT']->getTimestamp();
                $endTs   = $visit['endDT']->getTimestamp();
                $nowTs   = time();

                $isFuture     = $startTs > $nowTs;
                $isInProgress = ($startTs <= $nowTs) && ($nowTs < $endTs);

                $pct = 0;
                if ($isInProgress) {
                    $totalSeconds = max(1, $endTs - $startTs);
                    $elapsedSec   = max(0, min($totalSeconds, $nowTs - $startTs));
                    $pct          = (int)round(($elapsedSec / $totalSeconds) * 100);
                }

                $daysUntil = 0;
                if ($isFuture) {
                    $daysUntil = (int)max(0, ceil(($startTs - $nowTs) / 86400));
                }

                $viewUrl = $BASE . '/view_booking.php?BookingID=' . (int)$visit['BookingID'];
                if (!empty($_GET['magic_key'])) {
                  $viewUrl .= '&magic_key=' . urlencode($_GET['magic_key']);
                }
              ?>
              <a class="text-decoration-none text-reset" href="<?= h($viewUrl) ?>">
                <div class="card mb-3">
                  <div class="card-body d-flex align-items-start gap-3 p-3">
                    <div class="avatar-group mt-1" aria-label="Guest avatars">
                      <?php foreach ($show as $g): ?>
                        <img loading="lazy"
                             src="<?= h($g['PhotoURL'] ?: $defaultAvatar) ?>"
                             class="avatar" alt="<?= h($g['KnownAs']) ?> photo"
                             title="<?= h($g['KnownAs']) ?>">
                      <?php endforeach; ?>
                      <?php if ($totalGuests > 5): ?>
                        <div class="avatar-more" aria-label="More guests">+<?= (int)($totalGuests-5) ?></div>
                      <?php endif; ?>
                    </div>

                    <div class="flex-grow-1">
                      <div class="d-flex flex-wrap align-items-center gap-2">
                        <div class="fw-semibold"><?= h($headline) ?></div>
                        <?php if (!empty($visit['RoomName'])): ?>
                          <span class="chip chip-room" aria-label="Room"><?= h($visit['RoomName']) ?></span>
                        <?php endif; ?>
                        <?php if ($occasion !== ''): ?>
                          <span class="chip chip-occasion" aria-label="Occasion"><?= h($occasion) ?></span>
                        <?php endif; ?>
                        <span class="chip <?= h($statusClass) ?>" aria-label="Status"><?= h($visit['Status']) ?></span>
                      </div>

                      <div class="small text-muted mt-1">
                        <?= h($visit['startDT']->format('D, M j · H:i')) ?> →
                        <?= h($visit['endDT']->format('D, M j · H:i')) ?>
                        <span class="ms-1">· <?= $nights ?> night<?= $nights===1?'':'s' ?></span>
                      </div>

                      <div class="d-flex align-items-center gap-2 mt-2">
                        <div class="progress flex-grow-1 progress-thin" role="progressbar" aria-label="Stay progress" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
                          <div class="progress-bar" style="width: <?= $pct ?>%"></div>
                        </div>
                        <small class="text-muted">
                          <?php if ($isInProgress): ?>
                            In progress · <?= $pct ?>%
                          <?php else: ?>
                            Starts in <?= $daysUntil ?> day<?= $daysUntil===1?'':'s' ?>
                          <?php endif; ?>
                        </small>
                      </div>
                    </div>

                  </div>
                </div>
              </a>
            <?php endforeach; ?>

            <!-- pagination controls -->
            <?php if ($totalPages > 1): ?>
              <nav aria-label="Upcoming pagination">
                <ul class="pagination justify-content-center pagination-sm mt-2">
                  <li class="page-item <?= $page==1?'disabled':'' ?>">
                    <a class="page-link"
                       href="<?= h($BASE) ?>/guest_manager.php?<?= http_build_query(array_merge($_GET,['page'=>max(1,$page-1)])) ?>#upcoming"
                       tabindex="-1" aria-label="Previous">Previous</a>
                  </li>

                  <?php for ($p=1;$p<=$totalPages;$p++): ?>
                    <li class="page-item <?= $p==$page?'active':'' ?>">
                      <a class="page-link"
                         href="<?= h($BASE) ?>/guest_manager.php?<?= http_build_query(array_merge($_GET,['page'=>$p])) ?>#upcoming"
                         aria-label="Page <?= $p ?>"><?= $p ?></a>
                    </li>
                  <?php endfor; ?>

                  <li class="page-item <?= $page==$totalPages?'disabled':'' ?>">
                    <a class="page-link"
                       href="<?= h($BASE) ?>/guest_manager.php?<?= http_build_query(array_merge($_GET,['page'=>min($totalPages,$page+1)])) ?>#upcoming"
                       aria-label="Next">Next</a>
                  </li>
                </ul>
              </nav>
            <?php endif; ?>

          <?php endif; ?>

        </div>
      </div>
    </div>
  </div>

</div>

<?php include '../footer.php'; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.8/index.global.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const BASE      = <?= json_encode($BASE) ?>;
  const urlParams = new URLSearchParams(window.location.search);
  const magicKey  = urlParams.get('magic_key');

  function buildViewUrl(bookingID) {
    let url = BASE + '/view_booking.php?BookingID=' + encodeURIComponent(bookingID ?? '');
    if (magicKey) url += '&magic_key=' + encodeURIComponent(magicKey);
    return url;
  }

  function guestSummaryFromCsv(csv, guestCount) {
    const names = (csv || '').split(',').map(s => s.trim()).filter(Boolean);
    const cnt = Number.isFinite(guestCount) ? guestCount : names.length;
    if (names.length === 0) return 'Guests';
    if (cnt <= 2 || names.length <= 2) return names.slice(0,2).join(', ');
    return `${names[0]}, ${names[1]} and ${Math.max(0, cnt - 2)} others`;
  }

  function formatOccasion(occ) {
    const s = (occ || '').trim();
    return s;
  }

  // Calendar
  const calendarEl = document.getElementById('calendar');
  const labelEl    = document.getElementById('calLabel');

  const calendar = new FullCalendar.Calendar(calendarEl, {
    height: 'auto',
    selectable: false,
    expandRows: true,
    initialView: 'dayGridMonth',
    headerToolbar: false,
    firstDay: 1,
    nowIndicator: true,
    dayMaxEventRows: 3,

    events: async (fetchInfo, successCallback, failureCallback) => {
      try {
        const qs = new URLSearchParams();
        qs.set('calendar_feed', '1');
        qs.set('start', fetchInfo.startStr);
        qs.set('end', fetchInfo.endStr);
        if (magicKey) qs.set('magic_key', magicKey);

        const resp = await fetch(BASE + '/guest_manager.php?' + qs.toString(), { credentials: 'same-origin' });
        if (!resp.ok) throw new Error('Failed to load events');
        const raw = await resp.json();

        const events = (Array.isArray(raw) ? raw : []).map(ev => ({
          title: 'Booking',
          start: ev.start,
          end:   ev.end,
          url:   buildViewUrl(ev.bookingID),
          extendedProps: {
            bookingID: ev.bookingID,
            status: ev.status,
            room: ev.room,
            color: ev.color,
            occasion: ev.occasion,
            guestNames: ev.guestNames,
            guestCount: ev.guestCount
          },
          backgroundColor: ev.color,
          borderColor: ev.color
        }));

        successCallback(events);
      } catch (e) {
        failureCallback(e);
      }
    },

    // Render: short guests + occasion (both truncated) to prevent overflow
    eventContent: (arg) => {
      const ep = arg.event.extendedProps || {};
      const guestsShort = guestSummaryFromCsv(ep.guestNames || '', Number(ep.guestCount || 0));
      const occ = formatOccasion(ep.occasion || '');
      const room = (ep.room || '').trim();

      const wrap = document.createElement('div');
      wrap.className = 'event-line';

      const title = document.createElement('div');
      title.className = 'event-title';
      title.textContent = guestsShort;

      const meta = document.createElement('div');
      meta.className = 'event-meta';
      meta.textContent = [occ, room].filter(Boolean).join(' · ');

      wrap.appendChild(title);
      if (meta.textContent) wrap.appendChild(meta);

      return { domNodes: [wrap] };
    },

    eventDidMount: (info) => {
      const status = info.event.extendedProps.status;
      if (status === 'Cancelled') info.el.style.opacity = '0.55';
    },

    eventClick: null,

    datesSet: (arg) => {
      try {
        const start = arg.start;
        const opts  = { month: 'long', year: 'numeric' };
        labelEl.textContent = start.toLocaleDateString(undefined, opts);
      } catch {}
    }
  });

  calendar.render();

  const monthBtn = document.getElementById('viewMonth');
  const listBtn  = document.getElementById('viewList');

  document.getElementById('prevBtn').addEventListener('click',  ()=> calendar.prev());
  document.getElementById('todayBtn').addEventListener('click', ()=> calendar.today());
  document.getElementById('nextBtn').addEventListener('click',  ()=> calendar.next());

  monthBtn.addEventListener('click', () => {
    calendar.changeView('dayGridMonth');
    monthBtn.setAttribute('aria-pressed', 'true');
    listBtn.setAttribute('aria-pressed', 'false');
  });

  listBtn.addEventListener('click', () => {
    calendar.changeView('listMonth');
    listBtn.setAttribute('aria-pressed', 'true');
    monthBtn.setAttribute('aria-pressed', 'false');
  });

  // Global booking search dropdown (guest name OR occasion)
  const input = document.getElementById('globalSearch');
  const dd    = document.getElementById('globalSearchDd');

  let debounceTimer = null;
  let lastQuery = '';

  function closeDd() {
    dd.style.display = 'none';
    dd.innerHTML = '';
  }
  function openDd() { dd.style.display = 'block'; }

  function formatRange(startStr, endStr) {
    try {
      const s = new Date(startStr);
      const e = new Date(endStr);
      const opts = { weekday: 'short', month: 'short', day: 'numeric' };
      const tOpt = { hour: '2-digit', minute: '2-digit' };
      return `${s.toLocaleDateString(undefined, opts)} ${s.toLocaleTimeString(undefined, tOpt)} → ${e.toLocaleDateString(undefined, opts)} ${e.toLocaleTimeString(undefined, tOpt)}`;
    } catch {
      return `${startStr} → ${endStr}`;
    }
  }

  function buildGuestsSummary(guestsCsv, guestCount) {
    const names = (guestsCsv || '').split(',').map(s => s.trim()).filter(Boolean);
    const cnt = Number.isFinite(guestCount) ? guestCount : names.length;
    if (names.length <= 2) return names.join(', ') || 'Guests';
    return `${names[0]}, ${names[1]} and ${Math.max(0, cnt - 2)} others`;
  }

  async function searchBookings(query) {
    const qs = new URLSearchParams();
    qs.set('booking_search', '1');
    qs.set('q', query);
    if (magicKey) qs.set('magic_key', magicKey);

    const resp = await fetch(BASE + '/guest_manager.php?' + qs.toString(), { credentials: 'same-origin' });
    if (!resp.ok) throw new Error('Search failed');
    return await resp.json();
  }

  function renderResults(items) {
    dd.innerHTML = '';
    if (!Array.isArray(items) || items.length === 0) {
      dd.innerHTML = `<div class="empty">No bookings found.</div>`;
      openDd();
      return;
    }

    const frag = document.createDocumentFragment();
    items.forEach(it => {
      const div = document.createElement('div');
      div.className = 'item';
      div.setAttribute('role', 'option');

      const guestsShort = buildGuestsSummary(it.guests, Number(it.guestCount || 0));
      const titleParts = [guestsShort, (it.room || '').trim()].filter(Boolean);

      const title = document.createElement('div');
      title.className = 'title';
      title.textContent = titleParts.join(' • ');

      const metaParts = [
        (it.occasion || '').trim() ? `Occasion: ${(it.occasion || '').trim()}` : '',
        formatRange(it.start, it.end),
        it.status || 'Status'
      ].filter(Boolean);

      const meta = document.createElement('div');
      meta.className = 'meta';
      meta.textContent = metaParts.join(' · ');

      div.appendChild(title);
      div.appendChild(meta);

      div.addEventListener('click', () => {
        window.location.href = buildViewUrl(it.bookingID);
      });

      frag.appendChild(div);
    });

    dd.appendChild(frag);
    openDd();
  }

  input.addEventListener('input', () => {
    const query = (input.value || '').trim();
    if (debounceTimer) clearTimeout(debounceTimer);

    if (query.length < 2) {
      lastQuery = '';
      closeDd();
      return;
    }

    debounceTimer = setTimeout(async () => {
      if (query === lastQuery) return;
      lastQuery = query;

      try {
        const results = await searchBookings(query);
        renderResults(results);
      } catch {
        dd.innerHTML = `<div class="empty">Search unavailable.</div>`;
        openDd();
      }
    }, 200);
  });

  document.addEventListener('click', (e) => {
    if (!dd.contains(e.target) && e.target !== input) closeDd();
  });

  input.addEventListener('focus', () => {
    if (dd.innerHTML.trim() !== '') openDd();
  });
});
</script>

<script>
// Preserve magic_key propagation for forms (kept).
(function() {
  const params = new URLSearchParams(window.location.search);
  const magicKey = params.get("magic_key");
  if (!magicKey) return;

  document.addEventListener("DOMContentLoaded", () => {
    document.querySelectorAll("form").forEach(form => {
      if (!form.querySelector("input[name='magic_key']")) {
        const hidden = document.createElement("input");
        hidden.type  = "hidden";
        hidden.name  = "magic_key";
        hidden.value = magicKey;
        form.appendChild(hidden);
      }
    });
  });
})();
</script>

</body>
</html>
