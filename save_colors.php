<?php
session_start();
require 'config.php';

require 'auth.php';


$userID = $_SESSION['userID'];

// Get the submitted color values
$dailyOpsColor = isset($_POST['dailyOpsColor']) ? $_POST['dailyOpsColor'] : '#29af8f'; // Default DailyOps color
$eventColor = isset($_POST['eventColor']) ? $_POST['eventColor'] : '#bb3678';       // Default Event color
$onCallColor = isset($_POST['onCallColor']) ? $_POST['onCallColor'] : '#ff0000';     // Default OnCall color
$dutySheetColor = isset($_POST['dutySheetColor']) ? $_POST['dutySheetColor'] : '#FFD700'; // Default DutySheet color

// Array of color settings
$colorSettings = [
    'DailyOpsColor' => $dailyOpsColor,
    'EventColor' => $eventColor,
    'OnCallColor' => $onCallColor,
    'DutySheetColor' => $dutySheetColor
];

// Prepare SQL statements
$sqlCheck = "SELECT SettingKey FROM UserSettings WHERE UserID = ? AND SettingKey IN ('DailyOpsColor', 'EventColor', 'OnCallColor', 'DutySheetColor')";
$stmtCheck = $link->prepare($sqlCheck);
$stmtCheck->bind_param('i', $userID);
$stmtCheck->execute();
$resultCheck = $stmtCheck->get_result();

$existingSettings = [];
while ($row = $resultCheck->fetch_assoc()) {
    $existingSettings[] = $row['SettingKey'];
}
$stmtCheck->close();

// Loop through each color setting
foreach ($colorSettings as $settingKey => $settingValue) {
    // Validate color value (simple validation for HEX color code)
    if (!preg_match('/^#[a-f0-9]{6}$/i', $settingValue)) {
        // Invalid color code, you can handle this as needed
        continue;
    }

    if (in_array($settingKey, $existingSettings)) {
        // Update existing setting
        $sqlUpdate = "UPDATE UserSettings SET SettingValue = ? WHERE UserID = ? AND SettingKey = ?";
        $stmtUpdate = $link->prepare($sqlUpdate);
        $stmtUpdate->bind_param('sis', $settingValue, $userID, $settingKey);
        $stmtUpdate->execute();
        $stmtUpdate->close();
    } else {
        // Insert new setting
        $sqlInsert = "INSERT INTO UserSettings (UserID, SettingKey, SettingValue) VALUES (?, ?, ?)";
        $stmtInsert = $link->prepare($sqlInsert);
        $stmtInsert->bind_param('iss', $userID, $settingKey, $settingValue);
        $stmtInsert->execute();
        $stmtInsert->close();
    }
}

$link->close();

echo 'success';
