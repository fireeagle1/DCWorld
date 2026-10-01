<?php
/*  guest_manager.php — bookings dashboard (direct-open, month nav)
    -----------------------------------------------------------------
    This file is now a *view*. Its JSON endpoints (calendar feed and
    global booking search) live in guest_feeds.php, and shared logic
    (status colours, guest-list summaries, escaping) lives in
    guest_helpers.php. Presentation is in assets/guest_manager.css and
    behaviour in assets/guest_manager.js.

    Features:
    - Top bar: global booking search (guest name OR occasion) dropdown
    - Calendar: range-fed, truncated, with a loading overlay
    - Upcoming rail: SQL pagination + guest-name filter, excludes Cancelled
    - Upcoming cards: long guest lists show "A, B and N others"
    -----------------------------------------------------------------*/

declare(strict_types=1);

require '../auth.php';                 // session + config.php ($link) + login gate
require __DIR__ . '/guest_helpers.php';

/* ── absolute base URL (directory of this script) ─────────────── */
$https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$scheme = $https ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$BASE   = $scheme . '://' . $host . ($dir ?: '');   // e.g. https://site.tld/home

/* ── default avatar ────────────────────────────────────────────── */
$defaultAvatar = 'https://assets.dcworld.uk/images/contacts/Black%20and%20white%20organic%20farmhouse%20mountain%20landscape%20hand%20drawn%20logo.png';

/* ── inputs (GET) ──────────────────────────────────────────────── */
$q = isset($_GET['q']) ? trim($_GET['q']) : ''; // upcoming guest-name search (rail only)

/* ── magic_key passthrough (optional deep-link token) ─────────── */
$magicKey = isset($_GET['magic_key']) ? (string)$_GET['magic_key'] : '';

/* ────────────────────────────────────────────────────────────────
   Upcoming rail: filtering + pagination in SQL
   - excludes Cancelled
   - supports guest-name search (q)
   ──────────────────────────────────────────────────────────────── */
$now    = new DateTime();
$nowStr = $now->format('Y-m-d H:i:s');

$perPage = 7;
$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset  = ($page - 1) * $perPage;

$where  = " WHERE b.EndDateTime >= ? AND (b.Status IS NULL OR b.Status <> 'Cancelled') ";
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

/* total count — counts the same rows the rail renders (guestless included) */
$total     = 0;
$countStmt = $link->prepare("SELECT COUNT(*) AS cnt FROM Bookings b " . $where);
if ($countStmt) {
    $countStmt->bind_param($types, ...$params);
    $countStmt->execute();
    $total = (int)($countStmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $countStmt->close();
}

/* page rows */
$listSql = "
    SELECT b.*, r.Name AS RoomName
    FROM Bookings b
    LEFT JOIN HouseLocations r ON b.RoomID = r.RoomID
    $where
    ORDER BY b.StartDateTime ASC
    LIMIT ? OFFSET ?
";

$pageRows = [];
$listStmt = $link->prepare($listSql);
if ($listStmt) {
    $types2  = $types . "ii";
    $params2 = array_merge($params, [$perPage, $offset]);
    $listStmt->bind_param($types2, ...$params2);
    $listStmt->execute();
    $listRes = $listStmt->get_result();
    while ($row = $listRes->fetch_assoc()) {
        $pageRows[] = $row;
    }
    $listStmt->close();
}

/* ── batch-fetch guests for the displayed rows (single query) ──── */
$allGuestIDs = [];
foreach ($pageRows as $row) {
    foreach (gm_guest_ids($row['GuestsJSON'] ?? null) as $gid) {
        $allGuestIDs[$gid] = true;
    }
}

$contactsById = [];
if ($allGuestIDs) {
    $in       = implode(',', array_map('intval', array_keys($allGuestIDs)));
    $guestRes = $link->query(
        "SELECT ContactID, KnownAs, PhotoURL FROM Contacts WHERE ContactID IN ($in)"
    );
    if ($guestRes) {
        while ($g = $guestRes->fetch_assoc()) {
            $contactsById[(int)$g['ContactID']] = $g;
        }
    }
}

/* ── hydrate display rows (guestless bookings are kept) ────────── */
$display = [];
foreach ($pageRows as $row) {
    $guests = [];
    foreach (gm_guest_ids($row['GuestsJSON'] ?? null) as $gid) {
        $guests[] = $contactsById[$gid] ?? [
            'ContactID' => $gid,
            'KnownAs'   => 'Guest',
            'PhotoURL'  => null,
        ];
    }
    $row['guests']  = $guests;
    $row['startDT'] = new DateTime($row['StartDateTime']);
    $row['endDT']   = new DateTime($row['EndDateTime']);
    $row['nights']  = max(1, (int)$row['startDT']->diff($row['endDT'])->format('%a'));
    $display[]      = $row;
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
  <link href="<?= h($BASE) ?>/assets/guest_manager.css" rel="stylesheet">
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
          <input type="search" class="form-control" id="globalSearch"
                 placeholder="Type a guest name or occasion…"
                 role="combobox" aria-expanded="false" aria-controls="globalSearchDd"
                 aria-autocomplete="list" autocomplete="off">
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
              <span class="item"><span class="dot" style="background:#6f42c1"></span> Pencilled</span>
              <span class="item"><span class="dot" style="background:#dc3545"></span> Cancelled</span>
              <span class="item"><span class="dot" style="background:#6c757d"></span> Other</span>
            </div>
          </div>

          <div class="mt-2 calendar-wrap">
            <div id="calendar" aria-label="Bookings calendar"></div>
            <div class="cal-loading" aria-hidden="true">
              <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Loading…</span>
              </div>
            </div>
          </div>
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
              <p class="text-muted mb-0">No upcoming bookings<?= $q !== '' ? ' for “' . h($q) . '”' : '' ?>.</p>
            </div>
          <?php else: ?>

            <?php foreach ($display as $visit): ?>
              <?php
                $totalGuests = count($visit['guests']);
                $show        = array_slice($visit['guests'], 0, 5);

                $names    = array_values(array_filter(array_column($visit['guests'], 'KnownAs')));
                $headline = gm_guest_summary($names, $totalGuests);

                $statusClass = gm_status_chip_class($visit['Status'] ?? null);
                $statusLabel = gm_status_label($visit['Status'] ?? null);

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

                $daysUntil = $isFuture ? (int)max(0, ceil(($startTs - $nowTs) / 86400)) : 0;

                $viewUrl = $BASE . '/view_booking.php?BookingID=' . (int)$visit['BookingID'];
                if ($magicKey !== '') {
                    $viewUrl .= '&magic_key=' . urlencode($magicKey);
                }
              ?>
              <a class="text-decoration-none text-reset" href="<?= h($viewUrl) ?>">
                <div class="card mb-3">
                  <div class="card-body d-flex align-items-start gap-3 p-3">
                    <div class="avatar-group mt-1" aria-label="Guest avatars">
                      <?php if ($show): ?>
                        <?php foreach ($show as $g): ?>
                          <img loading="lazy"
                               src="<?= h($g['PhotoURL'] ?: $defaultAvatar) ?>"
                               class="avatar" alt="<?= h($g['KnownAs'] ?? 'Guest') ?> photo"
                               title="<?= h($g['KnownAs'] ?? 'Guest') ?>">
                        <?php endforeach; ?>
                      <?php else: ?>
                        <img loading="lazy" src="<?= h($defaultAvatar) ?>"
                             class="avatar" alt="Guest booking" title="Guest booking">
                      <?php endif; ?>
                      <?php if ($totalGuests > 5): ?>
                        <div class="avatar-more" aria-label="More guests">+<?= (int)($totalGuests - 5) ?></div>
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
                        <span class="chip <?= h($statusClass) ?>" aria-label="Status"><?= h($statusLabel) ?></span>
                      </div>

                      <div class="small text-muted mt-1">
                        <?= h($visit['startDT']->format('D, M j · H:i')) ?> →
                        <?= h($visit['endDT']->format('D, M j · H:i')) ?>
                        <span class="ms-1">· <?= $nights ?> night<?= $nights === 1 ? '' : 's' ?></span>
                      </div>

                      <div class="d-flex align-items-center gap-2 mt-2">
                        <div class="progress flex-grow-1 progress-thin" role="progressbar" aria-label="Stay progress" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100">
                          <div class="progress-bar" style="width: <?= $pct ?>%"></div>
                        </div>
                        <small class="text-muted">
                          <?php if ($isInProgress): ?>
                            In progress · <?= $pct ?>%
                          <?php else: ?>
                            Starts in <?= $daysUntil ?> day<?= $daysUntil === 1 ? '' : 's' ?>
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
                  <li class="page-item <?= $page == 1 ? 'disabled' : '' ?>">
                    <a class="page-link"
                       href="<?= h($BASE) ?>/guest_manager.php?<?= h(http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)]))) ?>#upcoming"
                       tabindex="-1" aria-label="Previous">Previous</a>
                  </li>

                  <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <li class="page-item <?= $p == $page ? 'active' : '' ?>">
                      <a class="page-link"
                         href="<?= h($BASE) ?>/guest_manager.php?<?= h(http_build_query(array_merge($_GET, ['page' => $p]))) ?>#upcoming"
                         aria-label="Page <?= $p ?>"><?= $p ?></a>
                    </li>
                  <?php endfor; ?>

                  <li class="page-item <?= $page == $totalPages ? 'disabled' : '' ?>">
                    <a class="page-link"
                       href="<?= h($BASE) ?>/guest_manager.php?<?= h(http_build_query(array_merge($_GET, ['page' => min($totalPages, $page + 1)]))) ?>#upcoming"
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
  window.GM_CONFIG = {
    base:     <?= json_encode($BASE, JSON_UNESCAPED_SLASHES) ?>,
    feedsUrl: <?= json_encode($BASE . '/guest_feeds.php', JSON_UNESCAPED_SLASHES) ?>,
    magicKey: <?= json_encode($magicKey !== '' ? $magicKey : null) ?>
  };
</script>
<script src="<?= h($BASE) ?>/assets/guest_manager.js" defer></script>

</body>
</html>
