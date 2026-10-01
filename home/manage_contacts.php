<?php
/**************************************************************************
 *  manage_contacts.php  (MOBILE FIX: no auto-select on mobile + better layout)
 **************************************************************************/

session_start();
require '../config.php';
require '../auth.php';

$userID = (int)($_SESSION['userID'] ?? 0);

/* -------------------- Security helpers -------------------- */
function as_int($v){ return (int)$v; }
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$CSRF = $_SESSION['csrf_token'];

function require_csrf(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        exit('CSRF validation failed');
    }
}

function validate_image_upload(array $file, array $allowed = ['image/jpeg','image/pjpeg','image/png'], int $maxBytes = 5_000_000): bool {
    if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return false;
    if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > $maxBytes) return false;

    $fi = new finfo(FILEINFO_MIME_TYPE);
    $mime = $fi->file($file['tmp_name']);
    return in_array($mime, $allowed, true);
}

/* -------------------- 1) Global Preference-Type CRUD -------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['deletePref'])) {
        require_csrf();
        $pid = as_int($_POST['deletePref']);
        if ($pid > 0) {
            $stmt = $link->prepare("DELETE FROM PreferenceTypes WHERE PreferenceID=?");
            $stmt->bind_param('i', $pid);
            $stmt->execute();
        }
        header('Location: manage_contacts.php');
        exit;
    }

    if (isset($_POST['updatePrefs'])) {
        require_csrf();
        $new = isset($_POST['newPref']) ? trim((string)$_POST['newPref']) : '';
        if ($new !== '') {
            $stmt = $link->prepare("INSERT IGNORE INTO PreferenceTypes (Name) VALUES (?)");
            $stmt->bind_param('s', $new);
            $stmt->execute();
        }
        header('Location: manage_contacts.php');
        exit;
    }
}

/* -------------------- 2) Add New Contact -------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['addNew'])) {
    require_csrf();

    $knownAs = trim((string)($_POST['KnownAs'] ?? ''));
    $first   = trim((string)($_POST['FirstName'] ?? ''));
    $last    = trim((string)($_POST['LastName'] ?? ''));

    if ($knownAs === '' || $first === '' || $last === '') {
        header('Location: manage_contacts.php');
        exit;
    }

    $email = trim((string)($_POST['Email'] ?? ''));
    $phone = trim((string)($_POST['PhoneNumber'] ?? ''));
    $dob   = ($_POST['DOB'] ?? '') !== '' ? (string)$_POST['DOB'] : null;

    $stmt = $link->prepare(
        "INSERT INTO Contacts
         (KnownAs,FirstName,LastName,Email,PhoneNumber,DOB,CreatedBy,LastUpdatedBy,LastUpdatedAt)
         VALUES (?,?,?,?,?,?, ?,?,NOW())"
    );
    $stmt->bind_param('ssssssii', $knownAs, $first, $last, $email, $phone, $dob, $userID, $userID);
    $stmt->execute();
    $newID = (int)$stmt->insert_id;

    if (!empty($_FILES['PhotoUpload']['size']) && validate_image_upload($_FILES['PhotoUpload'])) {
        $disk = "/home/xohpwhmm/assets.dcworld.uk/images/contacts/{$newID}.jpg";
        if (move_uploaded_file($_FILES['PhotoUpload']['tmp_name'], $disk)) {
            @chmod($disk, 0644);
            $url = "https://assets.dcworld.uk/images/contacts/{$newID}.jpg";
            $stmtU = $link->prepare("UPDATE Contacts SET PhotoURL=? WHERE ContactID=?");
            $stmtU->bind_param('si', $url, $newID);
            $stmtU->execute();
        }
    }

    header('Location: manage_contacts.php?ContactID='.$newID);
    exit;
}

/* -------------------- 3) Edit Contact -------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ContactID']) && !isset($_POST['addNew'])) {
    require_csrf();

    $id = as_int($_POST['ContactID']);
    if ($id > 0) {
        $file = null;

        if (!empty($_FILES['PhotoUpload']['size']) && validate_image_upload($_FILES['PhotoUpload'])) {
            $disk = "/home/xohpwhmm/assets.dcworld.uk/images/contacts/{$id}.jpg";
            if (move_uploaded_file($_FILES['PhotoUpload']['tmp_name'], $disk)) {
                @chmod($disk, 0644);
                $file = "https://assets.dcworld.uk/images/contacts/{$id}.jpg";
            }
        }

        $knownAs = trim((string)($_POST['KnownAs'] ?? ''));
        $first   = trim((string)($_POST['FirstName'] ?? ''));
        $last    = trim((string)($_POST['LastName'] ?? ''));
        $phone   = trim((string)($_POST['PhoneNumber'] ?? ''));
        $email   = trim((string)($_POST['Email'] ?? ''));
        $dob     = ($_POST['DOB'] ?? '') !== '' ? (string)$_POST['DOB'] : null;
        $street  = trim((string)($_POST['StreetAddress'] ?? ''));
        $city    = trim((string)($_POST['City'] ?? ''));
        $postcode= trim((string)($_POST['Postcode'] ?? ''));

        $sql = "UPDATE Contacts SET
                  KnownAs=?, FirstName=?, LastName=?, PhoneNumber=?, Email=?, DOB=?,
                  StreetAddress=?, City=?, Postcode=?,
                  LastUpdatedBy=?, LastUpdatedAt=NOW()"
              . ($file ? ", PhotoURL=?" : "")
              . " WHERE ContactID=?";

        if ($file) {
            $stmt = $link->prepare($sql);
            $stmt->bind_param(
                'sssssssssisi',
                $knownAs, $first, $last, $phone, $email, $dob, $street, $city, $postcode,
                $userID, $file, $id
            );
        } else {
            $stmt = $link->prepare($sql);
            $stmt->bind_param(
                'sssssssssii',
                $knownAs, $first, $last, $phone, $email, $dob, $street, $city, $postcode,
                $userID, $id
            );
        }
        $stmt->execute();

        $prefIDs = $link->query("SELECT PreferenceID FROM PreferenceTypes");
        while ($pt = $prefIDs->fetch_assoc()) {
            $pid = (int)$pt['PreferenceID'];
            $field = 'pref_' . $pid;

            $value = isset($_POST[$field]) ? trim((string)$_POST[$field]) : '';
            if ($value === '') {
                $stmtD = $link->prepare("DELETE FROM ContactPreferences WHERE ContactID=? AND PreferenceID=?");
                $stmtD->bind_param('ii', $id, $pid);
                $stmtD->execute();
            } else {
                $stmtP = $link->prepare(
                    "REPLACE INTO ContactPreferences (ContactID,PreferenceID,Value) VALUES (?,?,?)"
                );
                $stmtP->bind_param('iis', $id, $pid, $value);
                $stmtP->execute();
            }
        }
    }

    header('Location: manage_contacts.php?ContactID='.(int)$id);
    exit;
}

/* -------------------- 4) Dictionaries -------------------- */
$prefTypes = $link->query("SELECT PreferenceID, Name FROM PreferenceTypes ORDER BY Name")->fetch_all(MYSQLI_ASSOC);
$prefNameToId = [];
foreach ($prefTypes as $pt) {
    $prefNameToId[$pt['Name']] = (int)$pt['PreferenceID'];
}

/* -------------------- 5) Contacts list -------------------- */
$contacts = $link->query(
    "SELECT ContactID, KnownAs, FirstName, LastName, Email, PhoneNumber, City, PhotoURL
     FROM Contacts
     ORDER BY LastName, FirstName, KnownAs"
)->fetch_all(MYSQLI_ASSOC);

$defaultImg = 'https://assets.dcworld.uk/images/contacts/Black%20and%20white%20organic%20farmhouse%20mountain%20landscape%20hand%20drawn%20logo.png';

$grouped = [];
foreach ($contacts as $ct) {
    $candidate = trim(($ct['LastName'] ?? '')) ?: trim(($ct['KnownAs'] ?? '')) ?: '#';
    $firstChar = strtoupper(substr($candidate, 0, 1));
    if (!preg_match('/[A-Z]/', $firstChar)) { $firstChar = '#'; }
    $grouped[$firstChar][] = $ct;
}
ksort($grouped);

$initialContactID = isset($_GET['ContactID']) ? (int)$_GET['ContactID'] : 3;

$boot = [
    'defaultImg'       => $defaultImg,
    'prefNameToId'     => $prefNameToId,
    'initialContactID' => $initialContactID,
    'csrf'             => $CSRF,
];
$BOOT_JSON = json_encode(
    $boot,
    JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|
    JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE
);
?>
<?php include '../header.php'; ?>
<?php include 'subheader.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Manage Contacts</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <style>
.topbar{ z-index: 900; }


    body{background:#f8f9fa;}
    .topbar{position:sticky;top:0;z-index:1030;background:#f8f9fa;border-bottom:1px solid #e9ecef}
    .sidebar-shell{background:#fff;border-right:1px solid #e9ecef;min-height:calc(100vh - 70px)}
    #contactList{max-height:calc(100vh - 205px);overflow-y:auto;border-top:1px solid #eef2f7;background:#fff}

    .contact-item{padding:10px 12px;display:flex;align-items:center;gap:.65rem;cursor:pointer;border-bottom:1px solid #f1f1f1;transition:background .1s}
    .contact-item:hover{background:#f1f5f9;}
    .contact-item.active{background:#eef2ff;}
    .contact-thumb{width:38px;height:38px;border-radius:50%;object-fit:cover;flex:0 0 auto;}
    .alpha-header{font-size:.75rem;font-weight:700;color:#495057;background:#f3f4f6;padding:6px 12px;border-top:1px solid #eef2f7;border-bottom:1px solid #eef2f7;}
    .empty-hint{padding:1rem;color:#6c757d;font-size:.9rem;}

    #contactDetailsCard{background:#fff;border:1px solid #e5e5e5;border-radius:1rem;box-shadow:0 2px 8px rgba(0,0,0,.05);padding:24px;min-height:70vh;}
    #profileImage{width:120px;height:120px;border-radius:50%;object-fit:cover;border:3px solid #dee2e6;}

    .bookings-list{list-style:none;padding:0;margin:0;}
    .bookings-list li{display:flex;align-items:center;gap:1rem;justify-content:space-between;padding:.75rem 1rem;margin-bottom:.65rem;background:#fdfefe;border:1px solid #e3e8ee;border-radius:.75rem;box-shadow:0 1px 2px rgba(0,0,0,.04);transition:transform .15s ease,box-shadow .15s ease;position:relative;}
    .bookings-list li:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(0,0,0,.06);cursor:pointer;}
    .bookings-list li::before{content:'';position:absolute;left:0;top:10%;width:6px;height:80%;border-radius:4px;background:var(--booking-status, #6c757d);}
    .bookings-list li[data-status="Confirmed"]{ --booking-status:#38a169; }
    .bookings-list li[data-status="Pending"]  { --booking-status:#f59e0b; }
    .bookings-list li[data-status="Cancelled"]{ --booking-status:#e53e3e; }
    .booking-info{flex:1 1 auto;}
    .booking-date{font-weight:600;font-size:.9rem;}
    .booking-occasion{font-size:.78rem;color:#6b7280;}

    .toast-container{position:fixed;top:1rem;right:1rem;z-index:1080;}

    .details-skeleton .sk{background:#eef2f7;border-radius:10px;animation:pulse 1.2s infinite ease-in-out;}
    .sk.line{height:12px;margin:10px 0;}
    .sk.big{height:18px;margin:10px 0;width:55%;}
    .sk.avatar{width:120px;height:120px;border-radius:50%;}
    @keyframes pulse{0%{opacity:.7}50%{opacity:1}100%{opacity:.7}}

    /* -------------------- MOBILE LAYOUT FIXES --------------------
       - Sidebar becomes full-screen "panel" when expanded
       - Details uses full width and better padding
       - No auto-select of first contact on mobile
    */
    @media (max-width: 767.98px){
      .container-fluid{padding-left:0 !important; padding-right:0 !important;}
      .row{margin-left:0 !important; margin-right:0 !important;}
      .col-md-3,.col-md-9{padding-left:0 !important; padding-right:0 !important;}

      #sidebar{background:#fff;}
      .sidebar-shell{min-height:calc(100vh - 56px); border-right:none;}
      #contactList{max-height:calc(100vh - 250px);}

      #contactDetailsCard{
        border-radius:0;
        border-left:none;
        border-right:none;
        padding:16px;
        min-height:calc(100vh - 80px);
      }

      #profileImage{width:88px;height:88px;}
      .bookings-list li{padding:.7rem .85rem;}

      /* Make the sidebar collapse behave like a drawer panel */
      #sidebar.collapse:not(.show){display:none;}
      #sidebar.collapse.show{
        display:block;
        position:fixed;
        top:56px; /* topbar approx height */
        left:0;
        right:0;
        bottom:0;
        z-index:1040;
        overflow:hidden;
      }
      #sidebar .sidebar-shell{
        height:100%;
        overflow:hidden;
      }
    }
    /* --- Fix header dropdown appearing under Manage Contacts topbar --- */
/* Keep the Manage Contacts bar above page content... */
.topbar { position: sticky; top: 0; z-index: 1030; }

/* ...but ensure the site header/nav + dropdowns are ABOVE it */
header, .navbar, .navbar-nav, .dropdown, .dropdown-menu {
  z-index: 1200 !important;
}

header, .navbar {
  position: relative;
}

/* If your header uses overflow hidden anywhere, dropdowns can get clipped */
header, .navbar, .subheader {
  overflow: visible !important;
}

  </style>
</head>
<body>

<div class="toast-container" id="toasts"></div>

<div class="topbar">
  <div class="d-flex justify-content-between align-items-center px-3 py-2">
    <div class="d-flex gap-2">
      <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#settingsModal">⚙️ Preferences</button>
    </div>
    <!-- Mobile: open drawer -->
    <button class="d-md-none btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#sidebar" aria-controls="sidebar" aria-expanded="false">
      Contacts
    </button>
  </div>
</div>

<div class="container-fluid px-md-4">
  <div class="row g-0">
    <!-- sidebar -->
    <div id="sidebar" class="col-md-3 collapse d-md-block show p-0">
      <div class="sidebar-shell">
        <div class="p-3">
          <button class="btn btn-success w-100 mb-3" data-bs-toggle="modal" data-bs-target="#newContactModal">+ Add Contact</button>

          <input id="searchInput" class="form-control form-control-sm mb-2" placeholder="Search name..." type="text" autocomplete="off">
          <a id="toggleAdvanced" class="small d-block mb-2" href="#">Advanced search</a>

          <div id="advancedSearch" style="display:none;">
            <input id="searchEmail" class="form-control form-control-sm mb-1" placeholder="Email" autocomplete="off">
            <input id="searchPhone" class="form-control form-control-sm mb-1" placeholder="Phone" autocomplete="off">
            <input id="searchCity"  class="form-control form-control-sm mb-1" placeholder="City" autocomplete="off">
          </div>

          <!-- mobile helper -->
          <div class="d-md-none text-muted small mt-2">
            Tap a contact to open details.
          </div>
        </div>

        <div id="contactList">
          <?php if (empty($grouped)): ?>
            <div class="empty-hint">No contacts yet. Use “Add Contact” to get started.</div>
          <?php else: ?>
            <?php foreach ($grouped as $ltr => $arr): ?>
              <div class="alpha-header"><?= e($ltr) ?></div>
              <?php foreach ($arr as $c): ?>
                <?php
                  $thumb   = $c['PhotoURL'] ?: $defaultImg;
                  $display = trim(($c['FirstName'] ?? '').' '.($c['LastName'] ?? ''));
                  if ($display === '') $display = $c['KnownAs'] ?? '—';
                  $searchName = strtolower(trim(($c['KnownAs'] ?? '').' '.($c['FirstName'] ?? '').' '.($c['LastName'] ?? '')));
                  $searchEmail= strtolower((string)($c['Email'] ?? ''));
                  $searchPhone= strtolower((string)($c['PhoneNumber'] ?? ''));
                  $searchCity = strtolower((string)($c['City'] ?? ''));
                ?>
                <div class="contact-item"
                     data-id="<?= (int)$c['ContactID'] ?>"
                     data-name="<?= e($searchName) ?>"
                     data-email="<?= e($searchEmail) ?>"
                     data-phone="<?= e($searchPhone) ?>"
                     data-city="<?= e($searchCity) ?>"
                     title="Open <?= e($display) ?>">
                  <img src="<?= e($thumb) ?>" class="contact-thumb" onerror="this.src='<?= e($defaultImg) ?>'">
                  <div class="flex-grow-1">
                    <div class="fw-semibold small mb-0"><?= e($display) ?></div>
                    <?php if (!empty($c['Email'])): ?>
                      <div class="text-muted" style="font-size:.75rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:210px;">
                        <?= e($c['Email']) ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- details -->
    <div class="col-md-9 p-0 px-md-3">
      <div id="contactDetailsCard" class="mt-0 mt-md-3">
        <p class="text-muted m-0">Select a contact to view their details.</p>
      </div>
    </div>
  </div>
</div>

<!-- --------------------  MODALS (unchanged) -------------------- -->
<!-- Edit Contact Modal -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form id="editForm" method="POST" enctype="multipart/form-data" class="modal-content">
      <input type="hidden" name="csrf_token" value="<?= e($CSRF) ?>">
      <div class="modal-header">
        <h5 class="modal-title">Edit Contact</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <ul class="nav nav-tabs mb-3">
          <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-basic">Basic</a></li>
          <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-contact">Contact</a></li>
          <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-address">Address</a></li>
          <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-prefs">Preferences</a></li>
        </ul>
        <div class="tab-content">
          <input type="hidden" name="ContactID" id="editContactID">

          <div id="tab-basic" class="tab-pane fade show active">
            <div class="mb-2"><label class="form-label">Known As</label><input name="KnownAs" class="form-control" required></div>
            <div class="mb-2"><label class="form-label">First Name</label><input name="FirstName" class="form-control" required></div>
            <div class="mb-2"><label class="form-label">Last Name</label><input name="LastName" class="form-control" required></div>
            <div class="mb-2"><label class="form-label">Date of Birth</label><input type="date" name="DOB" class="form-control"></div>
            <div class="mb-2"><label class="form-label">Profile Image</label><input type="file" name="PhotoUpload" class="form-control" accept="image/*"></div>
          </div>

          <div id="tab-contact" class="tab-pane fade">
            <div class="mb-2"><label class="form-label">Phone</label><input name="PhoneNumber" class="form-control"></div>
            <div class="mb-2"><label class="form-label">Email</label><input type="email" name="Email" class="form-control"></div>
          </div>

          <div id="tab-address" class="tab-pane fade">
            <div class="mb-2"><label class="form-label">Street</label><input name="StreetAddress" class="form-control"></div>
            <div class="mb-2"><label class="form-label">City</label><input name="City" class="form-control"></div>
            <div class="mb-2"><label class="form-label">Postcode</label><input name="Postcode" class="form-control"></div>
          </div>

          <div id="tab-prefs" class="tab-pane fade">
            <?php foreach ($prefTypes as $pt): ?>
              <div class="mb-2">
                <label class="form-label"><?= e($pt['Name']) ?></label>
                <input name="pref_<?= (int)$pt['PreferenceID'] ?>" class="form-control">
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="modal-footer d-flex justify-content-between">
        <button type="button" class="btn btn-outline-danger" id="openDeleteFromEdit">Delete</button>
        <div class="d-flex gap-2">
          <button class="btn btn-success">Save</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- New Contact Modal -->
<div class="modal fade" id="newContactModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="POST" enctype="multipart/form-data" class="modal-content">
      <input type="hidden" name="csrf_token" value="<?= e($CSRF) ?>">
      <div class="modal-header">
        <h5 class="modal-title">Add New Contact</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <input type="hidden" name="addNew" value="1">
        <div class="mb-2"><label class="form-label">Known As</label><input name="KnownAs" class="form-control" required></div>
        <div class="mb-2"><label class="form-label">First Name</label><input name="FirstName" class="form-control" required></div>
        <div class="mb-2"><label class="form-label">Last Name</label><input name="LastName" class="form-control" required></div>
        <div class="mb-2"><label class="form-label">Date of Birth</label><input type="date" name="DOB" class="form-control"></div>
        <div class="mb-2"><label class="form-label">Email</label><input type="email" name="Email" class="form-control"></div>
        <div class="mb-2"><label class="form-label">Phone</label><input name="PhoneNumber" class="form-control"></div>
        <div class="mb-2"><label class="form-label">Profile Image</label><input type="file" name="PhotoUpload" class="form-control" accept="image/*"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-success">Create</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete Confirm Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:520px;">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title text-danger">Delete Contact</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">This action permanently deletes the contact and their saved preferences.</p>
        <div class="alert alert-warning mb-0">
          <strong id="deleteName">Contact</strong> will be deleted. This cannot be undone.
        </div>
      </div>
      <div class="modal-footer d-flex justify-content-between">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
      </div>
    </div>
  </div>
</div>

<!-- Global Preference Types Modal -->
<div class="modal fade" id="settingsModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:500px;">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Global Preference Types</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <form method="POST" class="mb-3">
          <input type="hidden" name="csrf_token" value="<?= e($CSRF) ?>">
          <div class="mb-2">
            <label class="form-label">Add New Preference</label>
            <input name="newPref" class="form-control" placeholder="e.g. Dietary Needs"></div>
          <button name="updatePrefs" class="btn btn-success">Add</button>
        </form>
        <ul class="list-group">
          <?php foreach ($prefTypes as $pt): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
              <?= e($pt['Name']) ?>
              <form method="POST" class="m-0" onsubmit="return confirm('Delete this type?');">
                <input type="hidden" name="csrf_token" value="<?= e($CSRF) ?>">
                <input type="hidden" name="deletePref" value="<?= (int)$pt['PreferenceID'] ?>">
                <button class="btn btn-sm btn-danger">Delete</button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="modal-footer"><button class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
    </div>
  </div>
</div>

<?php include '../footer.php'; ?>

<script type="application/json" id="bootData"><?= $BOOT_JSON ?: '{}' ?></script>

<script>
let BOOT = {};
try {
  const el = document.getElementById('bootData');
  BOOT = el && el.textContent ? JSON.parse(el.textContent) : {};
} catch(e) { BOOT = {}; }

const defaultImg       = BOOT.defaultImg       ?? '';
const prefNameToId     = BOOT.prefNameToId     ?? {};
const initialContactID = BOOT.initialContactID ?? 0;
const CSRF             = BOOT.csrf             ?? '';

const detailsCache = new Map();
let activeContactId = 0;

function isMobile(){
  return window.matchMedia && window.matchMedia('(max-width: 767.98px)').matches;
}

function toast(msg, type='danger'){
  const id='t'+Date.now();
  const html=`<div id="${id}" class="toast align-items-center text-bg-${type} border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex"><div class="toast-body">${msg}</div>
    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div></div>`;
  $('#toasts').append(html);
  const el = document.getElementById(id);
  const t = new bootstrap.Toast(el, {delay:3000});
  t.show();
}

function escapeHtml(s){
  return String(s ?? '')
    .replaceAll('&','&amp;')
    .replaceAll('<','&lt;')
    .replaceAll('>','&gt;')
    .replaceAll('"','&quot;')
    .replaceAll("'","&#039;");
}

function showSkeleton(){
  $('#contactDetailsCard').html(`
    <div class="details-skeleton">
      <div class="d-flex align-items-center gap-3 mb-3">
        <div class="sk avatar"></div>
        <div class="flex-grow-1">
          <div class="sk big"></div>
          <div class="sk line" style="width:40%"></div>
        </div>
      </div>
      <div class="sk line" style="width:70%"></div>
      <div class="sk line" style="width:60%"></div>
      <div class="sk line" style="width:85%"></div>
      <div class="sk line" style="width:55%"></div>
    </div>
  `);
}

async function fetchContactDetails(id){
  if (detailsCache.has(id)) return detailsCache.get(id);

  const url = new URL('contact_details_ajax.php', window.location.href);
  url.searchParams.set('id', id);

  const res = await fetch(url.toString(), {
    method: 'GET',
    headers: { 'Accept': 'application/json' },
    credentials: 'same-origin'
  });

  if (!res.ok) throw new Error('Failed to fetch details');
  const data = await res.json();
  if (!data || !data.success) throw new Error(data?.error || 'Not found');

  detailsCache.set(id, data.contact);
  return data.contact;
}

function bookingBadge(status){
  const s = status || '';
  const cls = s==='Confirmed'?'bg-success':(s==='Pending'?'bg-warning text-dark':(s==='Cancelled'?'bg-danger':'bg-secondary'));
  return `<span class="badge ${cls}">${escapeHtml(s || '—')}</span>`;
}

function fmtDateTime(t){
  if(!t) return '';
  const d = new Date(t);
  if (isNaN(d.getTime())) return escapeHtml(t);
  return d.toLocaleString(undefined,{day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
}
function fmtDate(t){
  if(!t) return '';
  const d = new Date(t);
  if (isNaN(d.getTime())) return escapeHtml(t);
  return d.toLocaleDateString(undefined,{day:'numeric',month:'short',year:'2-digit'});
}

function renderDetails(d){
  const prefs = d.Preferences || {};
  const up = d.UpcomingBookings || [];
  const past = d.PastBookings || [];

  // Mobile: add a back button to reopen the drawer quickly
  const mobileBack = isMobile()
    ? `<button class="btn btn-outline-secondary btn-sm me-2" id="backToListBtn">Back</button>`
    : ``;

  let html = `
    <div class="d-flex align-items-center mb-4">
      ${mobileBack}
      <img id="profileImage" src="${escapeHtml(d.PhotoURL||defaultImg)}" onerror="this.src='${escapeHtml(defaultImg)}'" alt="Profile">
      <div class="ms-3">
        <h4 class="mb-0">${escapeHtml(d.KnownAs||'(No Known-As)')}</h4>
        <small class="text-muted">${escapeHtml((d.FirstName||'')+' '+(d.LastName||''))}</small>
      </div>
      <div class="ms-auto d-flex gap-2">
        <button class="btn btn-outline-danger btn-sm" id="deleteBtn">Delete</button>
        <button class="btn btn-warning btn-sm" id="editBtn">Edit</button>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-md-6">
        <h6 class="text-uppercase text-muted">Contact</h6>
        ${d.Email ? `<p class="mb-1"><a href="mailto:${escapeHtml(d.Email)}">${escapeHtml(d.Email)}</a></p>` : '<p class="mb-1 text-muted">—</p>'}
        ${d.PhoneNumber ? `<p class="mb-1"><a href="tel:${escapeHtml(d.PhoneNumber)}">${escapeHtml(d.PhoneNumber)}</a></p>` : ''}
      </div>
      <div class="col-md-6">
        <h6 class="text-uppercase text-muted">Address</h6>
        <p class="mb-1">${escapeHtml([d.StreetAddress,d.City,d.Postcode].filter(Boolean).join(', ') || '—')}</p>
      </div>
    </div>
  `;

  const prefRows = Object.keys(prefs)
    .filter(k => String(prefs[k]||'').trim())
    .map(k => `<li class="mb-1"><strong>${escapeHtml(k)}:</strong> ${escapeHtml(prefs[k])}</li>`)
    .join('');

  if (prefRows) html += `<h6 class="mt-4 text-uppercase text-muted">Preferences</h6><ul class="mb-0">${prefRows}</ul>`;

  if (up.length) {
    html += `<h6 class="mt-4 text-uppercase text-muted">Upcoming Bookings</h6><ul class="bookings-list">`;
    up.forEach(b => {
      html += `
        <li data-status="${escapeHtml(b.Status||'')}">
          <a href="view_booking.php?BookingID=${encodeURIComponent(b.BookingID)}" class="text-reset text-decoration-none flex-grow-1">
            <div class="booking-info">
              <div class="booking-date">${escapeHtml(fmtDateTime(b.StartDateTime))} → ${escapeHtml(fmtDateTime(b.EndDateTime))}</div>
              <div class="booking-occasion">${escapeHtml(b.Occasion||'')}</div>
            </div>
          </a>
          ${bookingBadge(b.Status)}
        </li>`;
    });
    html += `</ul>`;
  }

  if (past.length) {
    html += `<h6 class="mt-4 text-uppercase text-muted">Previous Bookings</h6><ul class="bookings-list">`;
    past.forEach(b => {
      html += `
        <li data-status="${escapeHtml(b.Status||'')}">
          <a href="view_booking.php?BookingID=${encodeURIComponent(b.BookingID)}" class="text-reset text-decoration-none flex-grow-1">
            <div class="booking-info">
              <div class="booking-date">${escapeHtml(fmtDate(b.StartDateTime))}</div>
              <div class="booking-occasion">${escapeHtml(b.Occasion||'')}</div>
            </div>
          </a>
          ${bookingBadge(b.Status)}
        </li>`;
    });
    html += `</ul>`;
  }

  html += `
    <div class="mt-4 small text-muted">
      Created by: ${escapeHtml(d.CreatedByName || d.CreatedBy || '–')} |
      Last updated by: ${escapeHtml(d.LastUpdatedByName || d.LastUpdatedBy || '–')} |
      ${d.LastUpdatedAt ? ('Updated ' + escapeHtml(d.LastUpdatedAt)) : ''}
    </div>
  `;

  $('#contactDetailsCard').html(html);

  // Mobile back opens the drawer
  $(document).off('click','#backToListBtn').on('click','#backToListBtn', function(){
    const sb = document.getElementById('sidebar');
    if (sb && bootstrap?.Collapse) {
      const inst = bootstrap.Collapse.getOrCreateInstance(sb, {toggle:false});
      inst.show();
    }
  });

  // Edit
  $(document).off('click','#editBtn').on('click','#editBtn',function(){
    $('#editContactID').val(d.ContactID);
    $('#editForm [name=KnownAs]').val(d.KnownAs||'');
    $('#editForm [name=FirstName]').val(d.FirstName||'');
    $('#editForm [name=LastName]').val(d.LastName||'');
    $('#editForm [name=PhoneNumber]').val(d.PhoneNumber||'');
    $('#editForm [name=Email]').val(d.Email||'');
    $('#editForm [name=DOB]').val(d.DOB||'');
    $('#editForm [name=StreetAddress]').val(d.StreetAddress||'');
    $('#editForm [name=City]').val(d.City||'');
    $('#editForm [name=Postcode]').val(d.Postcode||'');

    for (const [prefName, prefId] of Object.entries(prefNameToId)) {
      const val = (d.Preferences && d.Preferences[prefName]) ? d.Preferences[prefName] : '';
      $('#editForm [name="pref_'+prefId+'"]').val(val);
    }

    new bootstrap.Modal(document.getElementById('editModal')).show();
  });

  // Delete (your existing delete modal wiring remains; keep your openDeleteModal() + handlers)
}

async function openContact(id){
  try{
    activeContactId = Number(id);
    showSkeleton();

    const d = await fetchContactDetails(activeContactId);
    renderDetails(d);

    if (window.history && activeContactId) {
      const url = new URL(window.location.href);
      url.searchParams.set('ContactID', activeContactId);
      window.history.replaceState({}, '', url);
    }
  } catch (err) {
    console.error(err);
    toast('Failed to load contact details.');
    $('#contactDetailsCard').html('<p class="text-muted m-0">Failed to load contact details.</p>');
  }
}

/* ====== Search ====== */
$('#toggleAdvanced').on('click', function(e){
  e.preventDefault();
  $('#advancedSearch').slideToggle();
});

$('#searchInput,#searchEmail,#searchPhone,#searchCity').on('input', function(){
  const qName = ($('#searchInput').val()  || '').toLowerCase();
  const qMail = ($('#searchEmail').val()  || '').toLowerCase();
  const qPhone= ($('#searchPhone').val()  || '').toLowerCase();
  const qCity = ($('#searchCity').val()   || '').toLowerCase();

  $('#contactList .contact-item').each(function(){
     const $el = $(this);
     const name  = ($el.data('name')  || '').toString();
     const email = ($el.data('email') || '').toString();
     const phone = ($el.data('phone') || '').toString();
     const city  = ($el.data('city')  || '').toString();

     let show = true;
     if (qName && !name.includes(qName)) show = false;
     if (qMail && !email.includes(qMail)) show = false;
     if (qPhone && !phone.includes(qPhone)) show = false;
     if (qCity && !city.includes(qCity)) show = false;

     $el.toggle(show);
  });

  $('#contactList .alpha-header').each(function(){
    let $hdr = $(this);
    let $next = $hdr.next();
    let anyVisible = false;
    while ($next.length && !$next.hasClass('alpha-header')) {
      if ($next.is(':visible')) { anyVisible = true; break; }
      $next = $next.next();
    }
    $hdr.toggle(anyVisible);
  });
});

/* ====== Click list -> load details ====== */
$('#contactList').on('click','.contact-item',function(){
  $('#contactList .contact-item').removeClass('active');
  $(this).addClass('active');

  const id = $(this).attr('data-id');
  openContact(id);

  // On mobile: close the drawer cleanly (do not toggle, explicitly hide)
  if (isMobile()) {
    const sb = document.getElementById('sidebar');
    if (sb && bootstrap?.Collapse) {
      const inst = bootstrap.Collapse.getOrCreateInstance(sb, {toggle:false});
      inst.hide();
    }
  }
});

/* ====== Deep link + mobile default behavior ======
   Desktop: open initialContactID or first contact
   Mobile: ONLY open if deep-linked; otherwise leave blank (no auto-select)
*/
$(function(){
  try{
    const $target = initialContactID
      ? $('#contactList .contact-item[data-id="'+initialContactID+'"]')
      : $();

    if ($target.length) {
      $target.trigger('click');
      const parent = document.getElementById('contactList');
      const el = $target.get(0);
      if (parent && el) parent.scrollTop = el.offsetTop - 60;
      return;
    }

    if (!isMobile()) {
      const $first = $('#contactList .contact-item:visible').first();
      if ($first.length) $first.trigger('click');
    }
  } catch(err){
    console.error(err);
    toast('Failed to initialize contact view.');
  }
});

// If orientation changes and sidebar overlay is open, ensure it stays usable
window.addEventListener('resize', () => {
  // no-op; layout is handled by CSS media queries
});
</script>
</body>
</html>
