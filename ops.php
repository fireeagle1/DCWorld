<?php
session_start();
require 'config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// --- CONFIG: user mapping (global scope so it's available everywhere) ---
$CK_USER_ID = 1; // CK
$DC_USER_ID = 3; // DC

try {
    // Auth
    require 'auth.php';

    // CSRF
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrfToken = $_SESSION['csrf_token'];

    // Current user
    $userID = (int)($_SESSION['userID'] ?? 0);
    $stmtUser = $link->prepare("SELECT Name FROM DC_Users WHERE UserID = ?");
    $stmtUser->bind_param("i", $userID);
    $stmtUser->execute();
    $stmtUser->bind_result($Name);
    $stmtUser->fetch();
    $stmtUser->close();

    // Week window
    $weekOffset = isset($_GET['week']) ? (int)$_GET['week'] : 0;
    $today = new DateTime('today');
    if ($weekOffset !== 0) {
        $today->modify(($weekOffset * 7) . ' days');
    }
    $startOfWeek = (clone $today)->modify('monday this week');
    $endOfWeek   = (clone $startOfWeek)->modify('+6 days');

    $formattedStartOfWeek = $startOfWeek->format('Y-m-d');
    $formattedEndOfWeek   = $endOfWeek->format('Y-m-d');

    // Dropdowns
    $locations = [];
    $workLocations = [];

    $res = $link->query("SELECT LocationID, LocationDesc FROM DC_Locations ORDER BY LocationDesc");
    while ($row = $res->fetch_assoc()) {
        $locations[(int)$row['LocationID']] = $row['LocationDesc'];
    }

    $res = $link->query("SELECT WorkID, WLocationDesc FROM DC_WorkLocation ORDER BY WLocationDesc");
    while ($row = $res->fetch_assoc()) {
        $workLocations[(int)$row['WorkID']] = $row['WLocationDesc'];
    }

    // COPY PREVIOUS WEEK (AJAX)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'copy_prev_week') {
        if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf'])) {
            http_response_code(403);
            echo 'CSRF validation failed';
            exit;
        }

        $sqlCopy = "
            UPDATE DCDailyOpsPlan cur
            JOIN DCDailyOpsPlan prev
              ON prev.Date = DATE_SUB(cur.Date, INTERVAL 7 DAY)
            SET
              cur.CKLocation     = prev.CKLocation,
              cur.DCLocation     = prev.DCLocation,
              cur.CKWorkLocation = prev.CKWorkLocation,
              cur.DCWorkLocation = prev.DCWorkLocation,
              cur.CKOnCall       = prev.CKOnCall,
              cur.DCOnCall       = prev.DCOnCall
            WHERE cur.Date BETWEEN ? AND ?
        ";
        $stmtCopy = $link->prepare($sqlCopy);
        $stmtCopy->bind_param("ss", $formattedStartOfWeek, $formattedEndOfWeek);
        $ok = $stmtCopy->execute();
        $stmtCopy->close();

        echo $ok ? 'success' : 'error';
        exit;
    }

    // Inline updates (AJAX)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dayID'], $_POST['field'])) {
        if (!isset($_POST['csrf']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf'])) {
            http_response_code(403);
            echo 'CSRF validation failed';
            exit;
        }

        $dayID = (int)$_POST['dayID'];
        $field = (string)$_POST['field'];

        // Whitelist + expected bind type
        $allowed = [
            'CKLocation'     => 'i',
            'DCLocation'     => 'i',
            'CKWorkLocation' => 'i',
            'DCWorkLocation' => 'i',
            'CKOnCall'       => 'i',
            'DCOnCall'       => 'i',
        ];

        if (!array_key_exists($field, $allowed)) {
            http_response_code(400);
            echo 'Invalid field';
            exit;
        }

        // Coerce value based on expected type
        $rawVal = $_POST['value'] ?? null;

        if ($field === 'CKOnCall' || $field === 'DCOnCall') {
            $val = ($rawVal == 1) ? 1 : 0;
        } else {
            $val = ($rawVal === '' || $rawVal === null) ? null : (int)$rawVal;
        }

        // Build SQL with explicit field name from whitelist
        if ($val === null) {
            $sqlNull = "UPDATE DCDailyOpsPlan SET {$field} = NULL WHERE DayID = ?";
            $stmtNull = $link->prepare($sqlNull);
            $stmtNull->bind_param("i", $dayID);
            $ok = $stmtNull->execute();
            $stmtNull->close();
            echo $ok ? 'success' : 'error';
            exit;
        }

        $sql = "UPDATE DCDailyOpsPlan SET {$field} = ? WHERE DayID = ?";
        $stmtUpdate = $link->prepare($sql);
        $stmtUpdate->bind_param("ii", $val, $dayID);
        $ok = $stmtUpdate->execute();
        $stmtUpdate->close();

        echo $ok ? 'success' : 'error';
        exit;
    }

    // Daily ops for the week (meals/notes removed)
    $stmtOps = $link->prepare(
        "SELECT DayID, Date, CKLocation, DCLocation, CKWorkLocation, DCWorkLocation, CKOnCall, DCOnCall
         FROM DCDailyOpsPlan
         WHERE Date BETWEEN ? AND ?
         ORDER BY Date ASC"
    );
    $stmtOps->bind_param("ss", $formattedStartOfWeek, $formattedEndOfWeek);
    $stmtOps->execute();
    $stmtOps->bind_result($DayID, $Date, $CKLocation, $DCLocation, $CKWorkLocation, $DCWorkLocation, $CKOnCall, $DCOnCall);

    $dailyOps = [];
    while ($stmtOps->fetch()) {
        $dailyOps[$DayID] = [
            'Date'           => $Date,
            'CKLocation'     => $CKLocation,
            'DCLocation'     => $DCLocation,
            'CKWorkLocation' => $CKWorkLocation,
            'DCWorkLocation' => $DCWorkLocation,
            'CKOnCall'       => (int)$CKOnCall,
            'DCOnCall'       => (int)$DCOnCall,
        ];
    }
    $stmtOps->close();

} catch (mysqli_sql_exception $e) {
    http_response_code(500);
    die('Database error: ' . htmlspecialchars($e->getMessage()));
} finally {
    if (isset($link) && $link instanceof mysqli) {
        $link->close();
    }
}

// Helper: build week URL preserving query params like magic_key
function week_url($weekOffset) {
    $params = $_GET;
    $params['week'] = (int)$weekOffset;
    $qs = http_build_query($params);
    return '?' . $qs;
}

// Determine mobile default view + "mine" mapping
$defaultMobileView = 'both';
if ($userID === $CK_USER_ID || $userID === $DC_USER_ID) {
    $defaultMobileView = 'mine';
}
$minePerson = ($userID === $CK_USER_ID) ? 'ck' : (($userID === $DC_USER_ID) ? 'dc' : 'both');
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="auto">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Daily Operations Plan</title>

  <style>
    body { background: #f6f7fb; }
    .shadow-soft { box-shadow: 0 1px 2px rgba(16,24,40,.04), 0 8px 24px rgba(16,24,40,.08); }
    .chip { display:inline-flex; align-items:center; padding:.25rem .6rem; border-radius:999px; font-size:.75rem; font-weight:600; }
    .chip-charlie { background:#e7f5ff; color:#0b7285; }
    .chip-daniel  { background:#f3f0ff; color:#5f3dc4; }
    .chip-oncall  { background:#fff4e6; color:#d9480f; }
    .table thead th { position: sticky; top: 0; z-index: 2; background: var(--bs-body-bg); }
    .sticky-col { position: sticky; left: 0; z-index: 1; background: var(--bs-body-bg); }
    .table > :not(caption) > * > * { vertical-align: middle; }
    .updated-success { animation: pulse-bg 1s ease; }
    @keyframes pulse-bg { 0% { box-shadow: 0 0 0 0 rgba(32,201,151,.7); } 100% { box-shadow: 0 0 0 8px rgba(32,201,151,0); } }
    .divider-v { border-left: 3px solid #0ea5e9; }

    /* Mobile-first */
    .desktop-view { display: none; }
    .mobile-view { display: block; }
    @media (min-width: 992px) {
      .desktop-view { display: block; }
      .mobile-view { display: none; }
    }

    .mobile-date {
      display:flex;
      align-items:baseline;
      justify-content:space-between;
      gap:.75rem;
      flex-wrap:wrap;
    }
    .mobile-date .dow { font-weight:700; }
    .mobile-date .mdy { color: var(--bs-secondary-color); font-size:.9rem; }
    
    /* Default = MOBILE FIRST */
.desktop-view { display: none !important; }
.mobile-view  { display: block !important; }

/* Desktop breakpoint */
@media (min-width: 992px) {
  .desktop-view { display: block !important; }
  .mobile-view  { display: none !important; }
}

  </style>
</head>
<body>

<?php include 'header.php'; ?>

<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div>
      <h1 class="h4 mb-1">Welcome, <?= htmlspecialchars($Name) ?></h1>
      <div class="text-muted small">
        Week of <strong><?= $startOfWeek->format('l, jS F Y') ?></strong> to <strong><?= $endOfWeek->format('l, jS F Y') ?></strong>
      </div>
    </div>

    <div class="d-flex align-items-center gap-2">
      <button class="btn btn-outline-secondary shadow-soft" id="btnCopyPrevWeek" type="button" title="Copy last week's plan into this week">
        Copy last week
      </button>

      <div class="btn-group shadow-soft" role="group" aria-label="Week navigation">
        <a class="btn btn-outline-primary" href="<?= htmlspecialchars(week_url($weekOffset - 1)) ?>" id="btnPrev" title="Previous week (←)">&laquo;</a>
        <a class="btn btn-primary" href="<?= htmlspecialchars(week_url(0)) ?>" id="btnToday" title="Current week (T)">This Week</a>
        <a class="btn btn-outline-primary" href="<?= htmlspecialchars(week_url($weekOffset + 1)) ?>" id="btnNext" title="Next week (→)">&raquo;</a>
      </div>
    </div>
  </div>

  <!-- DESKTOP: both CK + DC table -->
  <div class="desktop-view card shadow-soft">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-striped table-hover mb-0 align-middle">
          <thead>
            <tr>
              <th class="sticky-col" style="min-width: 220px;">Date</th>
              <th colspan="2"><span class="chip chip-charlie">Charlie</span></th>
              <th class="divider-v" colspan="2"><span class="chip chip-daniel">Daniel</span></th>
              <th style="min-width: 140px;">On&nbsp;Call</th>
            </tr>
            <tr class="text-muted small">
              <th class="sticky-col"></th>
              <th>Location</th>
              <th>Work Location</th>
              <th class="divider-v">Location</th>
              <th>Work Location</th>
              <th>—</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($dailyOps as $dayID => $op): ?>
            <tr>
              <td class="sticky-col">
                <div class="fw-semibold"><?= date('D, j M Y', strtotime($op['Date'])) ?></div>
              </td>

              <!-- Charlie -->
              <td>
                <select class="form-select update-field" data-id="<?= $dayID ?>" data-field="CKLocation">
                  <option value="">—</option>
                  <?php foreach ($locations as $locationId => $locationDesc): ?>
                  <option value="<?= $locationId ?>" <?= ($locationId == $op['CKLocation']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($locationDesc) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td>
                <select class="form-select update-field" data-id="<?= $dayID ?>" data-field="CKWorkLocation">
                  <option value="">—</option>
                  <?php foreach ($workLocations as $workId => $workDesc): ?>
                  <option value="<?= $workId ?>" <?= ($workId == $op['CKWorkLocation']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($workDesc) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </td>

              <!-- Daniel -->
              <td class="divider-v">
                <select class="form-select update-field" data-id="<?= $dayID ?>" data-field="DCLocation">
                  <option value="">—</option>
                  <?php foreach ($locations as $locationId => $locationDesc): ?>
                  <option value="<?= $locationId ?>" <?= ($locationId == $op['DCLocation']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($locationDesc) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td>
                <select class="form-select update-field" data-id="<?= $dayID ?>" data-field="DCWorkLocation">
                  <option value="">—</option>
                  <?php foreach ($workLocations as $workId => $workDesc): ?>
                  <option value="<?= $workId ?>" <?= ($workId == $op['DCWorkLocation']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($workDesc) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </td>

              <!-- On Call -->
              <td>
                <div class="form-check form-switch">
                  <input class="form-check-input update-field" type="checkbox" role="switch"
                         data-id="<?= $dayID ?>" data-field="CKOnCall" <?= $op['CKOnCall'] ? 'checked' : '' ?>>
                  <label class="form-check-label small">CK</label>
                </div>
                <div class="form-check form-switch">
                  <input class="form-check-input update-field" type="checkbox" role="switch"
                         data-id="<?= $dayID ?>" data-field="DCOnCall" <?= $op['DCOnCall'] ? 'checked' : '' ?>>
                  <label class="form-check-label small">DC</label>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- MOBILE: default "Mine", toggle to CK/DC/Both -->
  <div class="mobile-view">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">

      <div class="btn-group shadow-soft" role="group" aria-label="Mobile person toggle" id="mobileToggle">
        <button type="button" class="btn btn-outline-primary js-view" data-view="mine">Mine</button>
        <button type="button" class="btn btn-outline-primary js-view" data-view="ck">CK</button>
        <button type="button" class="btn btn-outline-primary js-view" data-view="dc">DC</button>
        <button type="button" class="btn btn-outline-primary js-view" data-view="both">Both</button>
      </div>
    </div>

    <?php foreach ($dailyOps as $dayID => $op): ?>
      <?php
        $dt = strtotime($op['Date']);
        $dow = date('D', $dt);
        $mdy = date('j M', $dt);
      ?>
      <div class="card mb-2 shadow-soft">
        <div class="card-body">
          <div class="mobile-date mb-2">
            <div>
              <span class="dow"><?= htmlspecialchars($dow) ?></span>
              <span class="mdy"><?= htmlspecialchars($mdy) ?></span>
            </div>
            <span class="chip chip-oncall"><?= ($op['CKOnCall'] || $op['DCOnCall']) ? 'On Call' : '—' ?></span>
          </div>

          <div class="row g-2">
            <!-- CK panel -->
            <div class="col-12 js-panel" data-person="ck">
              <div class="small text-muted mb-1"><span class="chip chip-charlie">Charlie</span></div>

              <label class="form-label mb-1">Location</label>
              <select class="form-select update-field" data-id="<?= $dayID ?>" data-field="CKLocation">
                <option value="">—</option>
                <?php foreach ($locations as $locationId => $locationDesc): ?>
                  <option value="<?= $locationId ?>" <?= ($locationId == $op['CKLocation']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($locationDesc) ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <label class="form-label mt-2 mb-1">Work Location</label>
              <select class="form-select update-field" data-id="<?= $dayID ?>" data-field="CKWorkLocation">
                <option value="">—</option>
                <?php foreach ($workLocations as $workId => $workDesc): ?>
                  <option value="<?= $workId ?>" <?= ($workId == $op['CKWorkLocation']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($workDesc) ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <div class="form-check form-switch mt-2">
                <input class="form-check-input update-field" type="checkbox" role="switch"
                       data-id="<?= $dayID ?>" data-field="CKOnCall" <?= $op['CKOnCall'] ? 'checked' : '' ?>>
                <label class="form-check-label">CK On Call</label>
              </div>
            </div>

            <!-- DC panel -->
            <div class="col-12 js-panel" data-person="dc">
              <div class="small text-muted mb-1"><span class="chip chip-daniel">Daniel</span></div>

              <label class="form-label mb-1">Location</label>
              <select class="form-select update-field" data-id="<?= $dayID ?>" data-field="DCLocation">
                <option value="">—</option>
                <?php foreach ($locations as $locationId => $locationDesc): ?>
                  <option value="<?= $locationId ?>" <?= ($locationId == $op['DCLocation']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($locationDesc) ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <label class="form-label mt-2 mb-1">Work Location</label>
              <select class="form-select update-field" data-id="<?= $dayID ?>" data-field="DCWorkLocation">
                <option value="">—</option>
                <?php foreach ($workLocations as $workId => $workDesc): ?>
                  <option value="<?= $workId ?>" <?= ($workId == $op['DCWorkLocation']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($workDesc) ?>
                  </option>
                <?php endforeach; ?>
              </select>

              <div class="form-check form-switch mt-2">
                <input class="form-check-input update-field" type="checkbox" role="switch"
                       data-id="<?= $dayID ?>" data-field="DCOnCall" <?= $op['DCOnCall'] ? 'checked' : '' ?>>
                <label class="form-check-label">DC On Call</label>
              </div>
            </div>
          </div><!-- row -->
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Toasts -->
  <div class="position-fixed bottom-0 end-0 p-3" style="z-index: 1080">
    <div id="toastError" class="toast align-items-center text-bg-danger border-0" role="alert" aria-live="assertive" aria-atomic="true">
      <div class="d-flex">
        <div class="toast-body" id="toastErrorBody">Update failed.</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
    </div>
  </div>

  <div class="position-fixed bottom-0 start-0 p-3" style="z-index: 1080">
    <div id="toastOk" class="toast align-items-center text-bg-success border-0" role="status" aria-live="polite" aria-atomic="true">
      <div class="d-flex">
        <div class="toast-body">Copied last week into this week.</div>
        <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
      </div>
    </div>
  </div>
</div>

<?php include 'footer.php'; ?>

<script>
(function() {
  const CSRF = <?= json_encode($csrfToken) ?>;

  const minePerson = <?= json_encode($minePerson) ?>;     // 'ck' | 'dc' | 'both'
  const defaultView = <?= json_encode($defaultMobileView) ?>; // 'mine' | 'both'

  const errorToast = new bootstrap.Toast(document.getElementById('toastError'), { delay: 3500 });
  const okToast = new bootstrap.Toast(document.getElementById('toastOk'), { delay: 2200 });

  function markSuccess(el) {
    el.classList.add('updated-success');
    setTimeout(() => el.classList.remove('updated-success'), 800);
  }

  async function pushUpdate(dayID, field, value) {
    const fd = new FormData();
    fd.append('dayID', dayID);
    fd.append('field', field);
    fd.append('value', value);
    fd.append('csrf', CSRF);

    const res = await fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' });
    const text = (await res.text()).trim();
    if (text !== 'success') throw new Error(text || 'Unknown error');
  }

  async function copyPrevWeek() {
    const fd = new FormData();
    fd.append('action', 'copy_prev_week');
    fd.append('csrf', CSRF);

    const res = await fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' });
    const text = (await res.text()).trim();
    if (text !== 'success') throw new Error(text || 'Unknown error');

    okToast.show();
    setTimeout(() => window.location.reload(), 350);
  }

  document.getElementById('btnCopyPrevWeek')?.addEventListener('click', async () => {
    if (!confirm('Copy the entire previous week into this week? This will overwrite this week’s current values.')) return;
    try { await copyPrevWeek(); } catch (err) { showError(err); }
  });

  function showError(err) {
    const body = document.getElementById('toastErrorBody');
    body.textContent = 'Update failed: ' + (err && err.message ? err.message : 'Unknown error');
    errorToast.show();
  }

  // Mobile view toggling: mine/ck/dc/both
  function applyMobileView(view) {
    // Determine what "mine" means
    let effective = view;
    if (view === 'mine') effective = minePerson;

    document.querySelectorAll('.js-panel[data-person="ck"]').forEach(el => {
      el.style.display = (effective === 'ck' || effective === 'both') ? '' : 'none';
    });
    document.querySelectorAll('.js-panel[data-person="dc"]').forEach(el => {
      el.style.display = (effective === 'dc' || effective === 'both') ? '' : 'none';
    });

    document.querySelectorAll('#mobileToggle .js-view').forEach(btn => {
      const isActive = (btn.dataset.view === view);
      btn.classList.toggle('btn-primary', isActive);
      btn.classList.toggle('btn-outline-primary', !isActive);
    });
  }

  document.querySelectorAll('#mobileToggle .js-view').forEach(btn => {
    btn.addEventListener('click', () => applyMobileView(btn.dataset.view));
  });

  applyMobileView(defaultView);

  // Change listeners
  document.addEventListener('change', async (ev) => {
    const t = ev.target;
    if (!t.classList.contains('update-field')) return;

    const dayID = t.dataset.id;
    const field = t.dataset.field;
    const value = (t.type === 'checkbox') ? (t.checked ? 1 : 0) : t.value;

    try {
      await pushUpdate(dayID, field, value);
      markSuccess(t);
    } catch (err) {
      showError(err);
    }
  });

  // Keyboard shortcuts
  document.addEventListener('keydown', (e) => {
    const tag = (e.target.tagName || '').toLowerCase();
    if (['input','textarea','select'].includes(tag)) return;

    if (e.key === 'ArrowLeft') { window.location.assign(document.getElementById('btnPrev').href); }
    if (e.key === 'ArrowRight'){ window.location.assign(document.getElementById('btnNext').href); }
    if (e.key.toLowerCase() === 't'){ window.location.assign(document.getElementById('btnToday').href); }
  });
})();
</script>
</body>
</html>
