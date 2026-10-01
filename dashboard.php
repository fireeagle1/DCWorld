<?php
// dashboard.php — Modernised layout while keeping the week location summaries.
// Changes (this revision):
// - Removes “Ordered by start time” text
// - Adds an optional lightweight header image (placeholder URL) you can replace
// - Keeps Bootstrap 5 only (do not include Bootstrap 4 here)

declare(strict_types=1);

session_start();
require 'config.php';
require 'auth.php'; // must define $userID

header('Content-Type: text/html; charset=utf-8');
if (method_exists($link, 'set_charset')) { $link->set_charset('utf8mb4'); }
date_default_timezone_set('Europe/London');

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* ---------- current user (kept for any downstream logic; not displayed) ---------- */
$sqlUser = "SELECT Name FROM DC_Users WHERE UserID = ?";
$stmt = $link->prepare($sqlUser);
$stmt->bind_param("i", $userID);
$stmt->execute();
$stmt->bind_result($Name);
$stmt->fetch();
$stmt->close();

/* ---------- week range (Mon–Sun) with optional ?week offset ---------- */
$weekOffset = filter_input(INPUT_GET, 'week', FILTER_VALIDATE_INT);
$weekOffset = $weekOffset !== null ? (int)$weekOffset : 0;

$tz = new DateTimeZone('Europe/London');
$startOfWeek = new DateTime('now', $tz);
$startOfWeek->modify('monday this week');
if ($weekOffset !== 0) { $startOfWeek->modify(($weekOffset * 7) . ' days'); }
$endOfWeek = clone $startOfWeek; $endOfWeek->modify('+6 days');

$formattedStart = $startOfWeek->format('Y-m-d');
$formattedEnd   = $endOfWeek->format('Y-m-d');
$currentDate    = (new DateTime('now', $tz))->format('Y-m-d');

/* ---------- ops for the week (Notes used as Meal) + On-Call ---------- */
$sqlOps = "
  SELECT
    p.Date,
    p.Notes,                         -- meal text
    p.CKOnCall, p.DCOnCall,
    loc.LocationDesc  AS CKDayLocation,  loc.Location_Icon  AS CKDayIcon,
    loc2.LocationDesc AS DCDayLocation,  loc2.Location_Icon AS DCDayIcon,
    w1.WLocationDesc  AS CKWorkDayLocation, w1.WLocation_Icon AS CKWorkDayIcon,
    w2.WLocationDesc  AS DCWorkDayLocation, w2.WLocation_Icon AS DCWorkDayIcon
  FROM DCDailyOpsPlan p
  LEFT JOIN DC_Locations     loc  ON p.CKLocation     = loc.LocationID
  LEFT JOIN DC_Locations     loc2 ON p.DCLocation     = loc2.LocationID
  LEFT JOIN DC_WorkLocation  w1   ON p.CKWorkLocation = w1.WorkID
  LEFT JOIN DC_WorkLocation  w2   ON p.DCWorkLocation = w2.WorkID
  WHERE p.Date BETWEEN ? AND ?
  ORDER BY p.Date ASC
";
$stmtOps = $link->prepare($sqlOps);
$stmtOps->bind_param('ss', $formattedStart, $formattedEnd);
$stmtOps->execute();
$resOps = $stmtOps->get_result();

$opsByDate = [];
while ($row = $resOps->fetch_assoc()) {
  $opsByDate[$row['Date']] = [
    'Notes'             => $row['Notes'] ?? '',
    'CKOnCall'          => isset($row['CKOnCall']) ? (int)$row['CKOnCall'] : null,
    'DCOnCall'          => isset($row['DCOnCall']) ? (int)$row['DCOnCall'] : null,
    'CKDayLocation'     => $row['CKDayLocation'] ?? '',
    'CKDayIcon'         => $row['CKDayIcon'] ?? '',
    'DCDayLocation'     => $row['DCDayLocation'] ?? '',
    'DCDayIcon'         => $row['DCDayIcon'] ?? '',
    'CKWorkDayLocation' => $row['CKWorkDayLocation'] ?? '',
    'CKWorkDayIcon'     => $row['CKWorkDayIcon'] ?? '',
    'DCWorkDayLocation' => $row['DCWorkDayLocation'] ?? '',
    'DCWorkDayIcon'     => $row['DCWorkDayIcon'] ?? '',
  ];
}
$stmtOps->close();

/* build Mon–Sun array */
$weekDays = [];
$cursor = clone $startOfWeek;
while ($cursor <= $endOfWeek) {
  $d = $cursor->format('Y-m-d');
  $weekDays[$d] = $opsByDate[$d] ?? [
    'Notes'             => '',
    'CKOnCall'          => null,
    'DCOnCall'          => null,
    'CKDayLocation'     => '',
    'CKDayIcon'         => '',
    'DCDayLocation'     => '',
    'DCDayIcon'         => '',
    'CKWorkDayLocation' => '',
    'CKWorkDayIcon'     => '',
    'DCWorkDayLocation' => '',
    'DCWorkDayIcon'     => '',
  ];
  $weekDays[$d]['Date'] = $d;
  $cursor->modify('+1 day');
}

/* ---------- events for the week ---------- */
$sqlEvents = "
  SELECT e.EventID, e.EventTitle, e.StartDateTime, e.EndDateTime, e.Location, e.AllDay, e.Source
  FROM Events e
  WHERE DATE(e.StartDateTime) BETWEEN ? AND ?
  ORDER BY e.StartDateTime ASC
";
$stmtEv = $link->prepare($sqlEvents);
$stmtEv->bind_param('ss', $formattedStart, $formattedEnd);
$stmtEv->execute();
$rEv = $stmtEv->get_result();
$events = [];
while ($row = $rEv->fetch_assoc()) { $events[] = $row; }
$stmtEv->close();

/* ---------- bookings overlapping the week (for guest cards) ---------- */
$sqlBookings = "
  SELECT b.*, r.Name AS RoomName
  FROM Bookings b
  LEFT JOIN HouseLocations r ON b.RoomID = r.RoomID
  WHERE
      (DATE(b.StartDateTime) BETWEEN ? AND ?)
   OR (DATE(b.EndDateTime)   BETWEEN ? AND ?)
   OR (DATE(b.StartDateTime) <= ? AND DATE(b.EndDateTime) >= ?)
  ORDER BY b.StartDateTime ASC
";
$stmtBk = $link->prepare($sqlBookings);
$stmtBk->bind_param('ssssss', $formattedStart, $formattedEnd, $formattedStart, $formattedEnd, $formattedStart, $formattedEnd);
$stmtBk->execute();
$rBk = $stmtBk->get_result();

$bookings = [];
$contactIDs = [];
while ($b = $rBk->fetch_assoc()) {
  $decoded = json_decode($b['GuestsJSON'] ?? '[]', true) ?: [];
  $ids = array_map('intval', array_filter(($decoded['guests'] ?? []), static fn($x)=>$x));
  $b['_GuestIDs'] = $ids;
  $bookings[] = $b;
  foreach ($ids as $cid) { $contactIDs[$cid] = true; }
}
$stmtBk->close();

/* fetch contacts once for guest names */
$contacts = [];
if (!empty($contactIDs)) {
  $in = implode(',', array_map('intval', array_keys($contactIDs)));
  $qC = $link->query("SELECT ContactID, KnownAs, PhotoURL FROM Contacts WHERE ContactID IN ($in)");
  while ($c = $qC->fetch_assoc()) { $contacts[(int)$c['ContactID']] = $c; }
}

/* decorate bookings with guest objects */
foreach ($bookings as &$bk) {
  $guestObjs = [];
  foreach ($bk['_GuestIDs'] as $cid) {
    $g = $contacts[$cid] ?? ['ContactID'=>$cid,'KnownAs'=>'Guest','PhotoURL'=>null];
    $guestObjs[] = $g;
  }
  $bk['_Guests'] = $guestObjs;
}
unset($bk);

/* ---------- brand images for event backgrounds (optional) ---------- */
$sqlBrand = "SELECT brandimageID, BrandImageURL, Keywords FROM BrandImages";
$rBrand = $link->query($sqlBrand);
$brandImages = []; $defaultBrandUrl = ''; $dutyBrandUrl='';
if ($rBrand) {
  while ($row = $rBrand->fetch_assoc()) {
    $brandImages[] = $row;
    if ((int)$row['brandimageID'] === 1) { $defaultBrandUrl = $row['BrandImageURL']; }
    if ((int)$row['brandimageID'] === 2) { $dutyBrandUrl    = $row['BrandImageURL']; }
  }
  $rBrand->free();
}

function chooseEventBgUrl(array $event, array $brandImages, string $defaultUrl, string $dutyUrl): string {
  if (($event['Source'] ?? '') === 'DutySheet' && $dutyUrl !== '') {
    return $dutyUrl;
  }
  $title = strtolower((string)($event['EventTitle'] ?? ''));
  foreach ($brandImages as $brand) {
    $kw = array_filter(array_map('trim', explode(',', (string)$brand['Keywords'])));
    foreach ($kw as $word) {
      if ($word !== '' && strpos($title, strtolower($word)) !== false) {
        return $brand['BrandImageURL'];
      }
    }
  }
  return $defaultUrl ?: 'https://assets.dcworld.uk/images/newbanner.png';
}

/* Merge events + bookings into one timeline for the card grid */
$combined = [];
foreach ($events as $e) { $combined[] = ['type'=>'event', 'start'=>$e['StartDateTime'], 'data'=>$e]; }
foreach ($bookings as $b) { $combined[] = ['type'=>'booking', 'start'=>$b['StartDateTime'], 'data'=>$b]; }
usort($combined, static fn($a,$b)=> strcmp($a['start'], $b['start']));

$link->close();

function formatGuestNamesAlways(array $names, int $maxShown = 4): string {
  $names = array_values(array_filter(array_map('strval', $names)));
  $count = count($names);
  if ($count === 0) return 'Guest booking';
  if ($count <= $maxShown) return implode(', ', $names);
  $shown = array_slice($names, 0, $maxShown);
  return implode(', ', $shown) . ' and ' . ($count - $maxShown) . ' more';
}

$page_title = 'Dashboard';
?>
<?php include 'header.php'; ?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">

<style>
  :root{
    --page-bg: #f3f4f6;
    --card-bg: #ffffff;
    --border: rgba(0,0,0,.08);
    --shadow: 0 10px 30px rgba(0,0,0,.08);
    --shadow-sm: 0 6px 18px rgba(0,0,0,.08);
    --text: #0f172a;
    --muted: #64748b;
    --primary: #0b57d0;
    --danger: #dc3545;
    --success: #10b981;
  }

  body { font-family: 'Roboto', sans-serif; background: var(--page-bg); color: var(--text); }

  .dash-wrap{
    max-width: 1200px;
    margin: 0 auto;
    padding: 14px 10px 24px;
  }

  /* Optional header image (replace URL) */
  .hero{
    border: 1px solid var(--border);
    border-radius: 18px;
    overflow:hidden;
    background: #0b1220;
    box-shadow: 0 6px 18px rgba(0,0,0,.08);
    margin-bottom: 14px;
  }
  .hero-inner{
    position: relative;
    min-height: 140px;
    background: url('https://assets.dcworld.uk/images/helicopter.jpeg') no-repeat center center / cover;
  }
  .hero-inner::after{
    content:"";
    position:absolute;
    inset:0;
    background: linear-gradient(180deg, rgba(0,0,0,.10), rgba(0,0,0,.55));
  }
  .hero-content{
    position: relative;
    z-index: 1;
    padding: 14px 14px 16px;
    color: #fff;
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap: 10px;
  }
  .hero-title{
    margin:0;
    font-weight: 900;
    letter-spacing: -0.02em;
    font-size: 1.25rem;
    text-shadow: 0 2px 10px rgba(0,0,0,.35);
  }

  .week-nav{
    display:flex;
    gap: 8px;
    align-items:center;
  }
  .range-pill{
    display:inline-flex;
    align-items:center;
    gap:.5rem;
    border: 1px solid rgba(255,255,255,.25);
    background: rgba(0,0,0,.25);
    backdrop-filter: blur(6px);
    border-radius: 999px;
    padding: .4rem .7rem;
    color: rgba(255,255,255,.92);
    font-weight: 800;
    font-size: .9rem;
    white-space: nowrap;
    text-shadow: 0 1px 3px rgba(0,0,0,.35);
  }
  .btn-week{
    border-radius: 12px;
    padding: .45rem .7rem;
    border: 1px solid rgba(255,255,255,.25);
    background: rgba(0,0,0,.25);
    color: #fff;
    font-weight: 900;
    text-decoration:none;
    line-height: 1;
    backdrop-filter: blur(6px);
  }
  .btn-week:hover{
    transform: translateY(-1px);
    transition: transform .12s ease;
    color:#fff;
  }

  @media (max-width: 576px){
    .hero-content{
      flex-direction: column;
      align-items:flex-start;
    }
    .range-pill{ font-size: .85rem; }
  }

  /* Week cards — keep 7-col desktop + horizontal scroll mobile */
  @media (min-width: 768px) {
    .horizontal-scroll { display: grid; grid-template-columns: repeat(7, 1fr); gap: 12px; }
  }
  @media (max-width: 767.98px) {
    .horizontal-scroll { display: flex; overflow-x: auto; gap: 10px; scroll-snap-type: x mandatory; -webkit-overflow-scrolling: touch; padding-bottom: 6px; }
    .horizontal-scroll .ops-card { min-width: 180px; scroll-snap-align: start; }
  }

  .ops-card{
    border: 1px solid var(--border);
    border-radius: 14px;
    background: var(--card-bg);
    box-shadow: 0 2px 14px rgba(0,0,0,.06);
    overflow:hidden;
  }

  .ops-head{
    padding: 10px 12px;
    font-weight: 800;
    font-size: .9rem;
    display:flex;
    align-items:center;
    justify-content:space-between;
    background: linear-gradient(180deg, rgba(2,132,199,.14), rgba(2,132,199,0));
  }

  .ops-head .day{
    display:flex;
    flex-direction:column;
    line-height: 1.15;
  }
  .ops-head .day small{
    color: var(--muted);
    font-weight: 700;
  }

  .today-dot{
    width: 10px;
    height: 10px;
    border-radius: 999px;
    background: var(--primary);
    box-shadow: 0 0 0 4px rgba(11,87,208,.15);
  }

  .ops-body{
    padding: 10px 12px 12px;
    font-size: .86rem;
  }

  .person{
    font-weight: 900;
    margin-top: 6px;
    margin-bottom: 4px;
    color: #0b2a3e;
  }

  .loc-row{
    display:flex;
    gap: 8px;
    align-items:flex-start;
    margin: 4px 0;
    color: #1f2937;
  }
  .loc-row img{
    width: 16px; height: 16px;
    margin-top: 2px;
  }
  .muted{ color: var(--muted); }

  .oncall-strip{
    height: 6px;
    background: var(--danger);
  }
  .oncall-label{
    display:inline-flex;
    align-items:center;
    gap: .35rem;
    background: rgba(220,53,69,.12);
    color: #b42318;
    border: 1px solid rgba(220,53,69,.22);
    font-weight: 900;
    font-size: .78rem;
    padding: .18rem .55rem;
    border-radius: 999px;
    margin-bottom: 6px;
  }

  .meal-badge{
    display:inline-flex;
    align-items:center;
    gap:.35rem;
    background: rgba(16,185,129,.12);
    color: #0f766e;
    border: 1px solid rgba(16,185,129,.22);
    border-radius: 999px;
    padding: .18rem .55rem;
    font-size: .78rem;
    font-weight: 800;
    margin-top: 6px;
    max-width: 100%;
  }
  .meal-badge span{
    overflow:hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 100%;
    display:inline-block;
  }

  /* Section heading */
  .section-head{
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap: 12px;
    margin: 18px 0 10px;
  }
  .section-head h2{
    margin:0;
    font-size: 1.05rem;
    font-weight: 900;
    letter-spacing: -0.01em;
  }

  /* Events + bookings grid */
  .grid{
    display:grid;
    gap: 14px;
    grid-template-columns: repeat(12, 1fr);
  }
  .grid > .grid-item{ grid-column: span 4; }
  @media (max-width: 992px){
    .grid > .grid-item{ grid-column: span 6; }
  }
  @media (max-width: 576px){
    .grid > .grid-item{ grid-column: span 12; }
  }

  .tile{
    border: 1px solid var(--border);
    border-radius: 16px;
    overflow:hidden;
    background: #000;
    box-shadow: var(--shadow-sm);
    position: relative;
    transform: translateY(0);
    transition: transform .12s ease, box-shadow .12s ease;
    text-decoration:none;
    display:block;
  }
  .tile:hover{
    transform: translateY(-2px);
    box-shadow: var(--shadow);
  }

  .tile-media{
    position: relative;
    width: 100%;
    padding-top: 56.25%;
    background: #0b1220;
  }
  .tile-media img{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
    object-fit: cover;
    object-position:center;
  }
  .tile-media::after{
    content:"";
    position:absolute;
    inset:0;
    background: linear-gradient(180deg, rgba(0,0,0,.15), rgba(0,0,0,.75));
  }

  .tile-badge{
    position:absolute;
    top:10px;
    right:10px;
    z-index:2;
    display:inline-flex;
    align-items:center;
    gap:.35rem;
    border-radius: 999px;
    padding: .25rem .6rem;
    font-weight: 900;
    font-size: .82rem;
    color:#fff;
    background: rgba(0,0,0,.55);
    border: 1px solid rgba(255,255,255,.18);
    backdrop-filter: blur(6px);
  }

  .tile-body{
    position:absolute;
    left: 0;
    right: 0;
    bottom: 0;
    z-index:2;
    padding: 12px 12px 14px;
    color:#fff;
  }
  .tile-title{
    font-weight: 900;
    font-size: 1rem;
    margin: 0 0 4px 0;
    text-shadow: 0 1px 3px rgba(0,0,0,.6);
  }
  .tile-meta{
    margin:0;
    font-size: .84rem;
    opacity: .95;
    text-shadow: 0 1px 3px rgba(0,0,0,.6);
  }

  .room-pill{
    display:inline-flex;
    align-items:center;
    gap:.35rem;
    background: rgba(11,87,208,.9);
    color:#fff;
    border-radius:999px;
    padding:.18rem .55rem;
    font-size:.75rem;
    font-weight: 900;
    margin-top: 6px;
  }
  .room-pill::before{ content: '🛏️'; }
</style>

<div class="dash-wrap">

  <!-- Optional hero image header -->
  <div class="hero">
    <div class="hero-inner">
      <div class="hero-content">
        <h1 class="hero-title">Dashboard</h1>

        <div class="week-nav">
          <a href="?week=<?= $weekOffset - 1; ?>" class="btn-week" aria-label="Previous week">&laquo;</a>
          <div class="range-pill" aria-label="Week range">
            <?= h($startOfWeek->format('D j M Y')); ?> – <?= h($endOfWeek->format('D j M Y')); ?>
          </div>
          <a href="?week=<?= $weekOffset + 1; ?>" class="btn-week" aria-label="Next week">&raquo;</a>
        </div>
      </div>
    </div>
  </div>

  <!-- Week Day Cards -->
  <div class="horizontal-scroll">
    <?php foreach ($weekDays as $date => $op):
      $isCurrent = ($date === $currentDate);
      $ckCall = ((int)($op['CKOnCall'] ?? 0) === 1);
      $dcCall = ((int)($op['DCOnCall'] ?? 0) === 1);
      $mealText = trim((string)($op['Notes'] ?? ''));
    ?>
      <div class="ops-card">
        <?php if ($ckCall || $dcCall): ?><div class="oncall-strip" aria-hidden="true"></div><?php endif; ?>

        <div class="ops-head">
          <div class="day">
            <div><?= h(date('D, j M', strtotime($date))); ?></div>
            <small><?= h(date('Y', strtotime($date))); ?></small>
          </div>
          <?php if ($isCurrent): ?><div class="today-dot" title="Today" aria-label="Today"></div><?php endif; ?>
        </div>

        <div class="ops-body">
          <?php if ($ckCall): ?><div class="oncall-label">☎️ CK on call</div><?php endif; ?>
          <?php if ($dcCall): ?><div class="oncall-label">☎️ DC on call</div><?php endif; ?>

          <?php if (!empty($op['CKDayLocation']) || !empty($op['DCDayLocation']) || !empty($op['CKWorkDayLocation']) || !empty($op['DCWorkDayLocation']) || $mealText !== ''): ?>
            <div class="person">Charlie (CK)</div>
            <?php if (!empty($op['CKDayLocation'])): ?>
              <div class="loc-row">
                <?php if (!empty($op['CKDayIcon'])): ?><img src="<?= h($op['CKDayIcon']); ?>" alt=""><?php endif; ?>
                <div><?= h($op['CKDayLocation']); ?></div>
              </div>
            <?php endif; ?>
            <?php if (!empty($op['CKWorkDayLocation'])): ?>
              <div class="loc-row">
                <?php if (!empty($op['CKWorkDayIcon'])): ?><img src="<?= h($op['CKWorkDayIcon']); ?>" alt=""><?php endif; ?>
                <div><?= h($op['CKWorkDayLocation']); ?></div>
              </div>
            <?php endif; ?>

            <div class="person">Daniel (DC)</div>
            <?php if (!empty($op['DCDayLocation'])): ?>
              <div class="loc-row">
                <?php if (!empty($op['DCDayIcon'])): ?><img src="<?= h($op['DCDayIcon']); ?>" alt=""><?php endif; ?>
                <div><?= h($op['DCDayLocation']); ?></div>
              </div>
            <?php endif; ?>
            <?php if (!empty($op['DCWorkDayLocation'])): ?>
              <div class="loc-row">
                <?php if (!empty($op['DCWorkDayIcon'])): ?><img src="<?= h($op['DCWorkDayIcon']); ?>" alt=""><?php endif; ?>
                <div><?= h($op['DCWorkDayLocation']); ?></div>
              </div>
            <?php endif; ?>

            <?php if ($mealText !== ''): ?>
              <div class="meal-badge" title="Meal">
                🍽️ <span><?= h($mealText); ?></span>
              </div>
            <?php endif; ?>
          <?php else: ?>
            <div class="muted">No Ops Data</div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="section-head">
    <h2>This Week — Events &amp; Bookings</h2>
  </div>

  <?php if (!empty($combined)): ?>
    <div class="grid">
      <?php foreach ($combined as $item): ?>
        <?php if ($item['type'] === 'event'):
          $event   = $item['data'];
          $bgUrl   = chooseEventBgUrl($event, $brandImages, $defaultBrandUrl, $dutyBrandUrl);
          $title   = (string)$event['EventTitle'];
          $startFmt= date('D, j M Y h:i A', strtotime((string)$event['StartDateTime']));
          $endFmt  = date('D, j M Y h:i A', strtotime((string)$event['EndDateTime']));
          $href    = "view_event.php?eventId=" . (int)$event['EventID'];
        ?>
          <div class="grid-item">
            <a class="tile" href="<?= h($href); ?>">
              <div class="tile-media">
                <img src="<?= h($bgUrl); ?>" alt="">
              </div>
              <div class="tile-badge" title="Event">📅 Event</div>
              <div class="tile-body">
                <div class="tile-title"><?= h($title); ?></div>
                <p class="tile-meta mb-1"><?= h($startFmt); ?> – <?= h($endFmt); ?></p>
                <?php if (!empty($event['Location'])): ?>
                  <p class="tile-meta mb-0"><?= h((string)$event['Location']); ?></p>
                <?php endif; ?>
              </div>
            </a>
          </div>

        <?php else:
          $bk         = $item['data'];
          $guestObjs  = $bk['_Guests'] ?? [];
          $guestNames = array_map(static fn($g)=> (string)($g['KnownAs'] ?? 'Guest'), $guestObjs);
          $namesLine  = formatGuestNamesAlways($guestNames, 4);
          $startFmt   = date('D, j M Y h:i A', strtotime((string)$bk['StartDateTime']));
          $endFmt     = date('D, j M Y h:i A', strtotime((string)$bk['EndDateTime']));
          $href       = "/home/view_booking.php?BookingID=" . (int)$bk['BookingID'];
          $bookingBg  = 'https://assets.dcworld.uk/images/helicopter.jpeg';
        ?>
          <div class="grid-item">
            <a class="tile" href="<?= h($href); ?>">
              <div class="tile-media">
                <img src="<?= h($bookingBg); ?>" alt="">
              </div>
              <div class="tile-badge" title="Booking">🏠 Booking</div>
              <div class="tile-body">
                <div class="tile-title"><?= h($namesLine); ?></div>
                <p class="tile-meta mb-1"><?= h($startFmt); ?> – <?= h($endFmt); ?></p>
                <?php if (!empty($bk['RoomName'])): ?>
                  <div class="room-pill" title="Room"><?= h((string)$bk['RoomName']); ?></div>
                <?php endif; ?>
              </div>
            </a>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="muted">No events or bookings for this week.</div>
  <?php endif; ?>

</div>

<?php include 'footer.php'; ?>
