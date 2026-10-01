<?php
session_start();
require 'config.php';
require 'auth.php';

header('Content-Type: application/json; charset=utf-8');
$link->set_charset("utf8mb4");

/* if user disabled bookings, return empty */
if (isset($_GET['enabled']) && !$_GET['enabled']) {
  echo json_encode([]);
  exit;
}

$start = $_GET['start'] ?? null;
$end   = $_GET['end'] ?? null;
if (!$start || !$end) {
  echo json_encode([]);
  exit;
}

/* widen */
$startDt = date('Y-m-d 00:00:00', strtotime($start . ' -3 days'));
$endDt   = date('Y-m-d 23:59:59', strtotime($end   . ' +3 days'));

/* get bookings in range */
$sql = "SELECT BookingID, StartDateTime, EndDateTime, GuestsJSON, Occasion, Status 
        FROM Bookings
        WHERE StartDateTime <= ? AND EndDateTime >= ?
        ORDER BY StartDateTime";
$stmt = $link->prepare($sql);
$stmt->bind_param('ss', $endDt, $startDt);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
$allGuestIds = [];
while ($b = $res->fetch_assoc()) {
  $rows[] = $b;
  $decoded = json_decode($b['GuestsJSON'] ?? '[]', true);
  $guestIDs = array_filter($decoded['guests'] ?? []);
  foreach ($guestIDs as $gid) {
    $allGuestIds[(int)$gid] = true;
  }
}

/* batch contacts */
$namesById = [];
if ($allGuestIds) {
  $in = implode(',', array_map('intval', array_keys($allGuestIds)));
  $q = $link->query("SELECT ContactID, KnownAs FROM Contacts WHERE ContactID IN ($in)");
  while ($n = $q->fetch_assoc()) {
    $namesById[(int)$n['ContactID']] = $n['KnownAs'];
  }
}

$out = [];
foreach ($rows as $b) {
  $status = strtolower(trim($b['Status'] ?? ''));
  $emojiStatus = ($status === 'pencilled' || $status === 'penciled') ? ' (✏)' : (($status === 'confirmed') ? ' (✅)' : '');
  $decoded = json_decode($b['GuestsJSON'] ?? '[]', true);
  $guestIDs = array_filter($decoded['guests'] ?? []);
  $names = [];
  foreach ($guestIDs as $gid) {
    if (isset($namesById[(int)$gid])) $names[] = $namesById[(int)$gid];
  }
  $titleBase = $names ? implode(', ', $names) : ('Booking #'.$b['BookingID']);
  $title = "🏠 ".$titleBase.$emojiStatus;

  $out[] = [
    'id' => 'booking_'.$b['BookingID'],
    'title' => $title,
    'start' => date('c', strtotime($b['StartDateTime'])),
    'end'   => date('c', strtotime($b['EndDateTime'])),
    'backgroundColor' => '#9b59b6',
    'allDay' => false,
    'eventType' => 'booking',
    'description' => $b['Occasion'] ?? '',
    'url' => 'home/view_booking.php?BookingID='.(int)$b['BookingID']
  ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
$link->close();
