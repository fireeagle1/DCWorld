<?php
// contact_details_ajax.php
// Returns full contact details + preferences + upcoming/past bookings (no N+1 on page load)

session_start();
require '../config.php';
require '../auth.php';

header('Content-Type: application/json; charset=utf-8');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid id']);
    exit;
}

/* Contact + created/updated names */
$sql = "
  SELECT
    c.*,
    u1.Name AS CreatedByName,
    u2.Name AS LastUpdatedByName
  FROM Contacts c
  LEFT JOIN DC_Users u1 ON u1.UserID = c.CreatedBy
  LEFT JOIN DC_Users u2 ON u2.UserID = c.LastUpdatedBy
  WHERE c.ContactID = ?
  LIMIT 1
";
$stmt = $link->prepare($sql);
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$contact = $res->fetch_assoc();

if (!$contact) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Not found']);
    exit;
}

/* Preferences (name->value) */
$prefs = [];
$sqlP = "
  SELECT pt.Name, cp.Value
  FROM ContactPreferences cp
  INNER JOIN PreferenceTypes pt ON pt.PreferenceID = cp.PreferenceID
  WHERE cp.ContactID = ?
  ORDER BY pt.Name
";
$stmtP = $link->prepare($sqlP);
$stmtP->bind_param('i', $id);
$stmtP->execute();
$rP = $stmtP->get_result();
while ($row = $rP->fetch_assoc()) {
    $prefs[$row['Name']] = $row['Value'];
}
$contact['Preferences'] = $prefs;

/* Bookings (JSON_SEARCH on GuestsJSON) */
$sqlBUpcoming = "
  SELECT BookingID, StartDateTime, EndDateTime, Status, Occasion
  FROM Bookings
  WHERE JSON_SEARCH(GuestsJSON,'one',CAST(? AS CHAR),NULL,'$.guests[*]') IS NOT NULL
    AND EndDateTime >= NOW()
  ORDER BY StartDateTime ASC
  LIMIT 5
";
$stmtBU = $link->prepare($sqlBUpcoming);
$stmtBU->bind_param('i', $id);
$stmtBU->execute();
$contact['UpcomingBookings'] = $stmtBU->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];

$sqlBPast = "
  SELECT BookingID, StartDateTime, EndDateTime, Status, Occasion
  FROM Bookings
  WHERE JSON_SEARCH(GuestsJSON,'one',CAST(? AS CHAR),NULL,'$.guests[*]') IS NOT NULL
    AND EndDateTime < NOW()
  ORDER BY StartDateTime DESC
  LIMIT 5
";
$stmtBP = $link->prepare($sqlBPast);
$stmtBP->bind_param('i', $id);
$stmtBP->execute();
$contact['PastBookings'] = $stmtBP->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];

echo json_encode(['success' => true, 'contact' => $contact], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
