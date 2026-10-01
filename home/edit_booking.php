<?php
/*  edit_booking.php — edit UI with guest chips, conflict checks, delete, and granular audit logging
    ------------------------------------------------------------------------------------------------
    • Delete button with confirmation modal (server-side).
    • On save: compute diffs vs previous values and write one audit row per change.
    • Guest diffs include readable "added"/"removed" names.
    • Uses Bootstrap 5.3 (no jQuery).
    ------------------------------------------------------------------------------------------------ */

session_start();
require '../config.php';
require '../auth.php';

/* ── security: auth gate ───────────────────────────────────────── */
if (!isset($_SESSION['userID'])) {
  header("Location: login.php");
  exit();
}
$userID = (int)$_SESSION['userID'];

/* ── helpers ───────────────────────────────────────────────────── */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function fmtDT(?string $s): string {
  if (!$s) return '—';
  $t = strtotime($s);
  if ($t === false) return h($s);
  return date('d M Y H:i', $t); // e.g., 01 Apr 2025 15:00
}
function fetch_single_val(mysqli $link, string $sql, string $types = '', ...$params): ?string {
  $stmt = $link->prepare($sql);
  if ($types) $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $stmt->bind_result($v);
  $ok = $stmt->fetch();
  $stmt->close();
  return $ok ? $v : null;
}

/* ── inline conflict check endpoint (JSON) ────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['conflict_check'])) {
  $roomID    = (int)($_POST['roomID'] ?? 0);
  $start     = $_POST['start'] ?? '';
  $end       = $_POST['end'] ?? '';
  $bookingID = (int)($_POST['bookingID'] ?? 0);

  header('Content-Type: application/json');

  if (!$roomID || !$start || !$end) {
    echo json_encode(['ok'=>false, 'error'=>'Missing parameters']); exit;
  }

  $stmt = $link->prepare(
    "SELECT BookingRef, StartDateTime, EndDateTime, GuestsJSON
       FROM Bookings
      WHERE RoomID = ?
        AND BookingID != ?
        AND NOT (EndDateTime <= ? OR StartDateTime >= ?)
      ORDER BY StartDateTime ASC"
  );
  $stmt->bind_param("iiss", $roomID, $bookingID, $start, $end);
  $stmt->execute();
  $result = $stmt->get_result();

  $conflicts = [];
  while ($conflict = $result->fetch_assoc()) {
    $guestIDs = json_decode($conflict['GuestsJSON'] ?? '[]', true)['guests'] ?? [];
    $guestNames = [];
    if ($guestIDs) {
      $ids = implode(',', array_map('intval',$guestIDs));
      $guestResult = $link->query("SELECT KnownAs FROM Contacts WHERE ContactID IN ($ids)");
      while ($g = $guestResult->fetch_assoc()) { $guestNames[] = $g['KnownAs']; }
    }
    $conflicts[] = [
      'BookingRef' => $conflict['BookingRef'],
      'Start'      => $conflict['StartDateTime'],
      'End'        => $conflict['EndDateTime'],
      'Guests'     => $guestNames,
    ];
  }

  echo json_encode(['ok'=>true, 'conflicts'=>$conflicts]); exit;
}

/* ── param guard ───────────────────────────────────────────────── */
if (!isset($_GET['BookingID'])) { echo "No booking ID provided."; exit(); }
$bookingID = (int)$_GET['BookingID'];

/* ── load booking (BEFORE edits) ───────────────────────────────── */
$stmt = $link->prepare("SELECT * FROM Bookings WHERE BookingID = ?");
$stmt->bind_param("i", $bookingID);
$stmt->execute();
$result  = $stmt->get_result();
$booking = $result->fetch_assoc();
$stmt->close();
if (!$booking) { echo "Booking not found."; exit(); }

/* ── DELETE handler (with audit) ──────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_booking']) && (int)$_POST['delete_booking'] === 1) {
  // Log intent BEFORE delete (so TargetBooking is valid)
  $stmtAudit = $link->prepare(
    "INSERT INTO BookingsAuditLog (InitiatedBy, TargetBooking, ChangeMade, TimeStamp)
     VALUES (?, ?, 'Booking deleted', NOW())"
  );
  $stmtAudit->bind_param("ii", $userID, $bookingID);
  $stmtAudit->execute();
  $stmtAudit->close();

  // Delete the booking
  $stmtDel = $link->prepare("DELETE FROM Bookings WHERE BookingID = ?");
  $stmtDel->bind_param("i", $bookingID);
  $stmtDel->execute();
  $stmtDel->close();

  // Redirect to manager
  header("Location: guest_manager.php");
  exit();
}

/* ── UPDATE handler with granular audit ───────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['conflict_check']) && !isset($_POST['delete_booking'])) {
  // Capture BEFORE state
  $before = $booking;

  // Gather incoming
  $roomID    = (int)($_POST['RoomID'] ?? 0);
  $start     = $_POST['StartDateTime'] ?? '';
  $end       = $_POST['EndDateTime'] ?? '';
  $occasion  = $_POST['Occasion'] ?? '';
  $notes     = $_POST['GuestNotes'] ?? '';
  $status    = $_POST['Status'] ?? '';
  $guestIDs  = array_map('intval', $_POST['Guests'] ?? []);
  $guestsJSON= json_encode(['guests' => $guestIDs]);

  // Update
  $stmtU = $link->prepare(
    "UPDATE Bookings
        SET RoomID = ?, StartDateTime = ?, EndDateTime = ?, Occasion = ?, GuestNotes = ?, GuestsJSON = ?, Status = ?
      WHERE BookingID = ?"
  );
  $stmtU->bind_param("issssssi", $roomID, $start, $end, $occasion, $notes, $guestsJSON, $status, $bookingID);
  $stmtU->execute();
  $stmtU->close();

  // Build readable diffs
  $changes = [];

  // Fetch actor name (for readable audit line)
  $actorName = fetch_single_val($link, "SELECT Name FROM DC_Users WHERE UserID = ?", "i", $userID) ?? 'User';

  // Rooms map for naming
  $roomsMap = [];
  $rRes = $link->query("SELECT RoomID, Name FROM HouseLocations");
  while ($r = $rRes->fetch_assoc()) { $roomsMap[(int)$r['RoomID']] = $r['Name']; }

  // Contacts map for names
  $contactsMap = [];
  $cRes = $link->query("SELECT ContactID, KnownAs FROM Contacts");
  while ($c = $cRes->fetch_assoc()) { $contactsMap[(int)$c['ContactID']] = $c['KnownAs']; }

  // Room change
  if ((int)$before['RoomID'] !== $roomID) {
    $from = $roomsMap[(int)$before['RoomID']] ?? ('Room #'.(int)$before['RoomID']);
    $to   = $roomsMap[$roomID] ?? ('Room #'.$roomID);
    $changes[] = "$actorName changed room: $from → $to";
  }

  // Dates
  if ($before['StartDateTime'] !== $start || $before['EndDateTime'] !== $end) {
    $sFrom = fmtDT($before['StartDateTime']); $sTo = fmtDT($start);
    $eFrom = fmtDT($before['EndDateTime']);   $eTo = fmtDT($end);
    if ($before['StartDateTime'] !== $start) $changes[] = "$actorName changed start: $sFrom → $sTo";
    if ($before['EndDateTime']   !== $end)   $changes[] = "$actorName changed end: $eFrom → $eTo";
  }

  // Occasion
  if ((string)($before['Occasion'] ?? '') !== (string)$occasion) {
    $from = $before['Occasion'] ? '"'.h($before['Occasion']).'"' : '—';
    $to   = $occasion ? '"'.h($occasion).'"' : '—';
    $changes[] = "$actorName changed occasion: $from → $to";
  }

  // Notes (don’t dump full text; log that it changed)
  if ((string)($before['GuestNotes'] ?? '') !== (string)$notes) {
    $changes[] = "$actorName updated notes";
  }

  // Status
  if ((string)($before['Status'] ?? '') !== (string)$status) {
    $from = $before['Status'] ?: '—';
    $to   = $status ?: '—';
    $changes[] = "$actorName changed status: $from → $to";
  }

  // Guests diff
  $beforeGuests = json_decode($before['GuestsJSON'] ?? '[]', true)['guests'] ?? [];
  $beforeGuests = array_map('intval', array_filter($beforeGuests, static fn($x)=>$x));
  $afterGuests  = $guestIDs;

  $added = array_values(array_diff($afterGuests, $beforeGuests));
  $removed = array_values(array_diff($beforeGuests, $afterGuests));

  if ($added || $removed) {
    $addedNames = array_filter(array_map(fn($id)=>$contactsMap[$id] ?? ("ID#$id"), $added));
    $removedNames = array_filter(array_map(fn($id)=>$contactsMap[$id] ?? ("ID#$id"), $removed));

    $parts = [];
    if ($addedNames)   $parts[] = 'added '   . implode(', ', $addedNames);
    if ($removedNames) $parts[] = 'removed ' . implode(', ', $removedNames);

    $changes[] = $actorName . ' changed guests: ' . implode('; ', $parts);
  }

  // Write audit rows (one row per change; fallback "updated" if none)
  if (!$changes) { $changes[] = "$actorName saved booking (no field changes)"; }

  $stmtA = $link->prepare(
    "INSERT INTO BookingsAuditLog (InitiatedBy, TargetBooking, ChangeMade, TimeStamp)
     VALUES (?, ?, ?, NOW())"
  );
  foreach ($changes as $line) {
    $msg = strip_tags($line); // keep log clean
    $stmtA->bind_param("iis", $userID, $bookingID, $msg);
    $stmtA->execute();
  }
  $stmtA->close();

  header("Location: view_booking.php?BookingID=$bookingID");
  exit();
}

/* ── load form refs ───────────────────────────────────────────── */
$rooms = $link->query("SELECT RoomID, Name FROM HouseLocations ORDER BY Name")->fetch_all(MYSQLI_ASSOC);
$contacts = $link->query("SELECT ContactID, KnownAs, PhotoURL FROM Contacts ORDER BY KnownAs")->fetch_all(MYSQLI_ASSOC);
$currentGuests = json_decode($booking['GuestsJSON'] ?? '[]', true)['guests'] ?? [];

/* ── include header/subheader ─────────────────────────────────── */
include '../header.php';
include 'subheader.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Edit Booking #<?= $bookingID ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Bootstrap 5.3 -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    body { background:#f6f7fb; }
    .card { border:0; border-radius:16px; box-shadow: 0 1px 2px rgba(16,24,40,.04), 0 8px 24px rgba(16,24,40,.08); }
    .chip-card { border:1px solid #e9ecef; border-radius:12px; padding:.5rem .75rem; display:flex; align-items:center; gap:.6rem; background:#fff; }
    .avatar { width:40px; height:40px; border-radius:50%; object-fit:cover; background:#e9ecef; }
    .guest-grid { display:flex; flex-wrap:wrap; gap:.5rem; }
    .dropdown-menu.show { max-height: 260px; overflow:auto; }
    .dropdown-item.active, .dropdown-item:active { background-color: #e7f1ff; color:#0b57d0; }
    .section-title { font-size:1rem; text-transform:uppercase; letter-spacing:.03em; color:#6c757d; margin-bottom:.5rem; }
  </style>
</head>
<body>

<div class="container py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h2 class="mb-0">Edit Booking <span class="text-muted">#<?= $bookingID ?></span></h2>
    <div class="d-flex gap-2">
      <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal">Delete</button>
      <a href="view_booking.php?BookingID=<?= $bookingID ?>" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </div>

  <form method="POST" id="editForm" class="row g-4">
    <!-- LEFT: Core booking fields -->
    <div class="col-lg-8">
      <div class="card">
        <div class="card-body p-3 p-md-4">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Start Date/Time</label>
              <input type="datetime-local" class="form-control" name="StartDateTime"
                     value="<?= h(date('Y-m-d\TH:i', strtotime($booking['StartDateTime']))) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">End Date/Time</label>
              <input type="datetime-local" class="form-control" name="EndDateTime"
                     value="<?= h(date('Y-m-d\TH:i', strtotime($booking['EndDateTime']))) ?>" required>
            </div>

            <div class="col-12">
              <label class="form-label">Room</label>
              <select name="RoomID" class="form-select" required>
                <option value="">Choose a room</option>
                <?php foreach ($rooms as $room): ?>
                  <option value="<?= (int)$room['RoomID'] ?>" <?= ((int)$booking['RoomID'] === (int)$room['RoomID']) ? 'selected' : '' ?>>
                    <?= h($room['Name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label">Occasion</label>
              <input type="text" name="Occasion" class="form-control" value="<?= h($booking['Occasion']) ?>">
            </div>

            <div class="col-12">
              <label class="form-label">Notes</label>
              <textarea name="GuestNotes" class="form-control" rows="4"><?= h($booking['GuestNotes']) ?></textarea>
            </div>

            <div class="col-md-6">
              <label class="form-label">Status</label>
              <select name="Status" class="form-select">
                <?php foreach (['Pencilled','Confirmed','Cancelled'] as $st): ?>
                  <option value="<?= h($st) ?>" <?= ($booking['Status']===$st?'selected':'') ?>><?= h($st) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-md-6 d-flex align-items-end">
              <button type="button" id="checkConflictsBtn" class="btn btn-outline-warning w-100">Check Conflicts</button>
            </div>
          </div>
        </div>
      </div>

      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn btn-primary">Save Changes</button>
        <a href="view_booking.php?BookingID=<?= $bookingID ?>" class="btn btn-outline-secondary">Cancel</a>
      </div>
    </div>

    <!-- RIGHT: Guests builder -->
    <div class="col-lg-4">
      <div class="card">
        <div class="card-body p-3 p-md-4">
          <div class="section-title">Guests</div>

          <!-- Add Guest typeahead (Bootstrap dropdown) -->
          <div class="mb-3 dropdown">
            <input
              type="text"
              id="guestSearch"
              class="form-control"
              placeholder="Add guest by name…"
              autocomplete="off"
              data-bs-toggle="dropdown"
              aria-expanded="false"
              aria-haspopup="true"
            >
            <ul id="guestResults" class="dropdown-menu w-100" aria-labelledby="guestSearch" role="listbox"></ul>
            <div class="form-text">Type to search, then hit Enter or click a result to add.</div>
          </div>

          <!-- Selected Guests as cards -->
          <div id="guestCards" class="guest-grid">
            <?php
              $byId = [];
              foreach ($contacts as $c) { $byId[(int)$c['ContactID']] = $c; }
              foreach ($currentGuests as $gid):
                $gid = (int)$gid;
                $c   = $byId[$gid] ?? ['KnownAs'=>'Guest '.$gid, 'PhotoURL'=>null];
            ?>
              <div class="chip-card" data-id="<?= $gid ?>">
                <img class="avatar" src="<?= h($c['PhotoURL'] ?: 'https://assets.dcworld.uk/images/contacts/Black%20and%20white%20organic%20farmhouse%20mountain%20landscape%20hand%20drawn%20logo.png') ?>" alt="<?= h($c['KnownAs']) ?>">
                <div class="me-1">
                  <div class="fw-semibold"><?= h($c['KnownAs']) ?></div>
                  <div class="text-muted small">ID #<?= $gid ?></div>
                </div>
                <button class="btn btn-sm btn-outline-danger ms-auto remove-guest" type="button" aria-label="Remove guest">Remove</button>
                <input type="hidden" name="Guests[]" value="<?= $gid ?>">
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="deleteLabel">Delete booking #<?= $bookingID ?>?</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        This action cannot be undone. The booking will be permanently removed.
      </div>
      <div class="modal-footer">
        <input type="hidden" name="delete_booking" value="1">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-danger">Delete</button>
      </div>
    </form>
  </div>
</div>

<!-- Conflict Modal -->
<div class="modal fade" id="conflictModal" tabindex="-1" aria-labelledby="conflictModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="conflictModalLabel">Booking Conflicts</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="conflictBody">
        <!-- Injected via JS -->
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">OK</button>
      </div>
    </div>
  </div>
</div>

<?php include '../footer.php'; ?>

<!-- Bootstrap 5 JS (includes Popper) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/js/bootstrap.bundle.min.js"></script>
<script>
(() => {
  const defaultAvatar = 'https://assets.dcworld.uk/images/contacts/Black%20and%20white%20organic%20farmhouse%20mountain%20landscape%20hand%20drawn%20logo.png';

  // Contacts data for typeahead (id, name, photo)
  const CONTACTS = <?= json_encode(array_map(function($c){
      return [
        'id'   => (int)$c['ContactID'],
        'name' => $c['KnownAs'],
        'photo'=> $c['PhotoURL'] ?: null
      ];
    }, $contacts), JSON_UNESCAPED_SLASHES|JSON_HEX_APOS) ?>;

  const guestSearch  = document.getElementById('guestSearch');
  const guestResults = document.getElementById('guestResults');
  const guestCards   = document.getElementById('guestCards');

  const dropdown = new bootstrap.Dropdown(guestSearch, { autoClose: 'outside' });
  let activeIndex = -1;

  function currentGuestIds() {
    return Array.from(guestCards.querySelectorAll('.chip-card'))
      .map(el => parseInt(el.getAttribute('data-id'), 10));
  }

  function escapeHtml(s) {
    return (s ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  }

  function clearResults() {
    guestResults.innerHTML = '';
    activeIndex = -1;
    dropdown.hide();
    guestSearch.setAttribute('aria-expanded', 'false');
  }

  function openResults() {
    if (!guestResults.innerHTML.trim()) return;
    dropdown.show();
    guestSearch.setAttribute('aria-expanded', 'true');
  }

  function renderResults(query) {
    guestResults.innerHTML = '';
    activeIndex = -1;
    const q = (query || '').trim().toLowerCase();
    if (!q) { clearResults(); return; }

    const taken = new Set(currentGuestIds());
    const matches = CONTACTS
      .filter(c => !taken.has(c.id) && c.name && c.name.toLowerCase().includes(q))
      .slice(0, 12);

    if (matches.length === 0) {
      guestResults.innerHTML = '<li class="dropdown-item text-muted" role="option" aria-disabled="true">No matches</li>';
      openResults();
      return;
    }

    for (const c of matches) {
      const li = document.createElement('li');
      li.className = 'dropdown-item d-flex align-items-center gap-2';
      li.setAttribute('data-id', String(c.id));
      li.setAttribute('role', 'option');
      li.innerHTML =
        `<img src="${(c.photo||defaultAvatar)}" class="rounded-circle" style="width:28px;height:28px;object-fit:cover;">` +
        `<span>${escapeHtml(c.name)}</span>`;
      li.addEventListener('click', () => { addGuestCard(c); guestSearch.value=''; clearResults(); guestSearch.focus(); });
      guestResults.appendChild(li);
    }
    openResults();
  }

  function setActive(idx) {
    const items = guestResults.querySelectorAll('.dropdown-item');
    items.forEach((el,i) => el.classList.toggle('active', i === idx));
    activeIndex = idx;
  }

  function pickActive() {
    const items = guestResults.querySelectorAll('.dropdown-item');
    if (activeIndex >= 0 && activeIndex < items.length) {
      items[activeIndex].click();
    }
  }

  function addGuestCard(c) {
    const already = guestCards.querySelector(`.chip-card[data-id="${c.id}"]`);
    if (already) return;

    const card = document.createElement('div');
    card.className = 'chip-card';
    card.setAttribute('data-id', String(c.id));
    card.innerHTML = `
      <img class="avatar" src="${(c.photo||defaultAvatar)}" alt="${escapeHtml(c.name)}">
      <div class="me-1">
        <div class="fw-semibold">${escapeHtml(c.name)}</div>
        <div class="text-muted small">ID #${c.id}</div>
      </div>
      <button class="btn btn-sm btn-outline-danger ms-auto remove-guest" type="button" aria-label="Remove guest">Remove</button>
      <input type="hidden" name="Guests[]" value="${c.id}">
    `;
    card.querySelector('.remove-guest').addEventListener('click', () => card.remove());
    guestCards.appendChild(card);
  }

  // Wire up search input -> results
  guestSearch.addEventListener('input', () => renderResults(guestSearch.value));
  guestSearch.addEventListener('focus', () => renderResults(guestSearch.value));
  guestSearch.addEventListener('keydown', (e) => {
    const items = guestResults.querySelectorAll('.dropdown-item');
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (!items.length) return;
      setActive((activeIndex + 1) % items.length);
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (!items.length) return;
      setActive((activeIndex - 1 + items.length) % items.length);
    } else if (e.key === 'Enter') {
      if (dropdown && guestResults.contains(document.activeElement) === false) {
        e.preventDefault();
        if (activeIndex === -1 && items.length) setActive(0);
        pickActive();
      }
    } else if (e.key === 'Escape') {
      clearResults();
    }
  });

  // Close dropdown shortly after blur (allow clicks)
  guestSearch.addEventListener('blur', () => setTimeout(clearResults, 150));

  // Delegate remove clicks for pre-rendered cards
  guestCards.addEventListener('click', (e) => {
    const btn = e.target.closest('.remove-guest');
    if (!btn) return;
    btn.closest('.chip-card')?.remove();
  });

  // Validation: end after start
  const startEl = document.querySelector('input[name="StartDateTime"]');
  const endEl   = document.querySelector('input[name="EndDateTime"]');
  function validateDates() {
    const s = new Date(startEl.value);
    const e = new Date(endEl.value);
    if (startEl.value && endEl.value && e < s) {
      endEl.setCustomValidity('End time cannot be before start time.');
    } else {
      endEl.setCustomValidity('');
    }
  }
  startEl.addEventListener('change', validateDates);
  endEl.addEventListener('change', validateDates);

  // Conflict check (button + on changes)
  const roomEl  = document.querySelector('select[name="RoomID"]');
  const modalEl = document.getElementById('conflictModal');
  const modal   = new bootstrap.Modal(modalEl);
  const modalBody = document.getElementById('conflictBody');
  const checkBtn  = document.getElementById('checkConflictsBtn');

  async function checkConflicts() {
    const payload = new URLSearchParams({
      conflict_check: '1',
      bookingID: '<?= (int)$bookingID ?>',
      roomID: roomEl.value || '',
      start: startEl.value || '',
      end: endEl.value || ''
    });

    if (!roomEl.value || !startEl.value || !endEl.value) return;

    const res = await fetch('', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: payload.toString()
    });
    const data = await res.json().catch(()=>({ok:false}));

    if (!data.ok) return;

    if ((data.conflicts || []).length === 0) {
      modalBody.innerHTML = '<div class="alert alert-success mb-0">No conflicts detected.</div>';
    } else {
      modalBody.innerHTML = data.conflicts.map(c => {
        const guests = (c.Guests && c.Guests.length) ? ('Guests: ' + c.Guests.map(escapeHtml).join(', ')) : 'No guests listed';
        return `
          <div class="alert alert-warning">
            <div><strong>${escapeHtml(c.BookingRef || 'Conflict')}</strong></div>
            <div>${escapeHtml(c.Start)} → ${escapeHtml(c.End)}</div>
            <div class="small text-muted mt-1">${escapeHtml(guests)}</div>
          </div>`;
      }).join('');
    }
    modal.show();
  }

  checkBtn.addEventListener('click', checkConflicts);
  [startEl, endEl, roomEl].forEach(el => el.addEventListener('change', checkConflicts));
})();
</script>
</body>
</html>
