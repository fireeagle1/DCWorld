<?php
require 'config.php';
require 'auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dayID = $_POST['dayID'];
    $field = $_POST['field'];
    $value = $_POST['value'];

    $sqlUpdate = "UPDATE DCDailyOpsPlan SET $field = ? WHERE DayID = ?";
    $stmtUpdate = $link->prepare($sqlUpdate);
    $stmtUpdate->bind_param("si", $value, $dayID);

    if ($stmtUpdate->execute()) {
        echo 'success';
    } else {
        echo 'error';
    }

    $stmtUpdate->close();
    $link->close();
}
?>
