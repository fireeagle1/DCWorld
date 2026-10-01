<?php
session_start();
require 'config.php';
require 'auth.php';

header('Content-Type: application/json; charset=utf-8');
$link->set_charset("utf8mb4");

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

/* we only need month/day between start and end, so get all contacts but generate only in range */
$sql = "SELECT ContactID, KnownAs, FirstName, LastName, DOB 
        FROM Contacts 
        WHERE DOB IS NOT NULL AND DOB <> '0000-00-00'";
$res = $link->query($sql);

$birthdayColor = '#ff8c00';
$out = [];

$startTs = strtotime($start);
$endTs   = strtotime($end);

/* helper: check if YYYY-MM-DD is between start/end (inclusive) */
function date_in_range($ts, $startTs, $endTs){
  return ($ts >= $startTs && $ts <= $endTs);
}

$yearStart = (int)date('Y', $startTs);
$yearEnd   = (int)date('Y', $endTs);

while ($c = $res->fetch_assoc()) {
  $dob = (string)$c['DOB'];
  if (!preg_match('/^\d{4}-(\d{2})-(\d{2})$/', $dob, $m)) continue;
  $mm = (int)$m[1]; $dd = (int)$m[2];
  if (!checkdate($mm, $dd, 2000)) continue;

  $name = trim((string)$c['KnownAs']);
  if ($name === '') {
    $first = trim((string)$c['FirstName']);
    $last  = trim((string)$c['LastName']);
    $name = trim($first . ' ' . $last);
    if ($name === '') $name = 'Unknown';
  }

  /* generate only for visible years (usually 1) */
  for ($y = $yearStart; $y <= $yearEnd; $y++) {
    $mdY = sprintf('%04d-%02d-%02d', $y, $mm, $dd);
    if ($mm === 2 && $dd === 29 && !checkdate(2, 29, $y)) {
      $mdY = sprintf('%04d-02-28', $y);
    }
    $ts = strtotime($mdY);
    if (!date_in_range($ts, $startTs, $endTs)) continue;

    $out[] = [
      'id' => 'contact_'.$c['ContactID'].'_bday_'.$y,
      'title' => '🎂 '.$name,
      'start' => $mdY,
      'allDay' => true,
      'backgroundColor' => $birthdayColor,
      'eventType' => 'birthday',
      'url' => 'home/manage_contacts.php?ContactID='.(int)$c['ContactID']
    ];
  }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
$link->close();
