<?php
require 'config.php';

session_start();

require 'auth.php';


$currentUserID = $_SESSION['userID'];

// Fetch the current week's plan
$today = new DateTime();
$startOfWeek = clone $today;
$startOfWeek->modify('monday this week');
$endOfWeek = clone $startOfWeek;
$endOfWeek->modify('+6 days');

$formattedStartOfWeek = $startOfWeek->format('Y-m-d');
$formattedEndOfWeek = $endOfWeek->format('Y-m-d');

$sqlOps = "
    SELECT Date, work.WLocationDesc AS CKWorkDayLocation, work.WLocation_Icon AS CKWorkDayIcon,
           loc2.LocationDesc AS DCDayLocation, loc2.Location_Icon AS DCDayIcon,
           loc.LocationDesc AS CKDayLocation, loc.Location_Icon AS CKDayIcon,
           work2.WLocationDesc AS DCWorkDayLocation, work2.WLocation_Icon AS DCWorkDayIcon,
           Notes, MealID
    FROM DCDailyOpsPlan AS plan
    LEFT JOIN DC_WorkLocation AS work ON plan.CKWorkLocation = work.WorkID
    LEFT JOIN DC_Locations AS loc ON plan.CKLocation = loc.LocationID
    LEFT JOIN DC_Locations AS loc2 ON plan.DCLocation = loc2.LocationID
    LEFT JOIN DC_WorkLocation AS work2 ON plan.DCWorkLocation = work2.WorkID
    WHERE Date BETWEEN ? AND ?
    ORDER BY plan.Date ASC
";
$stmtOps = $link->prepare($sqlOps);
$stmtOps->bind_param("ss", $formattedStartOfWeek, $formattedEndOfWeek);
$stmtOps->execute();
$stmtOps->store_result();
$stmtOps->bind_result($Date, $CKWorkDayLocation, $CKWorkDayIcon, $DCDayLocation, $DCDayIcon, $CKDayLocation, $CKDayIcon, $DCWorkDayLocation, $DCWorkDayIcon, $Notes, $MealID);

$dailyOps = [];
$meals = [];
while ($stmtOps->fetch()) {
    if (!isset($meals[$MealID]) && $MealID) {
        $sqlMeal = "SELECT MealShortName FROM DCMeals WHERE MealID = ?";
        $stmtMeal = $link->prepare($sqlMeal);
        $stmtMeal->bind_param("i", $MealID);
        $stmtMeal->execute();
        $stmtMeal->bind_result($MealShortName);
        $stmtMeal->fetch();
        $meals[$MealID] = $MealShortName;
        $stmtMeal->close();
    }

    $dailyOps[] = [
        'Date' => $Date,
        'CKDayLocation' => $CKDayLocation,
        'CKDayIcon' => $CKDayIcon,
        'DCDayLocation' => $DCDayLocation,
        'DCDayIcon' => $DCDayIcon,
        'CKWorkDayLocation' => $CKWorkDayLocation,
        'CKWorkDayIcon' => $CKWorkDayIcon,
        'DCWorkDayLocation' => $DCWorkDayLocation,
        'DCWorkDayIcon' => $DCWorkDayIcon,
        'Notes' => $Notes,
        'MealShortName' => $meals[$MealID] ?? 'No meal assigned',
        'MealID' => $MealID
    ];
}
$stmtOps->close();

// Generate the email content
$emailContent = "<h2>Weekly Plan from {$startOfWeek->format('d-m-Y')} to {$endOfWeek->format('d-m-Y')}</h2>";
$emailContent .= "<table border='1' cellpadding='5' cellspacing='0'>";
$emailContent .= "<tr><th>Date</th><th>Charlie Day Location</th><th>Dan Day Location</th><th>Charlie Night Location</th><th>Dan Night Location</th><th>Notes</th><th>Meal</th></tr>";

foreach ($dailyOps as $op) {
    $emailContent .= "<tr>";
    $emailContent .= "<td>" . date('l jS F Y', strtotime($op['Date'])) . "</td>";
    $emailContent .= "<td>" . htmlspecialchars($op['CKWorkDayLocation']) . "</td>";
    $emailContent .= "<td>" . htmlspecialchars($op['DCWorkDayLocation']) . "</td>";
    $emailContent .= "<td>" . htmlspecialchars($op['CKDayLocation']) . "</td>";
    $emailContent .= "<td>" . htmlspecialchars($op['DCDayLocation']) . "</td>";
    $emailContent .= "<td>" . htmlspecialchars($op['Notes']) . "</td>";
    $emailContent .= "<td><a href='view_meal.php?mealId={$op['MealID']}'>" . htmlspecialchars($op['MealShortName']) . "</a></td>";
    $emailContent .= "</tr>";
}

$emailContent .= "</table>";

$recipients = [
    'cromptondaniel234@gmail.com',
    'charlie@ckenterprises.co.uk'
];

foreach ($recipients as $to) {
    $sqlEmail = "INSERT INTO DCEmailsLog (`to`, Subject, Content, Sent) VALUES (?, 'Weekly Plan', ?, 'No')";
    $stmtEmail = $link->prepare($sqlEmail);
    $stmtEmail->bind_param("ss", $to, $emailContent);
    $stmtEmail->execute();
    $stmtEmail->close();
}

$link->close();

header("Location: weeklyplan.php?success=emails_sent");
exit();
?>
