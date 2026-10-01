<?php
/*  view_booking.php — snazzy view with accurate progress, resend confirmations, compact audit
    ------------------------------------------------------------------------------------------
    Change requested:
    • Move Booking History into its own tab (so it doesn’t extend the page).
    • Remove guest ID display from Guest Summary.
*/

session_start();
require '../config.php';
require '../auth.php';

/* ── helpers ───────────────────────────────────────────────────── */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function dt(?string $s): ?DateTime { return $s ? new DateTime($s) : null; }
function fmt_nice_date(?string $iso): string {
  if (!$iso) return '';
  $d = dt($iso); if (!$d) return '';
  return $d->format('D j M Y');
}

/* ── param guard ───────────────────────────────────────────────── */
if (!isset($_GET['BookingID'])) { echo "No booking selected."; exit; }
$bookingID = (int)$_GET['BookingID'];

/* ── POST: resend confirmations ────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_confirm']) && (int)$_POST['resend_confirm'] === 1) {
  $userID = (int)($_SESSION['userID'] ?? 0);

  $stmt = $link->prepare("SELECT b.*, r.Name AS RoomName FROM Bookings b LEFT JOIN HouseLocations r ON b.RoomID = r.RoomID WHERE b.BookingID = ?");
  $stmt->bind_param("i", $bookingID);
  $stmt->execute();
  $res  = $stmt->get_result();
  $booking = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  if (!$booking) { echo "Booking not found."; exit; }

  $roomID   = (int)$booking['RoomID'];
  $roomName = $booking['RoomName'] ?: ('Room #'.$roomID);
  $ref      = $booking['BookingRef'];
  $startIso = $booking['StartDateTime'] ?? '';
  $endIso   = $booking['EndDateTime'] ?? '';

  $payload = json_decode($_POST['confirm_payload'] ?? '[]', true) ?: [];
  $sentCount = 0;

  foreach ($payload as $rec) {
    $email = trim($rec['email'] ?? '');
    if ($email === '') continue;
    $cid   = isset($rec['contactID']) ? (int)$rec['contactID'] : null;
    $save  = !empty($rec['saveToContact']) ? 1 : 0;

    if ($save && $cid) {
      $stmtU = $link->prepare("UPDATE Contacts SET Email = ? WHERE ContactID = ?");
      $stmtU->bind_param("si", $email, $cid);
      $stmtU->execute();
      $stmtU->close();
    }

    $subject = "Your stay is booked! Ref {$ref}";
    $content =
"Hi there!

We're excited to confirm your upcoming stay with Daniel and Charlie.

• Booking Ref: {$ref}
• Room: {$roomName}
• Check-in: Arriving on ".fmt_nice_date($startIso)."
• Check-out: Leaving on ".fmt_nice_date($endIso)."

If you need anything or want to tweak the plan, please contact Charlie or Dan!

Charlie and Daniel";

    $stmtE = $link->prepare(
      "INSERT INTO DCEmailsLog (`to`,`Subject`,`Content`,`DateTimeSent`,`Sent`)
       VALUES (?, ?, ?, NOW(), 'No')"
    );
    $stmtE->bind_param("sss", $email, $subject, $content);
    $stmtE->execute();
    $stmtE->close();
    $sentCount++;
  }

  $stmtA = $link->prepare(
    "INSERT INTO BookingsAuditLog (InitiatedBy, TargetBooking, ChangeMade, TimeStamp)
     VALUES (?, ?, ?, NOW())"
  );
  $msg = "Guest confirmations re-sent (".$sentCount.")";
  $stmtA->bind_param("iis", $userID, $bookingID, $msg);
  $stmtA->execute();
  $stmtA->close();

  header("Location: view_booking.php?BookingID=".$bookingID."&resent=".$sentCount);
  exit();
}

/* ── load booking ──────────────────────────────────────────────── */
$sql = "SELECT b.*, r.Name AS RoomName
        FROM Bookings b
        LEFT JOIN HouseLocations r ON b.RoomID = r.RoomID
        WHERE b.BookingID = ?";
$stmt = $link->prepare($sql);
$stmt->bind_param("i", $bookingID);
$stmt->execute();
$res = $stmt->get_result();
$booking = $res ? $res->fetch_assoc() : null;
$stmt->close();
if (!$booking) { echo "Booking not found."; exit; }

$defaultAvatar = 'https://assets.dcworld.uk/images/contacts/Black%20and%20white%20organic%20farmhouse%20mountain%20landscape%20hand%20drawn%20logo.png';
$startDT = dt($booking['StartDateTime']);
$endDT   = dt($booking['EndDateTime']);
$now     = new DateTime();

/* Progress by seconds */
$progressPct = 0;
if ($startDT && $endDT && $endDT > $startDT) {
  $totalSec = max(1, $endDT->getTimestamp() - $startDT->getTimestamp());
  $elapsedSec = max(0, min($totalSec, $now->getTimestamp() - $startDT->getTimestamp()));
  $progressPct = (int)round(($elapsedSec / $totalSec) * 100);
}

/* guests + preferences */
$decodedGuests = json_decode($booking['GuestsJSON'] ?? '[]', true) ?: [];
$guestIDs = array_map('intval', array_filter($decodedGuests['guests'] ?? [], static fn($x)=>$x));
$guestList = [];
if ($guestIDs) {
    $inClause = implode(',', $guestIDs);
    $sqlGuests = "SELECT ContactID, KnownAs, PhotoURL, Email FROM Contacts WHERE ContactID IN ($inClause)";
    $resGuests = $link->query($sqlGuests);
    while ($g = $resGuests->fetch_assoc()) { $guestList[] = $g; }
}

/* preferences */
$guestPreferences = [];
if ($guestIDs) {
    $inClause = implode(',', $guestIDs);
    $sqlPrefs = "SELECT cp.ContactID, pt.Name, cp.Value
                 FROM ContactPreferences cp
                 JOIN PreferenceTypes pt ON cp.PreferenceID = pt.PreferenceID
                 WHERE cp.ContactID IN ($inClause)";
    $resPrefs = $link->query($sqlPrefs);
    while ($row = $resPrefs->fetch_assoc()) {
        $guestPreferences[(int)$row['ContactID']][$row['Name']] = $row['Value'];
    }
}
foreach ($guestList as &$guest) {
    $guest['Preferences'] = $guestPreferences[(int)$guest['ContactID']] ?? [];
}
unset($guest);

/* events during stay */
$sqlEvents = "
    SELECT e.*, CASE WHEN e.UserID = 0 THEN '' ELSE u.Name END AS CreatedBy
      FROM Events e
 LEFT JOIN DC_Users u ON e.UserID = u.UserID
     WHERE (e.StartDateTime BETWEEN ? AND ?)
        OR (e.EndDateTime   BETWEEN ? AND ?)
  ORDER BY e.StartDateTime ASC
";
$startIso = $booking['StartDateTime'];
$endIso   = $booking['EndDateTime'];
$stmtEvents = $link->prepare($sqlEvents);
$stmtEvents->bind_param("ssss", $startIso, $endIso, $startIso, $endIso);
$stmtEvents->execute();
$resEvents = $stmtEvents->get_result();
$events = $resEvents ? $resEvents->fetch_all(MYSQLI_ASSOC) : [];
$stmtEvents->close();

/* audit log */
$auditSql = "
  SELECT b.*, IFNULL(u.Name, CONCAT('Unknown (ID ', b.InitiatedBy, ')')) AS UserName
    FROM BookingsAuditLog b
LEFT JOIN DC_Users u ON b.InitiatedBy = u.UserID
   WHERE b.TargetBooking = ?
ORDER BY b.TimeStamp DESC
";
$stmtLog = $link->prepare($auditSql);
$stmtLog->bind_param("i", $bookingID);
$stmtLog->execute();
$resLog = $stmtLog->get_result();
$auditLogRows = $resLog ? $resLog->fetch_all(MYSQLI_ASSOC) : [];
$stmtLog->close();

/* computed presentation fields */
$guestNames = array_values(array_filter(array_map(static fn($g)=>$g['KnownAs'] ?? 'Guest', $guestList)));
$guestFull  = implode(', ', $guestNames);
if (count($guestNames) <= 2) {
  $guestHeadline = $guestFull ?: 'Booking';
} else {
  $guestHeadline = $guestNames[0] . ', ' . $guestNames[1] . ' and ' . (count($guestNames) - 2) . ' others';
}

$status   = $booking['Status'] ?? '';
$roomName = $booking['RoomName'] ?? 'Room';

$startsInDays = ($startDT && $startDT > $now) ? $now->diff($startDT)->days : 0;
$nights = 0;
if ($startDT && $endDT) {
  $nights = max(1, (int)$startDT->diff($endDT)->format('%a'));
}

$statusClass = match ($status) {
  'Confirmed' => 'bg-success-subtle text-success-emphasis border border-success-subtle',
  'Tentative' => 'bg-warning-subtle text-warning-emphasis border border-warning-subtle',
  'Cancelled' => 'bg-danger-subtle text-danger-emphasis border border-danger-subtle',
  default     => 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>View Booking · <?= h($booking['BookingRef']) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    body { background:#f6f7fb; }
    .card { border:0; border-radius:16px; box-shadow: 0 1px 2px rgba(16,24,40,.04), 0 8px 24px rgba(16,24,40,.08); }
    .chip { display:inline-flex; align-items:center; gap:.35rem; padding:.3rem .65rem; border-radius:999px; font-size:.8rem; }
    .chip-room { background:#eef2ff; color:#3730a3; }
    .chip-ref { background:#e7f0ff; color:#0b57d0; }
    .avatar { width:44px; height:44px; border-radius:50%; object-fit:cover; background:#e9ecef; border:2px solid #fff; }
    .avatar-lg { width:56px; height:56px; }
    .avatar-stack { display:flex; }
    .avatar-stack .avatar:not(:first-child){ margin-left:-10px; }
    .kv { display:flex; justify-content:space-between; gap:1rem; }
    .kv .k { color:#6c757d; }
    .kv .v { font-weight:600; }
    .progress-thin { height:6px; }
    .timeline { position:relative; padding-left:1.5rem; }
    .timeline::before { content:""; position:absolute; left:.62rem; top:0; bottom:0; width:2px; background:#e9ecef; }
    .t-item { position:relative; margin-bottom:1rem; }
    .t-item::before { content:""; position:absolute; left:-.08rem; top:.25rem; width:10px; height:10px; border-radius:50%; background:#0d6efd; }
    .section-title { font-size:1rem; text-transform:uppercase; letter-spacing:.03em; color:#6c757d; margin-bottom:.5rem; }
    .link-muted { text-decoration:none; color:inherit; }
    .link-muted:hover { color:#0d6efd; }
    .audit-list .list-group-item { padding:.5rem .75rem; }
    .audit-entry { display:flex; justify-content:space-between; align-items:start; gap:.75rem; }
    .audit-entry .left { font-size:.92rem; }
    .audit-entry .right { text-align:right; font-size:.75rem; color:#6c757d; min-width:140px; }
    .scroll-box { max-height: 520px; overflow:auto; border-radius: 12px; border:1px solid rgba(0,0,0,.06); }

    /* Guests list */
    .guest-list { border:1px solid rgba(0,0,0,.06); border-radius:14px; overflow:hidden; background:#fff; }
    .guest-row { display:flex; gap:12px; padding:12px 12px; align-items:flex-start; }
    .guest-row + .guest-row { border-top:1px solid rgba(0,0,0,.06); }
    .guest-avatar { width:42px; height:42px; border-radius:50%; object-fit:cover; background:#e9ecef; flex:0 0 auto; }
    .guest-name { font-weight:600; line-height:1.2; margin:0; }
    .guest-email { font-size:.85rem; color:#6c757d; margin-top:2px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width: 240px; }
    .guest-meta { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
    .mini-chip { font-size:.72rem; padding:.18rem .5rem; border-radius:999px; background:#f1f3f5; border:1px solid #e9ecef; }
    .mini-chip strong { font-weight:700; }
    .mini-chip.more { background:#eef2ff; border-color:#e0e7ff; color:#3730a3; }
  </style>
</head>
<body>

<?php include '../header.php'; ?>
<?php include 'subheader.php'; ?>

<div class="container py-4">

  <?php if (isset($_GET['resent'])): ?>
    <div class="alert alert-success">Confirmation email<?= ((int)$_GET['resent']===1?' was':'s were') ?> queued to <?= (int)$_GET['resent'] ?> recipient<?= ((int)$_GET['resent']===1?'':'s') ?>.</div>
  <?php endif; ?>

  <!-- HERO / HEADER -->
  <div class="card mb-4">
    <div class="card-body p-4">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
          <div class="avatar-stack">
            <?php foreach (array_slice($guestList, 0, 4) as $g): ?>
              <img class="avatar avatar-lg" src="<?= h($g['PhotoURL'] ?: $defaultAvatar) ?>" alt="<?= h($g['KnownAs']) ?>">
            <?php endforeach; ?>
          </div>
          <div>
            <h3 class="mb-1" title="<?= h($guestFull ?: 'Booking') ?>"><?= h($guestHeadline ?: 'Booking') ?></h3>
            <?php if (count($guestNames) > 2): ?>
              <div class="small text-muted"><?= h($guestFull) ?></div>
            <?php endif; ?>
            <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
              <span class="chip chip-room"><?= h($roomName) ?></span>
              <span class="chip <?= $statusClass ?>"><?= h($status) ?: 'Status' ?></span>
              <span class="chip chip-ref">
                Ref: <span id="bookingRefText"><?= h($booking['BookingRef']) ?></span>
                <button class="btn btn-sm btn-link p-0 ms-1" id="copyRefBtn" type="button" aria-label="Copy booking reference">Copy</button>
              </span>
            </div>
          </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <a href="edit_booking.php?BookingID=<?= (int)$bookingID ?>" class="btn btn-primary">Edit Booking</a>
          <a href="welcome_links.php?BookingID=<?= (int)$bookingID ?>" class="btn btn-outline-primary">Get to Know</a>
          <button class="btn btn-outline-primary" id="resendBtn" type="button">Resend Confirmation</button>
          <a href="guest_manager.php" class="btn btn-outline-secondary">← Back to Guest Manager</a>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-4">
    <!-- LEFT -->
    <div class="col-lg-8">
      <div class="card">
        <div class="card-body p-0">
          <ul class="nav nav-tabs px-3 pt-3" role="tablist">
            <li class="nav-item" role="presentation">
              <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab">Overview</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" data-bs-toggle="tab" data-bs-target="#events" type="button" role="tab">Events (<?= count($events) ?>)</button>
            </li>
            <li class="nav-item" role="presentation">
              <button class="nav-link" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab">History (<?= count($auditLogRows) ?>)</button>
            </li>
            <li class="nav-item ms-auto pe-3 py-2 text-muted small d-none d-md-block">
              Created <?= h(date('D, M j Y · H:i', strtotime($booking['CreatedAt'] ?? 'now'))) ?>
            </li>
          </ul>

          <div class="tab-content p-3 p-md-4">
            <!-- OVERVIEW -->
            <div class="tab-pane fade show active" id="overview" role="tabpanel">
              <div class="row g-3">
                <div class="col-md-6">
                  <div class="section-title">When</div>
                  <div class="kv mb-2"><div class="k">From</div><div class="v"><?= h($startDT? $startDT->format('D, M j Y · H:i') : '—') ?></div></div>
                  <div class="kv"><div class="k">To</div><div class="v"><?= h($endDT? $endDT->format('D, M j Y · H:i') : '—') ?></div></div>
                </div>
                <div class="col-md-6">
                  <div class="section-title">Stay</div>
                  <div class="kv mb-2"><div class="k">Duration</div><div class="v"><?= $nights ?> night<?= $nights===1?'':'s' ?></div></div>
                  <div class="kv">
                    <div class="k"><?= ($startDT && $startDT > $now) ? 'Starts in' : (($endDT && $now > $endDT) ? 'Completed' : 'Progress') ?></div>
                    <div class="v">
                      <?php if ($startDT && $startDT > $now): ?>
                        <?= $startsInDays ?> day<?= $startsInDays==1?'':'s' ?>
                      <?php else: ?>
                        <?= $progressPct ?>%
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="progress progress-thin" role="progressbar" aria-valuenow="<?= $progressPct ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar" style="width:<?= $progressPct ?>%"></div>
                  </div>
                  <div class="small text-muted mt-1">
                    <?= h($startDT? $startDT->format('D H:i') : '?') ?> → <?= h($endDT? $endDT->format('D H:i') : '?') ?>
                  </div>
                </div>
                <div class="col-12">
                  <div class="section-title">Notes</div>
                  <div class="border rounded p-3 bg-light-subtle"><?= nl2br(h($booking['GuestNotes'])) ?: '<span class="text-muted">No notes.</span>' ?></div>
                </div>
              </div>
            </div>

            <!-- EVENTS -->
            <div class="tab-pane fade" id="events" role="tabpanel">
              <?php if ($events): ?>
                <div class="timeline">
                  <?php foreach ($events as $ev): ?>
                    <div class="t-item">
                      <div class="d-flex justify-content-between">
                        <div>
                          <strong><?= h($ev['EventTitle'] ?? 'Event') ?></strong>
                          <div class="small text-muted">
                            <?php
                              $allDay = (int)($ev['AllDay'] ?? 0);
                              $s = dt($ev['StartDateTime']); $e = dt($ev['EndDateTime']);
                              echo $allDay ? 'All Day' : h(($s? $s->format('D H:i'):'?') . ' → ' . ($e? $e->format('H:i'):'?'));
                            ?>
                            <?php if (!empty($ev['Location'])): ?>
                              · <span><?= h($ev['Location']) ?></span>
                            <?php endif; ?>
                          </div>
                        </div>
                        <div class="small text-secondary">
                          <?= ($ev['UserID'] ?? 0) == 0 ? 'Joint Event' : 'By: ' . h($ev['CreatedBy'] ?: 'User') ?>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <p class="text-muted mb-0">No events during this booking.</p>
              <?php endif; ?>
            </div>

            <!-- HISTORY -->
            <div class="tab-pane fade" id="history" role="tabpanel">
              <?php if ($auditLogRows): ?>
                <div class="scroll-box">
                  <ul class="list-group list-group-flush audit-list mb-0">
                    <?php foreach ($auditLogRows as $log): ?>
                      <li class="list-group-item">
                        <div class="audit-entry">
                          <div class="left"><strong><?= h($log['ChangeMade']) ?></strong></div>
                          <div class="right">
                            <div><?= h($log['UserName']) ?></div>
                            <div><?= h($log['TimeStamp']) ?></div>
                          </div>
                        </div>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php else: ?>
                <p class="text-muted mb-0">No changes logged.</p>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- RIGHT -->
    <div class="col-lg-4">
      <div class="card">
        <div class="card-body">
          <div class="section-title">Guests (<?= count($guestList) ?>)</div>

          <?php if ($guestList): ?>
            <div class="guest-list">
              <?php foreach ($guestList as $g): ?>
                <?php
                  $gid = (int)$g['ContactID'];
                  $prefs = $g['Preferences'] ?? [];
                  $prefPairs = [];
                  foreach ($prefs as $k => $v) { if ($v) $prefPairs[] = [$k, $v]; }
                  $prefShow = array_slice($prefPairs, 0, 3);
                  $prefMore = max(0, count($prefPairs) - count($prefShow));
                ?>
                <div class="guest-row">
                  <a class="link-muted" href="manage_contacts.php?ContactID=<?= $gid ?>" style="flex:0 0 auto;">
                    <img class="guest-avatar" src="<?= h($g['PhotoURL'] ?: $defaultAvatar) ?>" alt="<?= h($g['KnownAs']) ?>">
                  </a>
                  <div class="flex-grow-1">
                    <a class="link-muted" href="manage_contacts.php?ContactID=<?= $gid ?>">
                      <p class="guest-name mb-0"><?= h($g['KnownAs'] ?: 'Guest') ?></p>
                    </a>
                    <div class="guest-email"><?= $g['Email'] ? h($g['Email']) : 'No email saved' ?></div>

                    <?php if ($prefShow): ?>
                      <div class="guest-meta">
                        <?php foreach ($prefShow as [$k,$v]): ?>
                          <span class="mini-chip" title="<?= h($k) ?>"><?= h($k) ?>: <strong><?= h($v) ?></strong></span>
                        <?php endforeach; ?>
                        <?php if ($prefMore > 0): ?>
                          <span class="mini-chip more" title="<?= h($prefMore) ?> more preferences">+<?= (int)$prefMore ?></span>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <p class="text-muted mb-0">No guests for this booking.</p>
          <?php endif; ?>

          <div class="section-title mt-3">Booking Snapshot</div>
          <div class="kv mb-2"><div class="k">Room</div><div class="v"><?= h($roomName) ?></div></div>
          <div class="kv mb-2"><div class="k">Status</div><div class="v"><?= h($status) ?></div></div>
          <div class="kv mb-2"><div class="k">From</div><div class="v"><?= h($startDT? $startDT->format('D, M j · H:i') : '—') ?></div></div>
          <div class="kv mb-3"><div class="k">To</div><div class="v"><?= h($endDT? $endDT->format('D, M j · H:i') : '—') ?></div></div>

          <div class="kv mb-2"><div class="k"><?= ($startDT && $startDT > $now) ? 'Starts in' : (($endDT && $now > $endDT) ? 'Completed' : 'Progress') ?></div><div class="v"><?= ($startDT && $startDT > $now) ? ($startsInDays . ' day' . ($startsInDays==1?'':'s')) : ($progressPct . '%') ?></div></div>
          <div class="progress progress-thin mb-2" role="progressbar" aria-valuenow="<?= $progressPct ?>" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar" style="width:<?= $progressPct ?>%"></div>
          </div>
          <div class="small text-muted mb-3">
            <?= h($startDT? $startDT->format('D H:i') : '?') ?> → <?= h($endDT? $endDT->format('D H:i') : '?') ?>
          </div>

          <div class="d-grid gap-2">
            <a href="edit_booking.php?BookingID=<?= (int)$bookingID ?>" class="btn btn-primary">Edit Booking</a>
            <button class="btn btn-outline-primary" id="resendBtn2" type="button">Resend Confirmation</button>
            <a href="guest_manager.php" class="btn btn-outline-secondary">Back to Manager</a>
          </div>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- Resend Confirmation Modal -->
<div class="modal fade" id="emailModal" tabindex="-1" aria-labelledby="emailModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form method="POST" class="modal-content" id="resendForm">
      <div class="modal-header">
        <h5 class="modal-title" id="emailModalLabel">Send booking confirmations to guests?</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-3">Guests are <strong>selected by default</strong>. Uncheck to opt out, add missing emails, and choose whether to save new emails to Contacts.</p>
        <div id="emailList"></div>
        <hr>
        <div class="mb-2 fw-semibold">Additional recipients</div>
        <div id="extraEmails" class="mb-2"></div>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="addExtraEmail">+ Add email</button>
      </div>
      <div class="modal-footer">
        <input type="hidden" name="resend_confirm" value="1">
        <input type="hidden" name="confirm_payload" id="confirmPayload">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary" id="confirmEmails">Send & Save</button>
      </div>
    </form>
  </div>
</div>

<?php include '../footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', () => {
  // Copy booking reference
  const copyBtn = document.getElementById('copyRefBtn');
  if (copyBtn) {
    copyBtn.addEventListener('click', async () => {
      const txt = document.getElementById('bookingRefText')?.textContent?.trim() || '';
      try { await navigator.clipboard.writeText(txt); copyBtn.textContent='Copied'; setTimeout(()=>copyBtn.textContent='Copy', 1200); }
      catch { copyBtn.textContent='Failed'; setTimeout(()=>copyBtn.textContent='Copy', 1200); }
    });
  }

  // Prepare resend modal
  const emailModal = new bootstrap.Modal(document.getElementById('emailModal'));
  const emailList  = document.getElementById('emailList');
  const extraWrap  = document.getElementById('extraEmails');
  const addExtra   = document.getElementById('addExtraEmail');
  const payloadEl  = document.getElementById('confirmPayload');

  const GUESTS = <?= json_encode(array_map(function($g){
      return [
        'id'    => (int)$g['ContactID'],
        'name'  => $g['KnownAs'],
        'email' => $g['Email'] ?? '',
        'photo' => $g['PhotoURL'] ?: null
      ];
    }, $guestList), JSON_UNESCAPED_SLASHES|JSON_HEX_APOS) ?>;

  function escapeHtml(s){ return (s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

  function openResendModal(){
    emailList.innerHTML = GUESTS.map(g => {
      const safeName = escapeHtml(g.name||('Guest #'+g.id));
      const safeEmail = escapeHtml(g.email||'');
      return `
        <div class="border rounded p-2 mb-2" data-id="${g.id}">
          <div class="d-flex align-items-center justify-content-between">
            <div class="fw-semibold">${safeName}</div>
            <div><input type="checkbox" class="form-check-input send-check" checked></div>
          </div>
          <div class="row g-2 mt-1">
            <div class="col-md-7">
              <input type="email" class="form-control email-input" placeholder="Email address" value="${safeEmail}">
            </div>
            <div class="col-md-5">
              <div class="form-check mt-1">
                <input class="form-check-input save-check" type="checkbox" id="save-${g.id}" ${g.email ? '' : 'checked'}>
                <label class="form-check-label" for="save-${g.id}">Save to contact</label>
              </div>
            </div>
          </div>
        </div>`;
    }).join('');
    extraWrap.innerHTML = '';
    emailModal.show();
  }

  addExtra.addEventListener('click', ()=>{
    const row = document.createElement('div');
    row.className = 'input-group mb-2';
    row.innerHTML = `
      <span class="input-group-text">To</span>
      <input type="email" class="form-control extra-email" placeholder="name@example.com">
      <button class="btn btn-outline-danger" type="button">Remove</button>
    `;
    row.querySelector('button').addEventListener('click', ()=> row.remove());
    extraWrap.appendChild(row);
  });

  document.getElementById('resendBtn')?.addEventListener('click', openResendModal);
  document.getElementById('resendBtn2')?.addEventListener('click', openResendModal);

  document.getElementById('resendForm').addEventListener('submit', ()=>{
    const payload = [];
    emailList.querySelectorAll('[data-id]').forEach(row=>{
      const id    = parseInt(row.getAttribute('data-id'),10);
      const send  = row.querySelector('.send-check').checked;
      const email = (row.querySelector('.email-input').value||'').trim();
      const save  = row.querySelector('.save-check').checked ? 1 : 0;
      if (send && email) payload.push({ email, contactID:id, saveToContact: save });
    });
    extraWrap.querySelectorAll('.extra-email').forEach(inp=>{
      const email = (inp.value||'').trim();
      if (email) payload.push({ email, contactID:null, saveToContact:0 });
    });
    payloadEl.value = JSON.stringify(payload);
  });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
