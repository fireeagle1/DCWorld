<?php
/*  home/welcome_links.php — Queue & copy guest welcome links (magic links)
    -----------------------------------------------------------------------
    • Staff-only page.
    • Builds welcome email + secure link per guest; queues into DCEmailsLog.
    • Copy link or full email for manual send.
    • Writes audit rows to BookingsAuditLog.
    • Hardened POST JSON handling to avoid "Unexpected end of JSON input".

    Note: Config is hard-coded to the displays host:
          /home/xohpwhmm/displays.dcworld.uk/config.php
*/

/* --------- fixed paths ---------- */
$CONFIG_PATH        = '/home/xohpwhmm/displays.dcworld.uk/config.php';
$GS_FS_ROOT         = '/home/xohpwhmm/guestservices.dcworld.uk/guests';
$GS_CLAIM_BASE_URL  = 'https://guestservices.dcworld.uk/guests/claim.php';
/* -------------------------------- */

session_start();

/* Site root (one level up from /home) for header/auth includes */
$SITE_ROOT = dirname(__DIR__);

/* Core includes */
require_once $CONFIG_PATH;                 // ← hard-coded config
require_once $SITE_ROOT . '/auth.php';
if (!isset($_SESSION['userID'])) { header('Location: ../login.php'); exit; }

/* Bring in the email content builder (+auth helper if available) */
$builder = rtrim($GS_FS_ROOT, '/').'/email_content_builder.php';
$authlib = rtrim($GS_FS_ROOT, '/').'/guest_auth.php';
if (!is_file($builder)) {
  http_response_code(500);
  echo "Email builder not found at: ".htmlspecialchars($builder);
  exit;
}
require_once $builder;
if (is_file($authlib) && !function_exists('sign_claim_payload')) {
  require_once $authlib;
}

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/* ---- Input guard ---- */
if (!isset($_GET['BookingID'])) { echo "No booking selected."; exit; }
$bookingID = (int)$_GET['BookingID'];

/* ---- CSRF token ---- */
if (empty($_SESSION['csrf_guestlinks'])) {
  $_SESSION['csrf_guestlinks'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf_guestlinks'];

/* ---- Load booking ---- */
$stmt = $link->prepare(
  "SELECT b.BookingRef, b.StartDateTime, b.EndDateTime, b.GuestsJSON, b.Occasion,
          r.Name AS RoomName
     FROM Bookings b
LEFT JOIN HouseLocations r ON r.RoomID = b.RoomID
    WHERE b.BookingID=?"
);
$stmt->bind_param('i', $bookingID);
$stmt->execute();
$res = $stmt->get_result();
$booking = $res ? $res->fetch_assoc() : null;
$stmt->close();
if (!$booking) { echo "Booking not found."; exit; }

$startDT = new DateTime($booking['StartDateTime']);
$endDT   = new DateTime($booking['EndDateTime']);

/* ---- Guests on this booking ---- */
$gj = json_decode($booking['GuestsJSON'] ?? '[]', true);
$ids = array_values(array_unique(array_map('intval', $gj['guests'] ?? [])));
$guests = [];
if ($ids) {
  $in = implode(',', array_map('intval', $ids));
  $q = $link->query("SELECT ContactID, KnownAs, FirstName, LastName, Email, PhotoURL
                       FROM Contacts
                      WHERE ContactID IN ($in)
                   ORDER BY KnownAs, LastName, FirstName");
  while ($row = $q->fetch_assoc()) { $guests[(int)$row['ContactID']] = $row; }
}

/* =======================================================================
   AJAX actions (build / queue_one / queue_all) — robust JSON handling
   ======================================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  // Always JSON for POST branch
  header('Content-Type: application/json; charset=utf-8');
  // Don’t leak notices/warnings into the JSON; log them instead.
  ini_set('display_errors', '0');

  // Convert PHP notices/warnings into exceptions so we can return JSON
  set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
  });

  // Catch fatals and emit JSON (prevents empty body → "Unexpected end of JSON input")
  register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
      http_response_code(500);
      $where = basename($err['file']).':'.$err['line'];
      echo json_encode(['ok'=>false, 'error'=>'Fatal error', 'detail'=>$err['message'], 'where'=>$where]);
    }
  });

  try {
    // CSRF
    if (!hash_equals($_SESSION['csrf_guestlinks'] ?? '', $_POST['csrf'] ?? '')) {
      http_response_code(400);
      echo json_encode(['ok'=>false,'error'=>'CSRF failed']); exit;
    }

    $action = $_POST['action'];

    // Helper: audit booking actions
    $userID = (int)($_SESSION['userID'] ?? 0);
    $audit  = function(string $msg) use ($link, $bookingID, $userID) {
      $stmt = $link->prepare(
        "INSERT INTO BookingsAuditLog (InitiatedBy, TargetBooking, ChangeMade, TimeStamp)
         VALUES (?, ?, ?, NOW())"
      );
      $stmt->bind_param('iis', $userID, $bookingID, $msg);
      $stmt->execute();
      $stmt->close();
    };

    if ($action === 'build') {
      $cid = (int)($_POST['cid'] ?? 0);
      if (!$cid || !isset($guests[$cid])) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Unknown contact']); exit; }
      $built = build_guest_welcome_email($link, $bookingID, $cid, $GS_CLAIM_BASE_URL);
      // If the builder didn’t expose build_guest_link(), prefer the link inside $built
      if (function_exists('build_guest_link')) {
        $linkOnly = build_guest_link($link, $bookingID, $cid, $GS_CLAIM_BASE_URL);
      } else {
        $linkOnly = $built['link'] ?? '';
      }
      echo json_encode([
        'ok'=>true,
        'subject'=>$built['subject'] ?? '',
        'content'=>$built['content'] ?? '',
        'link'=>$linkOnly,
        'email'=>$guests[$cid]['Email'] ?? ''
      ]); exit;
    }

    if ($action === 'queue_one') {
      $cid   = (int)($_POST['cid'] ?? 0);
      $email = trim($_POST['email'] ?? '');
      $save  = (int)($_POST['save'] ?? 0);

      if (!$cid || !isset($guests[$cid])) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'Unknown contact']); exit; }
      if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Invalid email']); exit; }

      $built = build_guest_welcome_email($link, $bookingID, $cid, $GS_CLAIM_BASE_URL);

      if ($save === 1) {
        $u = $link->prepare("UPDATE Contacts SET Email=? WHERE ContactID=?");
        $u->bind_param('si', $email, $cid);
        $u->execute();
        $u->close();
      }

      $ins = $link->prepare(
        "INSERT INTO DCEmailsLog (`to`,`Subject`,`Content`,`DateTimeSent`,`Sent`)
         VALUES (?, ?, ?, NOW(), 'No')"
      );
      $ins->bind_param('sss', $email, $built['subject'], $built['content']);
      $ins->execute();
      $ins->close();

      $audit("Guest welcome link queued for ContactID {$cid}");
      echo json_encode(['ok'=>true]); exit;
    }

    if ($action === 'queue_all') {
      $queued = 0; $skipped = 0; $errors = [];
      foreach ($ids as $cid) {
        $c = $guests[$cid] ?? null; if (!$c) { $skipped++; continue; }
        $email = trim($c['Email'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $skipped++; continue; }
        try {
          $built = build_guest_welcome_email($link, $bookingID, $cid, $GS_CLAIM_BASE_URL);
          $ins = $link->prepare(
            "INSERT INTO DCEmailsLog (`to`,`Subject`,`Content`,`DateTimeSent`,`Sent`)
             VALUES (?, ?, ?, NOW(), 'No')"
          );
          $ins->bind_param('sss', $email, $built['subject'], $built['content']);
          $ins->execute();
          $ins->close();
          $queued++;
        } catch (Throwable $e) {
          $skipped++; $errors[] = "CID {$cid}: ".$e->getMessage();
        }
      }
      if ($queued > 0) { $audit("Guest welcome links queued ({$queued})"); }
      echo json_encode(['ok'=>true,'queued'=>$queued,'skipped'=>$skipped,'errors'=>$errors]); exit;
    }

    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Unknown action']); exit;

  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    exit;
  }
}

/* -------------------------- HTML -------------------------- */
$defaultAvatar = 'https://assets.dcworld.uk/images/contacts/Black%20and%20white%20organic%20farmhouse%20mountain%20landscape%20hand%20drawn%20logo.png';
$backUrl = 'view_booking.php?BookingID='.(int)$bookingID;
?>
<?php include $SITE_ROOT . '/header.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Guest Welcome Links · <?= h($booking['BookingRef']) ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body{background:#f6f7fb}
  .card{border:0;border-radius:16px;box-shadow:0 1px 2px rgba(16,24,40,.04),0 8px 24px rgba(16,24,40,.08)}
  .avatar{width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid #fff;background:#e9ecef}
  .email-badge{font-size:.8rem}
  .table thead th{white-space:nowrap}
  .actions .btn{white-space:nowrap}
  .small-muted{font-size:.9rem;color:#6c757d}
</style>
</head>
<body>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h3 class="mb-0">Guest Welcome Links</h3>
      <div class="small-muted">
        Ref <strong><?= h($booking['BookingRef']) ?></strong>
        · Room <strong><?= h($booking['RoomName'] ?: 'Room') ?></strong>
        <?php if (!empty($booking['Occasion'])): ?> · Occasion <strong><?= h($booking['Occasion']) ?></strong><?php endif; ?>
        · Stay <strong><?= h($startDT->format('D j M Y')) ?></strong> → <strong><?= h($endDT->format('D j M Y')) ?></strong>
      </div>
    </div>
    <div>
      <a href="<?= h($backUrl) ?>" class="btn btn-outline-secondary">← Back to Booking</a>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-body d-flex flex-wrap gap-2 align-items-center">
      <div class="me-auto">
        <div class="small-muted mb-1">Queue links to all guests who have a valid email on file. You can still copy individual links below if an email is missing.</div>
      </div>
      <button id="queueAllBtn" class="btn btn-primary">Queue All (valid emails)</button>
      <span id="queueAllStatus" class="small-muted"></span>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <?php if (!$ids): ?>
        <div class="alert alert-warning mb-0">No guests are attached to this booking.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th>Guest</th>
                <th>Email on file</th>
                <th style="min-width:220px;">Manual/Override Email</th>
                <th class="text-end">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($ids as $cid):
                  $g = $guests[$cid] ?? null;
                  $name = $g ? ($g['KnownAs'] ?: (trim(($g['FirstName'] ?? '').' '.($g['LastName'] ?? '')) ?: 'Guest')) : ('Contact #'.$cid);
                  $email= $g ? trim($g['Email'] ?? '') : '';
                  $photo= $g && $g['PhotoURL'] ? $g['PhotoURL'] : $defaultAvatar;
            ?>
              <tr data-cid="<?= (int)$cid ?>">
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <img class="avatar" src="<?= h($photo) ?>" alt="">
                    <div>
                      <div class="fw-semibold"><?= h($name) ?></div>
                      <div class="small-muted">#<?= (int)$cid ?></div>
                    </div>
                  </div>
                </td>
                <td>
                  <?php if ($email): ?>
                    <span class="badge text-bg-light border email-badge"><?= h($email) ?></span>
                  <?php else: ?>
                    <span class="text-danger small">No email on file</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="input-group">
                    <input type="email" class="form-control form-control-sm override-email" placeholder="name@example.com">
                    <div class="input-group-text">
                      <input class="form-check-input mt-0 save-to-contact" type="checkbox" title="Save to Contacts"> Save
                    </div>
                  </div>
                </td>
                <td class="text-end actions">
                  <div class="btn-group">
                    <button class="btn btn-sm btn-outline-secondary copy-link">Copy Link</button>
                    <button class="btn btn-sm btn-outline-secondary copy-email">Copy Email</button>
                    <button class="btn btn-sm btn-primary queue-one">Queue Email</button>
                  </div>
                  <div class="small" id="row-status-<?= (int)$cid ?>"></div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Modal: preview email (for copy) -->
<div class="modal fade" id="emailPreviewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Email Preview</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2"><strong>Subject</strong><br><input id="previewSubject" class="form-control"></div>
        <div class="mb-2"><strong>Content</strong><br><textarea id="previewContent" class="form-control" rows="10"></textarea></div>
        <div class="mb-2"><strong>Link</strong><br><input id="previewLink" class="form-control"></div>
      </div>
      <div class="modal-footer">
        <button id="copySubjectBtn" class="btn btn-outline-secondary">Copy Subject</button>
        <button id="copyContentBtn" class="btn btn-outline-secondary">Copy Content</button>
        <button id="copyLinkBtn"    class="btn btn-outline-secondary">Copy Link</button>
        <button class="btn btn-primary" data-bs-dismiss="modal">Done</button>
      </div>
    </div>
  </div>
</div>

<script>
const CSRF   = <?= json_encode($CSRF) ?>;
const BID    = <?= (int)$bookingID ?>;

function rowStatus(cid, msg, good=false){
  const el = document.getElementById('row-status-'+cid);
  if (!el) return;
  el.textContent = msg || '';
  el.className = 'small ' + (good ? 'text-success' : 'text-muted');
  if (msg) setTimeout(()=>{ el.textContent=''; }, 3000);
}

async function api(action, payload){
  payload = payload || {};
  payload.action = action;
  payload.BookingID = BID;
  payload.csrf = CSRF;

  const r = await fetch(location.href, {
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams(payload),
    credentials:'same-origin',
    redirect:'follow'
  });

  // Read as text first so we can diagnose non-JSON (e.g., login redirect or PHP fatal)
  const text = await r.text();
  try {
    return JSON.parse(text);
  } catch (e) {
    // Session expired? (login page)
    if (/login/i.test(text) && /<form/i.test(text)) {
      throw new Error('Your session has expired. Please refresh and sign in again.');
    }
    // Generic server error body (first 300 chars)
    const snippet = (text || '').slice(0, 300).replace(/\s+/g, ' ').trim();
    throw new Error(`Server returned ${r.status}. ${snippet || 'No response body.'}`);
  }
}

async function buildEmail(cid){
  const j = await api('build', { cid });
  if (!j.ok) throw new Error(j.error||'Build failed');
  return j; // {subject, content, link, email}
}

function copyText(str){
  if (navigator.clipboard && window.isSecureContext) {
    return navigator.clipboard.writeText(str);
  }
  const ta=document.createElement('textarea'); ta.value=str;
  ta.style.position='fixed'; ta.style.opacity='0'; document.body.appendChild(ta);
  ta.select(); document.execCommand('copy'); document.body.removeChild(ta);
  return Promise.resolve();
}

document.addEventListener('click', async (e)=>{
  const row = e.target.closest('tr[data-cid]');
  const cid = row ? parseInt(row.getAttribute('data-cid'),10) : null;

  if (e.target.matches('.copy-link')) {
    try{
      const j = await buildEmail(cid);
      await copyText(j.link);
      rowStatus(cid, 'Link copied ✓', true);
    }catch(err){ rowStatus(cid, err.message||'Failed'); }
  }

  if (e.target.matches('.copy-email')) {
    try{
      const j = await buildEmail(cid);
      document.getElementById('previewSubject').value = j.subject || '';
      document.getElementById('previewContent').value = j.content || '';
      document.getElementById('previewLink').value    = j.link || '';
      new bootstrap.Modal(document.getElementById('emailPreviewModal')).show();
      rowStatus(cid, 'Loaded preview', true);
    }catch(err){ rowStatus(cid, err.message||'Failed'); }
  }

  if (e.target.matches('.queue-one')) {
    const override = row.querySelector('.override-email').value.trim();
    const save = row.querySelector('.save-to-contact').checked ? 1 : 0;
    const emailToUse = override || (row.querySelector('.email-badge')?.textContent?.trim() || '');
    if (!emailToUse) { rowStatus(cid, 'Enter an email first'); return; }
    try{
      const j = await api('queue_one', { cid, email: emailToUse, save });
      if (!j.ok) throw new Error(j.error||'Queue failed');
      rowStatus(cid, 'Queued ✓', true);
    }catch(err){ rowStatus(cid, err.message||'Failed'); }
  }
});

document.getElementById('queueAllBtn')?.addEventListener('click', async ()=>{
  const btn = document.getElementById('queueAllBtn');
  const status = document.getElementById('queueAllStatus');
  btn.disabled = true; status.textContent = 'Queuing...';
  try{
    const j = await api('queue_all', {});
    if (!j.ok) throw new Error(j.error||'Queue failed');
    status.textContent = `Queued ${j.queued} · Skipped ${j.skipped}`;
    setTimeout(()=>{ status.textContent=''; }, 4000);
  }catch(err){
    status.textContent = err.message||'Failed';
  }finally{
    btn.disabled = false;
  }
});

// Copy buttons in modal
document.getElementById('copySubjectBtn').addEventListener('click', ()=> copyText(document.getElementById('previewSubject').value));
document.getElementById('copyContentBtn').addEventListener('click', ()=> copyText(document.getElementById('previewContent').value));
document.getElementById('copyLinkBtn').addEventListener('click',    ()=> copyText(document.getElementById('previewLink').value));
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
