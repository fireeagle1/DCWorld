<?php
session_start();
require 'config.php';

require 'auth.php';

$userID = $_SESSION['userID'];

// Expected settings
$settingsKeys = ['ShowWorkLocation', 'ShowNightLocation', 'ShowEvents', 'ShowOnCall', 'ShowDutySheet'];

foreach ($settingsKeys as $key) {
    if (isset($_POST[$key])) {
        $value = $_POST[$key] ? '1' : '0';

        // Check if setting already exists
        $sqlCheck = "SELECT * FROM UserSettings WHERE UserID = ? AND SettingKey = ?";
        $stmtCheck = $link->prepare($sqlCheck);
        $stmtCheck->bind_param('is', $userID, $key);
        $stmtCheck->execute();
        $resultCheck = $stmtCheck->get_result();

        if ($resultCheck->num_rows > 0) {
            // Update existing setting
            $sqlUpdate = "UPDATE UserSettings SET SettingValue = ? WHERE UserID = ? AND SettingKey = ?";
            $stmtUpdate = $link->prepare($sqlUpdate);
            $stmtUpdate->bind_param('sis', $value, $userID, $key);
            $stmtUpdate->execute();
            $stmtUpdate->close();
        } else {
            // Insert new setting
            $sqlInsert = "INSERT INTO UserSettings (UserID, SettingKey, SettingValue) VALUES (?, ?, ?)";
            $stmtInsert = $link->prepare($sqlInsert);
            $stmtInsert->bind_param('iss', $userID, $key, $value);
            $stmtInsert->execute();
            $stmtInsert->close();
        }

        $stmtCheck->close();
    }
}

$link->close();
echo 'success';
?>
