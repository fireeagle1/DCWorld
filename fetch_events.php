<?php
session_start();
require 'config.php';
require 'auth.php';

header('Content-Type: application/json; charset=utf-8');
$link->set_charset("utf8mb4");

$userID = $_SESSION['userID'] ?? 0;

/* colors */
$sqlColors = "SELECT SettingKey, SettingValue FROM UserSettings 
              WHERE UserID = ? 
              AND SettingKey IN ('DailyOpsColor','EventColor','OnCallColor','DutySheetColor')";
$stmtColors = $link->prepare($sqlColors);
$stmtColors->bind_param('i', $userID);
$stmtColors->execute();
$resColors = $stmtColors->get_result();
$colorSettings = [];
while ($row = $resColors->fetch_assoc()) $colorSettings[$row['SettingKey']] = $row['SettingValue'];

$dailyOpsColor  = $colorSettings['DailyOpsColor'] ?? '#29af8f';
$eventColor     = $colorSettings['EventColor'] ?? '#bb3678';
$onCallColor    = $colorSettings['OnCallColor'] ?? '#ff0000';
$dutySheetColor = $colorSettings['DutySheetColor'] ?? '#FFD700';

/* pills passed from JS */
$showWork = isset($_GET['pill_work']) ? (bool)$_GET['pill_work'] : true;
$showNight = isset($_GET['pill_night']) ? (bool)$_GET['pill_night'] : true;
$showEvents = isset($_GET['pill_regular']) ? (bool)$_GET['pill_regular'] : true;
$showDuty = isset($_GET['pill_duty']) ? (bool)$_GET['pill_duty'] : true;
$showOnCall = isset($_GET['pill_oncall']) ? (bool)$_GET['pill_oncall'] : true;

/* date range from FullCalendar */
$start = $_GET['start'] ?? null;
$end   = $_GET['end'] ?? null;
if (!$start || !$end) {
  http_response_code(400);
  echo json_encode([]);
  exit;
}

/* widen a bit */
$startDt = date('Y-m-d 00:00:00', strtotime($start . ' -7 days'));
$endDt   = date('Y-m-d 23:59:59', strtotime($end   . ' +7 days'));

$out = [];

/* events + dutysheet */
if ($showEvents || $showDuty) {
  $sql = "SELECT EventID, EventTitle, StartDateTime, EndDateTime, Location, AllDay, Source
          FROM Events
          WHERE StartDateTime <= ? AND (EndDateTime IS NULL OR EndDateTime >= ?)";
  $stmt = $link->prepare($sql);
  $stmt->bind_param('ss', $endDt, $startDt);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $src = trim((string)($row['Source'] ?? ''));
    $isDuty = (strcasecmp($src, 'DutySheet') === 0);
    if ($isDuty && !$showDuty) continue;
    if (!$isDuty && !$showEvents) continue;
    $title = (string)$row['EventTitle'];
    if ($isDuty) $title = "🚨 ".$title;
    $out[] = [
      'id' => (int)$row['EventID'],
      'title' => $title,
      'start' => date('c', strtotime($row['StartDateTime'])),
      'end'   => $row['EndDateTime'] ? date('c', strtotime($row['EndDateTime'])) : null,
      'location' => $row['Location'],
      'backgroundColor' => $isDuty ? $dutySheetColor : $eventColor,
      'allDay' => ((int)$row['AllDay'] === 1),
      'eventType' => $isDuty ? 'dutysheet' : 'regular',
      'url' => 'view_event.php?eventId='.(int)$row['EventID']
    ];
  }
}

/* on-call from DCDailyOpsPlan */
if ($showOnCall) {
  $sql = "SELECT Date, CKOnCall, DCOnCall 
          FROM DCDailyOpsPlan
          WHERE Date BETWEEN ? AND ?
          AND (CKOnCall=1 OR DCOnCall=1)";
  $stmt = $link->prepare($sql);
  $stmt->bind_param('ss', $startDt, $endDt);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($row = $res->fetch_assoc()) {
    $parts = [];
    if ((int)$row['CKOnCall'] === 1) $parts[] = 'CK On Call';
    if ((int)$row['DCOnCall'] === 1) $parts[] = 'DC On Call';
    $out[] = [
      'id' => null,
      'title' => '☎ '.implode(' ', $parts),
      'start' => $row['Date'],
      'allDay' => true,
      'backgroundColor' => $onCallColor,
      'eventType' => 'oncall'
    ];
  }
}

/* night locations */
if ($showNight) {
  $sql = "SELECT plan.Date, plan.CKLocation, plan.DCLocation,
                 loc.LocationDesc  AS CKLocationDesc,
                 loc2.LocationDesc AS DCLocationDesc
          FROM DCDailyOpsPlan AS plan
          LEFT JOIN DC_Locations AS loc  ON plan.CKLocation = loc.LocationID
          LEFT JOIN DC_Locations AS loc2 ON plan.DCLocation = loc2.LocationID
          WHERE plan.Date BETWEEN ? AND ?";
  $stmt = $link->prepare($sql);
  $stmt->bind_param('ss', $startDt, $endDt);
  $stmt->execute();
  $res = $stmt->get_result();
  $excluded = [4,6,7,10];
  while ($row = $res->fetch_assoc()) {
    $ckDesc = trim((string)$row['CKLocationDesc']);
    $dcDesc = trim((string)$row['DCLocationDesc']);
    $ckValid = !in_array((int)$row['CKLocation'], $excluded, true) && $ckDesc !== '';
    $dcValid = !in_array((int)$row['DCLocation'], $excluded, true) && $dcDesc !== '';
    if ($ckValid && $dcValid && $ckDesc === $dcDesc) {
      $out[] = [
        'id' => null,
        'title' => $ckDesc,
        'start' => $row['Date'],
        'allDay' => true,
        'backgroundColor' => $dailyOpsColor,
        'eventType' => 'night_location'
      ];
    } else {
      if ($ckValid) {
        $out[] = [
          'id' => null,
          'title' => 'CK: '.$ckDesc,
          'start' => $row['Date'],
          'allDay' => true,
          'backgroundColor' => $dailyOpsColor,
          'eventType' => 'night_location'
        ];
      }
      if ($dcValid) {
        $out[] = [
          'id' => null,
          'title' => 'DC: '.$dcDesc,
          'start' => $row['Date'],
          'allDay' => true,
          'backgroundColor' => $dailyOpsColor,
          'eventType' => 'night_location'
        ];
      }
    }
  }
}

/* work locations */
if ($showWork) {
  $sql = "SELECT plan.Date, plan.CKWorkLocation, plan.DCWorkLocation,
                 w.WLocationDesc  AS CKWorkLocationDesc,
                 w2.WLocationDesc AS DCWorkLocationDesc
          FROM DCDailyOpsPlan AS plan
          LEFT JOIN DC_WorkLocation AS w  ON plan.CKWorkLocation = w.WorkID
          LEFT JOIN DC_WorkLocation AS w2 ON plan.DCWorkLocation = w2.WorkID
          WHERE plan.Date BETWEEN ? AND ?";
  $stmt = $link->prepare($sql);
  $stmt->bind_param('ss', $startDt, $endDt);
  $stmt->execute();
  $res = $stmt->get_result();
  $excluded = [4,6,7];
  while ($row = $res->fetch_assoc()) {
    $ckDesc = trim((string)$row['CKWorkLocationDesc']);
    $dcDesc = trim((string)$row['DCWorkLocationDesc']);
    $ckValid = !in_array((int)$row['CKWorkLocation'], $excluded, true) && $ckDesc !== '';
    $dcValid = !in_array((int)$row['DCWorkLocation'], $excluded, true) && $dcDesc !== '';
    if ($ckValid && $dcValid && $ckDesc === $dcDesc) {
      $out[] = [
        'id' => null,
        'title' => $ckDesc,
        'start' => $row['Date'],
        'allDay' => true,
        'backgroundColor' => $dailyOpsColor,
        'eventType' => 'work_location'
      ];
    } else {
      if ($ckValid) {
        $out[] = [
          'id' => null,
          'title' => 'CK: '.$ckDesc,
          'start' => $row['Date'],
          'allDay' => true,
          'backgroundColor' => $dailyOpsColor,
          'eventType' => 'work_location'
        ];
      }
      if ($dcValid) {
        $out[] = [
          'id' => null,
          'title' => 'DC: '.$dcDesc,
          'start' => $row['Date'],
          'allDay' => true,
          'backgroundColor' => $dailyOpsColor,
          'eventType' => 'work_location'
        ];
      }
    }
  }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
$link->close();
