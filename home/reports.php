<?php
/* reports.php — Booking & occupancy reporting dashboard
   ------------------------------------------------------
   Update: default range is now LAST 6 MONTHS (rolling) ending today.
*/

require '../auth.php';

/* ── helpers ───────────────────────────────────────────────────── */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function dt(?string $s): ?DateTime { return $s ? new DateTime($s) : null; }
function clamp01(float $x): float { return max(0.0, min(1.0, $x)); }

/* ── absolute base URL (directory of this script) ─────────────── */
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$scheme = $https ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$dir    = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$BASE   = $scheme . '://' . $host . ($dir ? $dir : '');

/* ── date range (GET) ────────────────────────────────────────────
   Default: last 6 months ending today.
*/
$today = new DateTime('today');
$defaultTo   = (clone $today)->setTime(23,59,59);
$defaultFrom = (clone $today)->modify('-6 months')->setTime(0,0,0);

try {
  $from = isset($_GET['from']) && $_GET['from'] !== '' ? new DateTime($_GET['from'] . ' 00:00:00') : $defaultFrom;
  $to   = isset($_GET['to'])   && $_GET['to']   !== '' ? new DateTime($_GET['to']   . ' 23:59:59') : $defaultTo;
} catch (Exception $e) {
  $from = $defaultFrom;
  $to   = $defaultTo;
}
if ($to < $from) { $tmp=$from; $from=$to; $to=$tmp; }

$fromStr = $from->format('Y-m-d H:i:s');
$toStr   = $to->format('Y-m-d H:i:s');

/* ── rooms ─────────────────────────────────────────────────────── */
$rooms = [];
$roomRes = $link->query("SELECT RoomID, Name FROM HouseLocations ORDER BY Name ASC");
while ($r = $roomRes->fetch_assoc()) {
  $rooms[(int)$r['RoomID']] = (string)$r['Name'];
}
$roomIDs = array_keys($rooms);

/* ── booking rows overlapping range (for occupancy / trends) ────── */
$bookings = [];
$occSql = "
  SELECT
    b.BookingID,
    b.RoomID,
    b.StartDateTime,
    b.EndDateTime,
    b.Status,
    b.Occasion,
    IFNULL(JSON_LENGTH(JSON_EXTRACT(b.GuestsJSON,'$.guests')), 0) AS GuestCount
  FROM Bookings b
  WHERE b.StartDateTime < ?
    AND b.EndDateTime   > ?
";
$st = $link->prepare($occSql);
$st->bind_param('ss', $toStr, $fromStr);
$st->execute();
$rs = $st->get_result();
while ($row = $rs->fetch_assoc()) {
  $row['RoomID'] = (int)$row['RoomID'];
  $row['GuestCount'] = (int)$row['GuestCount'];
  $row['StartDT'] = dt($row['StartDateTime']);
  $row['EndDT']   = dt($row['EndDateTime']);
  $bookings[] = $row;
}
$st->close();

/* ── aggregates by room: bookings count + guests sum ────────────── */
$bookingsByRoom = array_fill_keys($roomIDs, 0);
$guestsByRoom   = array_fill_keys($roomIDs, 0);

$aggSql = "
  SELECT
    b.RoomID,
    COUNT(*) AS BookingCount,
    SUM(IFNULL(JSON_LENGTH(JSON_EXTRACT(b.GuestsJSON,'$.guests')), 0)) AS GuestSum
  FROM Bookings b
  WHERE b.StartDateTime < ?
    AND b.EndDateTime   > ?
  GROUP BY b.RoomID
";
$st = $link->prepare($aggSql);
$st->bind_param('ss', $toStr, $fromStr);
$st->execute();
$rs = $st->get_result();
while ($row = $rs->fetch_assoc()) {
  $rid = (int)$row['RoomID'];
  if (!isset($bookingsByRoom[$rid])) continue;
  $bookingsByRoom[$rid] = (int)$row['BookingCount'];
  $guestsByRoom[$rid]   = (int)($row['GuestSum'] ?? 0);
}
$st->close();

/* ── occupancy by room (seconds overlap / seconds in range) ─────── */
$rangeSeconds = max(1, $to->getTimestamp() - $from->getTimestamp());
$occupiedSecondsByRoom = array_fill_keys($roomIDs, 0);

foreach ($bookings as $b) {
  $rid = (int)$b['RoomID'];
  if (!isset($occupiedSecondsByRoom[$rid])) continue;

  $status = (string)($b['Status'] ?? '');
  if ($status === 'Cancelled') continue;

  $s = $b['StartDT'];
  $e = $b['EndDT'];
  if (!$s || !$e) continue;

  $overlapStart = max($from->getTimestamp(), $s->getTimestamp());
  $overlapEnd   = min($to->getTimestamp(),   $e->getTimestamp());
  $overlap = $overlapEnd - $overlapStart;
  if ($overlap > 0) $occupiedSecondsByRoom[$rid] += $overlap;
}

$occupancyPctByRoom = [];
foreach ($roomIDs as $rid) {
  $pct = clamp01($occupiedSecondsByRoom[$rid] / $rangeSeconds) * 100.0;
  $occupancyPctByRoom[$rid] = round($pct, 1);
}

/* ── occupancy trend by month (overall + by room) ───────────────── */
$months = [];
$cursor = (clone $from);
$cursor->modify('first day of this month')->setTime(0,0,0);
$endCursor = (clone $to);
$endCursor->modify('first day of this month')->setTime(0,0,0);

while ($cursor <= $endCursor) {
  $mStart = (clone $cursor);
  $mEnd   = (clone $cursor);
  $mEnd->modify('first day of next month')->setTime(0,0,0);

  $startTs = max($from->getTimestamp(), $mStart->getTimestamp());
  $endTs   = min($to->getTimestamp(),   $mEnd->getTimestamp());

  $months[] = [
    'label' => $mStart->format('M Y'),
    'start' => $startTs,
    'end'   => $endTs,
    'seconds' => max(1, $endTs - $startTs),
  ];
  $cursor->modify('first day of next month');
}

$trendOverall = [];
$trendByRoom  = [];
foreach ($roomIDs as $rid) $trendByRoom[$rid] = array_fill(0, count($months), 0.0);

for ($i=0; $i<count($months); $i++) {
  $m = $months[$i];
  $mOcc = 0;
  $mOccByRoom = array_fill_keys($roomIDs, 0);

  foreach ($bookings as $b) {
    $status = (string)($b['Status'] ?? '');
    if ($status === 'Cancelled') continue;

    $rid = (int)$b['RoomID'];
    if (!isset($mOccByRoom[$rid])) continue;

    $s = $b['StartDT']; $e = $b['EndDT'];
    if (!$s || !$e) continue;

    $overlapStart = max($m['start'], $s->getTimestamp());
    $overlapEnd   = min($m['end'],   $e->getTimestamp());
    $overlap = $overlapEnd - $overlapStart;
    if ($overlap > 0) {
      $mOcc += $overlap;
      $mOccByRoom[$rid] += $overlap;
    }
  }

  $trendOverall[] = round(clamp01($mOcc / $m['seconds']) * 100.0, 1);
  foreach ($roomIDs as $rid) {
    $trendByRoom[$rid][$i] = round(clamp01($mOccByRoom[$rid] / $m['seconds']) * 100.0, 1);
  }
}

/* ── “cool” stats ──────────────────────────────────────────────── */
$cool = [
  'avgParty' => 0.0,
  'avgNights' => 0.0,
  'cancelRate' => 0.0,
  'upcoming30' => 0,
  'busiestWeekday' => '—',
];

$totalBookingsInRange = 0;
$totalCancelledInRange = 0;
$totalGuestsInRange = 0;
$totalNightsInRange = 0;
$weekdayCounts = array_fill(0, 7, 0);

foreach ($bookings as $b) {
  $s = $b['StartDT']; $e = $b['EndDT'];
  if (!$s || !$e) continue;

  if ($s->getTimestamp() >= $to->getTimestamp() || $e->getTimestamp() <= $from->getTimestamp()) continue;

  $totalBookingsInRange++;
  if ((string)($b['Status'] ?? '') === 'Cancelled') $totalCancelledInRange++;

  $totalGuestsInRange += (int)($b['GuestCount'] ?? 0);
  $totalNightsInRange += max(1, (int)$s->diff($e)->format('%a'));
  $weekdayCounts[(int)$s->format('w')]++;
}

$now = new DateTime();
$in30 = (clone $now)->modify('+30 days');
$st = $link->prepare("
  SELECT COUNT(*) AS cnt
  FROM Bookings b
  WHERE b.Status <> 'Cancelled'
    AND b.StartDateTime >= ?
    AND b.StartDateTime < ?
");
$nowStr = $now->format('Y-m-d H:i:s');
$in30Str = $in30->format('Y-m-d H:i:s');
$st->bind_param('ss', $nowStr, $in30Str);
$st->execute();
$rs = $st->get_result();
$cool['upcoming30'] = (int)(($rs->fetch_assoc()['cnt'] ?? 0));
$st->close();

$cool['avgParty'] = $totalBookingsInRange > 0 ? round($totalGuestsInRange / $totalBookingsInRange, 2) : 0.0;
$cool['avgNights'] = $totalBookingsInRange > 0 ? round($totalNightsInRange / $totalBookingsInRange, 2) : 0.0;
$cool['cancelRate'] = $totalBookingsInRange > 0 ? round(($totalCancelledInRange / $totalBookingsInRange) * 100.0, 1) : 0.0;

$weekdayNames = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
$maxW = array_keys($weekdayCounts, max($weekdayCounts))[0];
$cool['busiestWeekday'] = ($totalBookingsInRange > 0) ? $weekdayNames[$maxW] : '—';

/* ── chart arrays ──────────────────────────────────────────────── */
$labelsRooms = array_values($rooms);
$dataGuestsByRoom = [];
$dataBookingsByRoom = [];
$dataOccByRoom = [];
foreach ($roomIDs as $rid) {
  $dataGuestsByRoom[]   = (int)($guestsByRoom[$rid] ?? 0);
  $dataBookingsByRoom[] = (int)($bookingsByRoom[$rid] ?? 0);
  $dataOccByRoom[]      = (float)($occupancyPctByRoom[$rid] ?? 0.0);
}
$labelsMonths = array_map(fn($m)=>$m['label'], $months);

/* ── optional: keep magic_key in internal links ────────────────── */
$magicKey = isset($_GET['magic_key']) ? (string)$_GET['magic_key'] : '';
function with_magic(string $url, string $magicKey): string {
  if ($magicKey === '') return $url;
  $sep = (str_contains($url, '?')) ? '&' : '?';
  return $url . $sep . 'magic_key=' . urlencode($magicKey);
}
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Reports</title>
  <base target="_self">

  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    body { background:#f6f7fb; }
    .card { border:0; border-radius:16px; }
    .shadow-soft { box-shadow: 0 1px 2px rgba(16,24,40,.04), 0 8px 24px rgba(16,24,40,.08); }
    .kpi { font-size: .9rem; color:#6c757d; }
    .kpi strong { color:#111827; font-size: 1.15rem; }
    .chart-wrap { position: relative; height: 320px; }
    .chart-wrap.tall { height: 360px; }
    .muted { color:#6c757d; }
  </style>
</head>
<body>

<?php include '../header.php'; ?>
<?php include 'subheader.php'; ?>

<div class="container py-4">

  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
      <h4 class="mb-0">Reports</h4>
      <div class="small muted">Range: <?= h($from->format('Y-m-d')) ?> → <?= h($to->format('Y-m-d')) ?></div>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <a class="btn btn-outline-secondary" href="<?= h(with_magic($BASE.'/guest_manager.php', $magicKey)) ?>">← Back to Guest Manager</a>
      <a class="btn btn-outline-primary" href="<?= h(with_magic($BASE.'/reports.php', $magicKey)) ?>">Reset</a>
    </div>
  </div>

  <!-- Range selector -->
  <div class="card shadow-soft mb-4">
    <div class="card-body">
      <form class="row g-2 align-items-end" method="get" action="">
        <?php if ($magicKey !== ''): ?>
          <input type="hidden" name="magic_key" value="<?= h($magicKey) ?>">
        <?php endif; ?>
        <div class="col-sm-4 col-md-3">
          <label class="form-label">From</label>
          <input type="date" class="form-control" name="from" value="<?= h($from->format('Y-m-d')) ?>">
        </div>
        <div class="col-sm-4 col-md-3">
          <label class="form-label">To</label>
          <input type="date" class="form-control" name="to" value="<?= h($to->format('Y-m-d')) ?>">
        </div>
        <div class="col-sm-4 col-md-3">
          <button type="submit" class="btn btn-primary w-100">Apply</button>
        </div>
        <div class="col-md-3">
          <div class="small muted">Default is last 6 months.</div>
          <div class="small muted">Occupancy excludes Cancelled bookings.</div>
        </div>
      </form>
    </div>
  </div>

  <!-- KPI row -->
  <div class="row g-4 mb-4">
    <div class="col-md-3">
      <div class="card shadow-soft h-100">
        <div class="card-body">
          <div class="kpi">Upcoming (next 30 days)</div>
          <strong><?= (int)$cool['upcoming30'] ?></strong>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow-soft h-100">
        <div class="card-body">
          <div class="kpi">Avg party size</div>
          <strong><?= h((string)$cool['avgParty']) ?></strong>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow-soft h-100">
        <div class="card-body">
          <div class="kpi">Avg nights per booking</div>
          <strong><?= h((string)$cool['avgNights']) ?></strong>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card shadow-soft h-100">
        <div class="card-body">
          <div class="kpi">Cancellation rate</div>
          <strong><?= h((string)$cool['cancelRate']) ?>%</strong>
          <div class="small muted mt-1">Busiest check-in: <?= h($cool['busiestWeekday']) ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Charts -->
  <div class="row g-4">
    <div class="col-lg-6">
      <div class="card shadow-soft h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">Guests by room</h6>
            <span class="small muted">Sum of guests across bookings</span>
          </div>
          <div class="chart-wrap">
            <canvas id="guestsPie"></canvas>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card shadow-soft h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">Bookings by room</h6>
            <span class="small muted">All statuses</span>
          </div>
          <div class="chart-wrap">
            <canvas id="bookingsBar"></canvas>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card shadow-soft h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">Occupancy by room</h6>
            <span class="small muted">Excludes Cancelled</span>
          </div>
          <div class="chart-wrap">
            <canvas id="occByRoom"></canvas>
          </div>
          <div class="small muted mt-2">
            Occupancy = overlapped seconds in range ÷ total seconds in range (clamped 0–100%).
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card shadow-soft h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">Occupancy trend</h6>
            <span class="small muted">Monthly %</span>
          </div>
          <div class="chart-wrap tall">
            <canvas id="occTrend"></canvas>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow-soft mt-4">
    <div class="card-body">
      <h6 class="mb-3">Room summary</h6>
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead>
            <tr>
              <th>Room</th>
              <th class="text-end">Bookings</th>
              <th class="text-end">Guests</th>
              <th class="text-end">Occupancy</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($roomIDs as $rid): ?>
            <tr>
              <td><?= h($rooms[$rid] ?? ('Room '.$rid)) ?></td>
              <td class="text-end"><?= (int)($bookingsByRoom[$rid] ?? 0) ?></td>
              <td class="text-end"><?= (int)($guestsByRoom[$rid] ?? 0) ?></td>
              <td class="text-end"><?= h((string)($occupancyPctByRoom[$rid] ?? 0.0)) ?>%</td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (count($roomIDs) === 0): ?>
        <div class="alert alert-warning mt-3 mb-0">No rooms found in HouseLocations.</div>
      <?php endif; ?>
    </div>
  </div>

</div>

<?php include '../footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function(){
  const labelsRooms = <?= json_encode($labelsRooms, JSON_UNESCAPED_SLASHES|JSON_HEX_APOS) ?>;
  const guestsByRoom = <?= json_encode($dataGuestsByRoom) ?>;
  const bookingsByRoom = <?= json_encode($dataBookingsByRoom) ?>;
  const occByRoom = <?= json_encode($dataOccByRoom) ?>;

  const labelsMonths = <?= json_encode($labelsMonths, JSON_UNESCAPED_SLASHES|JSON_HEX_APOS) ?>;
  const occOverall = <?= json_encode($trendOverall) ?>;

  const occByRoomTrend = <?= json_encode(array_values($trendByRoom)) ?>;

  function hasAnyData(arr) {
    return Array.isArray(arr) && arr.some(v => Number(v) > 0);
  }

  // Guests by room (doughnut)
  new Chart(document.getElementById('guestsPie'), {
    type: 'doughnut',
    data: { labels: labelsRooms, datasets: [{ data: guestsByRoom }] },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom' } }
    }
  });

  // Bookings by room (bar)
  new Chart(document.getElementById('bookingsBar'), {
    type: 'bar',
    data: { labels: labelsRooms, datasets: [{ label: 'Bookings', data: bookingsByRoom }] },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
    }
  });

  // Occupancy by room (bar)
  new Chart(document.getElementById('occByRoom'), {
    type: 'bar',
    data: { labels: labelsRooms, datasets: [{ label: 'Occupancy %', data: occByRoom }] },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, max: 100, ticks: { callback: (v)=> v + '%' } } }
    }
  });

  // Occupancy trend (line)
  const datasets = [{ label: 'Overall', data: occOverall, tension: 0.25 }];

  for (let i=0; i<labelsRooms.length; i++) {
    const series = occByRoomTrend[i] || [];
    datasets.push({ label: labelsRooms[i], data: series, tension: 0.25 });
  }

  new Chart(document.getElementById('occTrend'), {
    type: 'line',
    data: { labels: labelsMonths, datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { position: 'bottom' } },
      scales: { y: { beginAtZero: true, max: 100, ticks: { callback: (v)=> v + '%' } } }
    }
  });
})();
</script>

<script>
// Preserve magic_key for forms/links if present
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

    document.querySelectorAll("a[href]").forEach(a => {
      try {
        const href = a.getAttribute("href");
        if (!href || href.includes("magic_key=")) return;
        if (href.startsWith("#") || href.startsWith("mailto:") || href.startsWith("tel:")) return;

        const u = new URL(href, window.location.href);
        if (u.origin !== window.location.origin) return;
        u.searchParams.set("magic_key", magicKey);
        a.setAttribute("href", u.pathname + u.search + u.hash);
      } catch {}
    });
  });
})();
</script>

</body>
</html>
