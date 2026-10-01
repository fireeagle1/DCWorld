<?php
/*  add_booking.php — smart booking creation (room-first, guest chips, auto email)
    --------------------------------------------------------------------------------
    • Room must be chosen first; start/end disabled until then
    • Auto end-time: RoomID=2 => +6h same day; others => +24h
    • Live conflict detection + events preview
    • Guest chips (cannot submit without guests)
    • Email confirmation defaults ON (opt-out per guest) + extra recipients
    • Saves new guest emails to Contacts when requested
    • Audit log: notes whether confirmations were sent (and how many) or skipped
    • UTF-8-safe email body and content formatting
    -------------------------------------------------------------------------------- */

session_start();
require '../config.php';
require '../auth.php';

// Ensure UTF-8 everywhere
if (function_exists('mb_internal_encoding')) { mb_internal_encoding('UTF-8'); }
if (isset($link) && method_exists($link, 'set_charset')) { @$link->set_charset('utf8mb4'); }
header('Content-Type: text/html; charset=UTF-8');

if (!isset($_SESSION['userID'])) {
  header("Location: login.php");
  exit();
}

$userID = (int)$_SESSION['userID'];

/* ──────────────────────────────────────────────
   Helpers
──────────────────────────────────────────────── */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function dt(?string $s): ?DateTime { return $s ? new DateTime($s) : null; }
function fmt_nice_date(?string $iso): string {
  if (!$iso) return '';
  $d = dt($iso);
  if (!$d) return '';
  // e.g., Sat 16 Aug 2025
  return $d->format('D j M Y');
}

/* ──────────────────────────────────────────────
   JSON endpoints
──────────────────────────────────────────────── */

// POST: conflict checker
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['conflict_check'])) {
  header('Content-Type: application/json; charset=UTF-8');
  $roomID = (int)($_POST['roomID'] ?? 0);
  $start  = $_POST['start'] ?? '';
  $end    = $_POST['end'] ?? '';
  if (!$roomID || !$start || !$end) { echo json_encode(['ok'=>false,'error'=>'Missing params']); exit; }

  $stmt = $link->prepare(
    "SELECT BookingRef, StartDateTime, EndDateTime, GuestsJSON
       FROM Bookings
      WHERE RoomID = ?
        AND NOT (EndDateTime <= ? OR StartDateTime >= ?)
      ORDER BY StartDateTime ASC"
  );
  $stmt->bind_param("iss", $roomID, $start, $end);
  $stmt->execute();
  $res = $stmt->get_result();

  $conflicts = [];
  while ($row = $res->fetch_assoc()) {
    $ids = json_decode($row['GuestsJSON'] ?? '[]', true)['guests'] ?? [];
    $names = [];
    if ($ids) {
      $q = "SELECT KnownAs FROM Contacts WHERE ContactID IN (" . implode(',', array_map('intval',$ids)) . ")";
      $r = $link->query($q);
      while ($g = $r->fetch_assoc()) { $names[] = $g['KnownAs']; }
    }
    $conflicts[] = [
      'ref'   => $row['BookingRef'],
      'start' => $row['StartDateTime'],
      'end'   => $row['EndDateTime'],
      'guests'=> $names
    ];
  }
  echo json_encode(['ok'=>true,'conflicts'=>$conflicts]); exit;
}

// GET: events preview between start/end
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['events_between'])) {
  header('Content-Type: application/json; charset=UTF-8');
  $start = $_GET['start'] ?? '';
  $end   = $_GET['end'] ?? '';
  if (!$start || !$end) { echo json_encode(['ok'=>false,'error'=>'Missing params']); exit; }

  $stmt = $link->prepare(
    "SELECT e.*, CASE WHEN e.UserID=0 THEN '' ELSE u.Name END AS CreatedBy
       FROM Events e
  LEFT JOIN DC_Users u ON e.UserID = u.UserID
      WHERE (e.StartDateTime BETWEEN ? AND ?)
         OR (e.EndDateTime BETWEEN ? AND ?)
      ORDER BY e.StartDateTime ASC"
  );
  $stmt->bind_param("ssss", $start, $end, $start, $end);
  $stmt->execute();
  $events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  echo json_encode(['ok'=>true, 'events'=>$events]); exit;
}

/* ──────────────────────────────────────────────
   Create booking
──────────────────────────────────────────────── */

$formError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['conflict_check'])) {

  $roomID   = (int)($_POST['RoomID'] ?? 0);
  $start    = $_POST['StartDateTime'] ?? '';
  $end      = $_POST['EndDateTime'] ?? '';
  $occasion = $_POST['Occasion'] ?? '';
  $notes    = $_POST['GuestNotes'] ?? '';
  $status   = $_POST['Status'] ?? 'Pencilled';

  $guestIDsArray = array_map('intval', $_POST['Guests'] ?? []);
  if (!$roomID) { $formError = 'Please select a room.'; }
  if (!$guestIDsArray) { $formError = 'Please add at least one guest.'; }

  if (!$formError) {
    $guestsJSON = json_encode(['guests' => $guestIDsArray]);

    // Unique BookingRef e.g. "2025-742"
    do {
      $bookingRef = date('Y') . '-' . rand(100, 999);
      $stmtCheck = $link->prepare("SELECT COUNT(*) FROM Bookings WHERE BookingRef = ?");
      $stmtCheck->bind_param("s", $bookingRef);
      $stmtCheck->execute();
      $stmtCheck->bind_result($countRef);
      $stmtCheck->fetch();
      $stmtCheck->close();
    } while ($countRef > 0);

    // Insert booking
    $stmt = $link->prepare(
      "INSERT INTO Bookings
        (BookingRef, RoomID, StartDateTime, EndDateTime, Occasion, GuestNotes, GuestsJSON, Status)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param("sissssss", $bookingRef, $roomID, $start, $end, $occasion, $notes, $guestsJSON, $status);
    $stmt->execute();
    $bookingID = (int)$stmt->insert_id;
    $stmt->close();

    // Audit: created
    $stmtAudit = $link->prepare(
      "INSERT INTO BookingsAuditLog (InitiatedBy, TargetBooking, ChangeMade, TimeStamp)
       VALUES (?, ?, 'Booking created', NOW())"
    );
    $stmtAudit->bind_param("ii", $userID, $bookingID);
    $stmtAudit->execute();
    $stmtAudit->close();

    // Resolve names/emails for selected guests
    $guestNames = [];
    $guestEmailsById = [];
    if ($guestIDsArray) {
      $in = implode(',', $guestIDsArray);
      $resG = $link->query("SELECT ContactID, KnownAs, Email FROM Contacts WHERE ContactID IN ($in)");
      while ($row = $resG->fetch_assoc()) {
        $guestNames[] = $row['KnownAs'];
        $guestEmailsById[(int)$row['ContactID']] = $row['Email'] ?? '';
      }
    }
    $guestNamesString = $guestNames ? implode(', ', $guestNames) : 'None';

    // Room name for emails
    $roomName = '';
    $r = $link->query("SELECT Name FROM HouseLocations WHERE RoomID = ".(int)$roomID);
    if ($row = $r->fetch_assoc()) { $roomName = $row['Name']; }

    // Email decisions from modal (defaults ON; user can opt-out)
    $sendConfirm = isset($_POST['send_confirm']) && (int)$_POST['send_confirm'] === 1;
    $payload = json_decode($_POST['confirm_payload'] ?? '[]', true) ?: [];

    $sentCount = 0;
    if ($sendConfirm) {
      foreach ($payload as $rec) {
        $email = trim($rec['email'] ?? '');
        if ($email === '') continue;
        $cid   = isset($rec['contactID']) ? (int)$rec['contactID'] : null;
        $save  = !empty($rec['saveToContact']) ? 1 : 0;

        // Save new email to contact if requested
        if ($save && $cid) {
          $stmtU = $link->prepare("UPDATE Contacts SET Email = ? WHERE ContactID = ?");
          $stmtU->bind_param("si", $email, $cid);
          $stmtU->execute();
          $stmtU->close();
        }

        // Compose UTF-8 friendly guest confirmation (no times)
        $subject = "Your stay is booked! Ref {$bookingRef}";
        $content =
"Hi there!

We're excited to confirm your upcoming stay with Daniel and Charlie.

• Booking Ref: {$bookingRef}
• Room: ".($roomName !== '' ? $roomName : "Room #{$roomID}")."
• Check-in: Arriving on ".fmt_nice_date($start)."
• Check-out: Leaving on ".fmt_nice_date($end)."

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
    }

    // Always email admins
    $adminRecipients = ['charlie@ckeneterprises.co.uk', 'cromptomdaniel234@gmail.com'];
    $subjectAdmin = "New Booking Added: {$bookingRef}";
    $contentAdmin =
"A new booking has been added.

Booking Ref: {$bookingRef}
Room: ".($roomName !== '' ? $roomName : "Room #{$roomID}")."
From: {$start}
To: {$end}
Occasion: {$occasion}
Guests: {$guestNamesString}
Notes: {$notes}";
    foreach ($adminRecipients as $emailTo) {
      $stmtEmail = $link->prepare(
        "INSERT INTO DCEmailsLog (`to`,`Subject`,`Content`,`DateTimeSent`,`Sent`)
         VALUES (?, ?, ?, NOW(), 'No')"
      );
      $stmtEmail->bind_param("sss", $emailTo, $subjectAdmin, $contentAdmin);
      $stmtEmail->execute();
      $stmtEmail->close();
    }

    // Audit: record whether confirmations were sent or skipped
    $auditMsg = $sendConfirm
      ? "Guest confirmations sent ({$sentCount})"
      : "Guest confirmations skipped";
    $stmtAudit2 = $link->prepare(
      "INSERT INTO BookingsAuditLog (InitiatedBy, TargetBooking, ChangeMade, TimeStamp)
       VALUES (?, ?, ?, NOW())"
    );
    $stmtAudit2->bind_param("iis", $userID, $bookingID, $auditMsg);
    $stmtAudit2->execute();
    $stmtAudit2->close();

    // Redirect to view page
    header("Location: view_booking.php?BookingID=".$bookingID);
    exit();
  }
}

/* ──────────────────────────────────────────────
   Load data for form
──────────────────────────────────────────────── */
$roomsRes = $link->query("SELECT RoomID, Name FROM HouseLocations ORDER BY Name");
$rooms = $roomsRes->fetch_all(MYSQLI_ASSOC);

$contactsRes = $link->query("SELECT ContactID, KnownAs, PhotoURL, Email FROM Contacts ORDER BY KnownAs");
$contacts = $contactsRes->fetch_all(MYSQLI_ASSOC);

include '../header.php';
include 'subheader.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Add New Booking</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap 5.3 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    body { background:#f6f7fb; }
    .card { border:0; border-radius:16px; box-shadow: 0 1px 2px rgba(16,24,40,.04), 0 8px 24px rgba(16,24,40,.08); }
    .section-title { font-size:1rem; text-transform:uppercase; letter-spacing:.03em; color:#6c757d; margin-bottom:.5rem; }
    .chip-card { border:1px solid #e9ecef; border-radius:12px; padding:.5rem .75rem; display:flex; align-items:center; gap:.6rem; background:#fff; }
    .avatar { width:40px; height:40px; border-radius:50%; object-fit:cover; background:#e9ecef; }
    .guest-grid { display:flex; flex-wrap:wrap; gap:.5rem; }
    .dropdown-menu.show { max-height: 260px; overflow:auto; }
    .dropdown-item.active, .dropdown-item:active { background-color: #e7f1ff; color:#0b57d0; }
    .timeline { position:relative; padding-left:1.25rem; }
    .timeline::before { content:""; position:absolute; left:.5rem; top:0; bottom:0; width:2px; background:#e9ecef; }
    .t-item { position:relative; margin-bottom:1rem; }
    .t-item::before { content:""; position:absolute; left:-.15rem; top:.35rem; width:10px; height:10px; border-radius:50%; background:#0d6efd; }
  </style>
</head>
<body>

<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Add New Booking</h2>
    <a href="guest_manager.php" class="btn btn-outline-secondary">Cancel</a>
  </div>

  <?php if ($formError): ?>
    <div class="alert alert-danger"><?= h($formError) ?></div>
  <?php endif; ?>

  <form method="POST" id="addForm" class="row g-4">
    <!-- LEFT: core -->
    <div class="col-lg-8">
      <div class="card">
        <div class="card-body p-3 p-md-4">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Room <span class="text-danger">*</span></label>
              <select name="RoomID" id="roomID" class="form-select" required>
                <option value="">Choose a room</option>
                <?php foreach ($rooms as $room): ?>
                  <option value="<?= (int)$room['RoomID'] ?>"><?= h($room['Name']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Start and end will be enabled after selecting a room.</div>
            </div>

            <div class="col-md-6">
              <label class="form-label">Start Date/Time <span class="text-danger">*</span></label>
              <input type="datetime-local" class="form-control" name="StartDateTime" id="startDT" required disabled>
            </div>

            <div class="col-md-6">
              <label class="form-label">End Date/Time <span class="text-danger">*</span></label>
              <input type="datetime-local" class="form-control" name="EndDateTime" id="endDT" required disabled>
              <div class="form-text" id="endHint"></div>
            </div>

            <div class="col-12">
              <label class="form-label">Occasion</label>
              <input type="text" name="Occasion" class="form-control">
            </div>

            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea name="GuestNotes" class="form-control" rows="4"></textarea>
            </div>

            <div class="col-md-6">
              <label class="form-label">Status</label>
              <select name="Status" class="form-select">
                <option value="Pencilled">Pencilled</option>
                <option value="Confirmed">Confirmed</option>
              </select>
            </div>
          </div>

          <!-- Conflicts -->
          <div class="mt-4">
            <div class="section-title">Conflicts</div>
            <div id="conflictsArea" class="small text-muted">Select room, start and end to check.</div>
          </div>
        </div>
      </div>

      <div class="d-flex gap-2 mt-3">
        <button type="button" id="submitBtn" class="btn btn-success">Add Booking</button>
        <a href="guest_manager.php" class="btn btn-outline-secondary">Cancel</a>
      </div>
    </div>

    <!-- RIGHT: guests + events -->
    <div class="col-lg-4">
      <div class="card mb-4">
        <div class="card-body p-3 p-md-4">
          <div class="section-title">Guests <span class="text-danger">*</span></div>

          <div class="mb-3 dropdown">
            <input type="text" id="guestSearch" class="form-control"
                   placeholder="Add guest by name…" autocomplete="off"
                   data-bs-toggle="dropdown" aria-expanded="false" aria-haspopup="true" disabled>
            <ul id="guestResults" class="dropdown-menu w-100" aria-labelledby="guestSearch" role="listbox"></ul>
            <div class="form-text">Type to search, press Enter or click to add.</div>
          </div>

          <div id="guestCards" class="guest-grid"></div>
          <div id="guestError" class="text-danger small mt-2" hidden>Please add at least one guest.</div>
        </div>
      </div>

      <div class="card">
        <div class="card-body p-3 p-md-4">
          <div class="section-title">Events During Stay</div>
          <div id="eventsArea" class="timeline">
            <div class="text-muted small">Pick room, start and end to preview overlaps.</div>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>

<!-- Confirmation Email Modal (default ON per guest) -->
<div class="modal fade" id="emailModal" tabindex="-1" aria-labelledby="emailModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="emailModalLabel">Send booking confirmations to guests?</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-3">We’ll send a friendly confirmation to your guests. They’re <strong>selected by default</strong>. Uncheck to opt out, add missing emails, and choose whether to save new emails to Contacts.</p>
        <div id="emailList"></div>

        <hr>
        <div class="mb-2 fw-semibold">Additional recipients</div>
        <div id="extraEmails" class="mb-2"></div>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="addExtraEmail">+ Add email</button>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" id="skipEmails">Skip</button>
        <button type="button" class="btn btn-primary" id="confirmEmails">Send & Save</button>
      </div>
    </div>
  </div>
</div>

<?php include '../footer.php'; ?>

<!-- Bootstrap 5 bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
  const defaultAvatar = 'https://assets.dcworld.uk/images/contacts/Black%20and%20white%20organic%20farmhouse%20mountain%20landscape%20hand%20drawn%20logo.png';

  // Contacts payload for typeahead + emails
  const CONTACTS = <?= json_encode(array_map(function($c){
      return [
        'id'    => (int)$c['ContactID'],
        'name'  => $c['KnownAs'],
        'photo' => $c['PhotoURL'] ?: null,
        'email' => $c['Email'] ?? ''
      ];
    }, $contacts), JSON_UNESCAPED_SLASHES|JSON_HEX_APOS) ?>;

  // Elements
  const roomEl  = document.getElementById('roomID');
  const startEl = document.getElementById('startDT');
  const endEl   = document.getElementById('endDT');
  const endHint = document.getElementById('endHint');

  const conflictsArea = document.getElementById('conflictsArea');
  const eventsArea    = document.getElementById('eventsArea');

  const guestSearch  = document.getElementById('guestSearch');
  const guestResults = document.getElementById('guestResults');
  const guestCards   = document.getElementById('guestCards');
  const guestError   = document.getElementById('guestError');
  const dropdown     = new bootstrap.Dropdown(guestSearch, { autoClose: 'outside' });

  const form      = document.getElementById('addForm');
  const submitBtn = document.getElementById('submitBtn');

  // Email modal
  const emailModalEl = document.getElementById('emailModal');
  const emailModal   = new bootstrap.Modal(emailModalEl);
  const emailList    = document.getElementById('emailList');
  const extraEmails  = document.getElementById('extraEmails');
  const addExtraBtn  = document.getElementById('addExtraEmail');
  const skipBtn      = document.getElementById('skipEmails');
  const confirmBtn   = document.getElementById('confirmEmails');

  // State
  let endTouched = false;
  let activeIndex = -1;

  // Enable fields after room selected
  roomEl.addEventListener('change', () => {
    const hasRoom = !!roomEl.value;
    startEl.disabled = !hasRoom;
    endEl.disabled   = !hasRoom;
    guestSearch.disabled = !hasRoom;
    if (hasRoom && startEl.value) applyAutoEnd();
    triggerRecalc();
  });

  endEl.addEventListener('input', () => endTouched = true);

  // Helpers
  const pad = n => String(n).padStart(2,'0');
  const isoLocal = (d) => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  const clampSameDay = (end, start) => {
    const last = new Date(start);
    last.setHours(23,59,0,0);
    return (end > last) ? last : end;
  };

  function applyAutoEnd() {
    if (!startEl.value || !roomEl.value || endTouched) return;
    const start = new Date(startEl.value);
    if (isNaN(start.getTime())) return;

    let end;
    if (String(roomEl.value) === '2') {
      end = new Date(start.getTime() + 6*60*60*1000);
      end = clampSameDay(end, start);
      endHint.textContent = 'Room 2 defaults to +6 hours (same day).';
    } else {
      end = new Date(start.getTime() + 24*60*60*1000);
      endHint.textContent = 'Default is +24 hours from start.';
    }
    endEl.value = isoLocal(end);
  }

  startEl.addEventListener('change', () => { applyAutoEnd(); triggerRecalc(); });
  endEl  .addEventListener('change', ()  => { triggerRecalc(); });

  // Debounced conflict + events refresh
  let t;
  function triggerRecalc(){ clearTimeout(t); t = setTimeout(()=>{ checkConflicts(); refreshEvents(); }, 300); }

  async function checkConflicts() {
    const roomID = roomEl.value, start = startEl.value, end = endEl.value;
    if (!roomID || !start || !end) { conflictsArea.textContent = 'Select room, start and end to check.'; return; }
    const payload = new URLSearchParams({ conflict_check:'1', roomID, start, end });
    const res = await fetch('', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: payload.toString() });
    const data = await res.json().catch(()=>({ok:false}));
    if (!data.ok) { conflictsArea.textContent = '—'; return; }
    if (!data.conflicts.length) {
      conflictsArea.innerHTML = '<span class="text-success">No conflicts detected.</span>';
    } else {
      conflictsArea.innerHTML = data.conflicts.map(c => {
        const guests = (c.guests && c.guests.length) ? ('Guests: ' + c.guests.map(escapeHtml).join(', ')) : 'No guests listed';
        return `<div class="alert alert-warning py-2 px-3 mb-2">
                  <div><strong>${escapeHtml(c.ref)}</strong></div>
                  <div class="small">${escapeHtml(c.start)} → ${escapeHtml(c.end)}</div>
                  <div class="small text-muted">${escapeHtml(guests)}</div>
                </div>`;
      }).join('');
    }
  }

  async function refreshEvents() {
    const start = startEl.value, end = endEl.value;
    if (!start || !end) { eventsArea.innerHTML = '<div class="text-muted small">Pick room, start and end to preview overlaps.</div>'; return; }
    const qs = new URLSearchParams({ events_between:'1', start, end });
    const res = await fetch('?' + qs.toString());
    const data = await res.json().catch(()=>({ok:false}));
    if (!data.ok) { eventsArea.innerHTML = '<div class="text-muted small">—</div>'; return; }
    if (!data.events.length) { eventsArea.innerHTML = '<div class="text-muted small">No events during this time.</div>'; return; }
    eventsArea.innerHTML = data.events.map(ev => {
      const allDay = Number(ev.AllDay || 0) === 1;
      const s = new Date(ev.StartDateTime); const e = new Date(ev.EndDateTime);
      const fmtT = (d) => d.toLocaleTimeString(undefined,{hour:'2-digit',minute:'2-digit'});
      const fmtD = (d) => d.toLocaleDateString(undefined,{weekday:'short', month:'short', day:'numeric'});
      return `<div class="t-item">
        <div class="d-flex justify-content-between">
          <div>
            <strong>${escapeHtml(ev.EventTitle || 'Event')}</strong>
            <div class="small text-muted">
              ${allDay ? fmtD(s) + ' (All Day)' : `${fmtD(s)} · ${fmtT(s)} → ${fmtT(e)}`}
              ${ev.Location ? ' · ' + escapeHtml(ev.Location) : ''}
            </div>
          </div>
          <div class="small text-secondary">${ev.UserID == 0 ? 'Joint' : 'By: ' + escapeHtml(ev.CreatedBy || 'User')}</div>
        </div>
      </div>`;
    }).join('');
  }

  // Guest chips
  function currentGuestIds() {
    return Array.from(guestCards.querySelectorAll('.chip-card')).map(el => parseInt(el.getAttribute('data-id'), 10));
  }
  function escapeHtml(s){ return (s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
  function clearResults(){ guestResults.innerHTML=''; activeIndex=-1; dropdown.hide(); guestSearch.setAttribute('aria-expanded','false'); }
  function openResults(){ if (!guestResults.innerHTML.trim()) return; dropdown.show(); guestSearch.setAttribute('aria-expanded','true'); }

  function renderResults(query){
    guestResults.innerHTML=''; activeIndex=-1;
    const q = (query||'').trim().toLowerCase();
    if (!q){ clearResults(); return; }
    const taken = new Set(currentGuestIds());
    const matches = CONTACTS.filter(c => !taken.has(c.id) && c.name && c.name.toLowerCase().includes(q)).slice(0,12);
    if (!matches.length){ guestResults.innerHTML='<li class="dropdown-item text-muted" role="option" aria-disabled="true">No matches</li>'; openResults(); return; }
    for (const c of matches){
      const li = document.createElement('li');
      li.className = 'dropdown-item d-flex align-items-center gap-2';
      li.setAttribute('data-id', String(c.id));
      li.setAttribute('role','option');
      li.innerHTML = `<img src="${(c.photo||defaultAvatar)}" class="rounded-circle" style="width:28px;height:28px;object-fit:cover;"> <span>${escapeHtml(c.name)}</span>`;
      li.addEventListener('click', ()=>{ addGuestCard(c); guestSearch.value=''; clearResults(); guestSearch.focus(); });
      guestResults.appendChild(li);
    }
    openResults();
  }

  function addGuestCard(c){
    if (guestCards.querySelector(`.chip-card[data-id="${c.id}"]`)) return;
    const card = document.createElement('div');
    card.className='chip-card'; card.setAttribute('data-id', String(c.id));
    card.innerHTML = `
      <img class="avatar" src="${(c.photo||defaultAvatar)}" alt="${escapeHtml(c.name)}">
      <div class="me-1">
        <div class="fw-semibold">${escapeHtml(c.name)}</div>
        <div class="text-muted small">ID #${c.id}${c.email ? ' · ' + escapeHtml(c.email) : ''}</div>
      </div>
      <button class="btn btn-sm btn-outline-danger ms-auto remove-guest" type="button">Remove</button>
      <input type="hidden" name="Guests[]" value="${c.id}">
    `;
    card.querySelector('.remove-guest').addEventListener('click', ()=> card.remove());
    guestCards.appendChild(card);
  }

  guestSearch.addEventListener('input', ()=> renderResults(guestSearch.value));
  guestSearch.addEventListener('focus', ()=> renderResults(guestSearch.value));
  guestSearch.addEventListener('keydown', (e)=>{
    const items = guestResults.querySelectorAll('.dropdown-item');
    if (e.key==='ArrowDown'){ e.preventDefault(); if (!items.length) return; activeIndex = (activeIndex+1)%items.length; items.forEach((el,i)=>el.classList.toggle('active',i===activeIndex)); }
    else if (e.key==='ArrowUp'){ e.preventDefault(); if (!items.length) return; activeIndex = (activeIndex-1+items.length)%items.length; items.forEach((el,i)=>el.classList.toggle('active',i===activeIndex)); }
    else if (e.key==='Enter'){ if (items.length){ e.preventDefault(); if (activeIndex<0) activeIndex=0; items[activeIndex].click(); } }
    else if (e.key==='Escape'){ clearResults(); }
  });
  guestSearch.addEventListener('blur', ()=> setTimeout(clearResults, 150));
  guestCards.addEventListener('click', (e)=>{ const btn=e.target.closest('.remove-guest'); if (!btn) return; btn.closest('.chip-card')?.remove(); });

  // Validate guests before submit
  function ensureGuests(){
    const has = currentGuestIds().length > 0;
    guestError.hidden = has;
    return has;
  }

  // Submit → email modal (default ON)
  submitBtn.addEventListener('click', ()=>{
    if (!roomEl.value){ roomEl.focus(); return; }
    if (!startEl.value || !endEl.value){ startEl.focus(); return; }
    if (!ensureGuests()){ guestSearch.focus(); return; }

    // Build guest email rows (checkbox defaults ON)
    const ids = currentGuestIds();
    emailList.innerHTML = ids.map(id=>{
      const c = CONTACTS.find(x=>x.id===id) || {name:'Guest '+id, email:''};
      const safeName = escapeHtml(c.name);
      const safeEmail = escapeHtml(c.email || '');
      return `<div class="border rounded p-2 mb-2" data-id="${id}">
        <div class="d-flex align-items-center justify-content-between">
          <div class="fw-semibold">${safeName} <span class="text-muted small">#${id}</span></div>
          <div><input type="checkbox" class="form-check-input send-check" checked></div>
        </div>
        <div class="row g-2 mt-1">
          <div class="col-md-7">
            <input type="email" class="form-control email-input" placeholder="Email address" value="${safeEmail}">
          </div>
          <div class="col-md-5">
            <div class="form-check mt-1">
              <input class="form-check-input save-check" type="checkbox" id="save-${id}" ${c.email ? '' : 'checked'}>
              <label class="form-check-label" for="save-${id}">Save to contact</label>
            </div>
          </div>
        </div>
      </div>`;
    }).join('');

    // Clear extras and open
    extraEmails.innerHTML='';
    emailModal.show();
  });

  // Extra recipients
  document.getElementById('addExtraEmail').addEventListener('click', ()=>{
    const row = document.createElement('div');
    row.className='input-group mb-2';
    row.innerHTML = `<span class="input-group-text">To</span>
                     <input type="email" class="form-control extra-email" placeholder="name@example.com">
                     <button class="btn btn-outline-danger" type="button">Remove</button>`;
    row.querySelector('button').addEventListener('click', ()=> row.remove());
    extraEmails.appendChild(row);
  });

  // Skip → send admin only
  document.getElementById('skipEmails').addEventListener('click', ()=>{
    appendConfirmPayload([], false);
    form.submit();
  });

  // Confirm → build payload (only checked with email)
  document.getElementById('confirmEmails').addEventListener('click', ()=>{
    const payload = [];
    emailList.querySelectorAll('[data-id]').forEach(row=>{
      const id    = parseInt(row.getAttribute('data-id'),10);
      const send  = row.querySelector('.send-check').checked;
      const email = (row.querySelector('.email-input').value||'').trim();
      const save  = row.querySelector('.save-check').checked ? 1 : 0;
      if (send && email) payload.push({ email, contactID:id, saveToContact: save });
    });
    extraEmails.querySelectorAll('.extra-email').forEach(inp=>{
      const email = (inp.value||'').trim();
      if (email) payload.push({ email, contactID:null, saveToContact:0 });
    });
    appendConfirmPayload(payload, true);
    form.submit();
  });

  function appendConfirmPayload(payload, send){
    // Remove prior hidden inputs
    form.querySelectorAll('input[name="confirm_payload"], input[name="send_confirm"]').forEach(el=>el.remove());
    const p = document.createElement('input'); p.type='hidden'; p.name='confirm_payload'; p.value=JSON.stringify(payload);
    const s = document.createElement('input'); s.type='hidden'; s.name='send_confirm'; s.value= send ? '1' : '0';
    form.appendChild(p); form.appendChild(s);
  }

  // Utilities
  function escapeHtml(s){ return (s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

})();
</script>
</body>
</html>
