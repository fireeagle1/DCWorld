<?php
session_start();
require 'config.php';
require 'auth.php';


// Fetch the logged-in user's information
$userID = $_SESSION['userID'];
$sqlUser = "SELECT Name FROM DC_Users WHERE UserID = ?";
$stmtUser = $link->prepare($sqlUser);
$stmtUser->bind_param("i", $userID);
$stmtUser->execute();
$stmtUser->bind_result($Name);
$stmtUser->fetch();
$stmtUser->close();

// Get the 'week' parameter from URL, default to 0
$weekOffset = isset($_GET['week']) ? intval($_GET['week']) : 0;

// Calculate the date range based on the week offset
$today = new DateTime();
if ($weekOffset !== 0) {
    $today->modify('+' . ($weekOffset * 7) . ' days');
}
$nextWeek = clone $today;
$nextWeek->modify('+6 days');
$formattedToday = $today->format('Y-m-d');
$formattedNextWeek = $nextWeek->format('Y-m-d');

// Prepare display dates for header
$displayStartDate = $today->format('jS F Y');
$displayEndDate = $nextWeek->format('jS F Y');

$sqlOps = "
    SELECT Date, work.WLocationDesc AS CKWorkDayLocation, work.WLocation_Icon AS CKWorkDayIcon,
           loc2.LocationDesc AS DCDayLocation, loc2.Location_Icon AS DCDayIcon,
           loc.LocationDesc AS CKDayLocation, loc.Location_Icon AS CKDayIcon,
           work2.WLocationDesc AS DCWorkDayLocation, work2.WLocation_Icon AS DCWorkDayIcon,
           Notes, MealID, CKOnCall, DCOnCall
    FROM DCDailyOpsPlan AS plan
    LEFT JOIN DC_WorkLocation AS work ON plan.CKWorkLocation = work.WorkID
    LEFT JOIN DC_Locations AS loc ON plan.CKLocation = loc.LocationID
    LEFT JOIN DC_Locations AS loc2 ON plan.DCLocation = loc2.LocationID
    LEFT JOIN DC_WorkLocation AS work2 ON plan.DCWorkLocation = work2.WorkID
    WHERE Date BETWEEN ? AND ?
    ORDER BY plan.Date ASC
";

$stmtOps = $link->prepare($sqlOps);
$stmtOps->bind_param("ss", $formattedToday, $formattedNextWeek);
$stmtOps->execute();
$stmtOps->store_result();
$stmtOps->bind_result($Date, $CKWorkDayLocation, $CKWorkDayIcon, $DCDayLocation, $DCDayIcon, $CKDayLocation, $CKDayIcon, $DCWorkDayLocation, $DCWorkDayIcon, $Notes, $MealID, $CKOnCall, $DCOnCall);

$dailyOps = [];
$meals = []; // To store meal descriptions
while ($stmtOps->fetch()) {
    // Fetch meal short description for each MealID
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
        'MealID' => $MealID,
        'CKOnCall' => $CKOnCall,
        'DCOnCall' => $DCOnCall
    ];
}
$stmtOps->close();

$link->close();
?>

<?php include 'header.php'; ?>

<style>
    .card-header {
        background-color: #007ea7;
        color: #fff;
    }

    .table th,
    .table td {
        vertical-align: middle;
    }

    .table th {
        background-color: #003459;
        color: #fff;
    }

    .table-sm th,
    .table-sm td {
        font-size: 14px;
        padding: 0.75rem;
    }

    .table-sm td {
        vertical-align: top;
    }

    .icon-img {
        width: 20px;
        height: 20px;
        margin-right: 5px;
    }

    .day-header {
        background-color: #005b96;
        color: #fff;
        text-align: center;
        padding: 10px;
        margin-bottom: 10px;
        border-radius: 5px;
        position: relative;
    }

    .notes-cell {
        background-color: #f7f7f7;
        position: relative;
    }

    .on-call-phone {
        width: 18px;
        height: 18px;
    }

    .on-call-phone-notes {
        width: 12px;
        height: 12px;
    }

    .on-call-section {
        position: absolute;
        top: 40px;
        left: 0;
        right: 0;
        display: flex;
        justify-content: center;
        align-items: center;
    }

    .on-call-notes {
        font-size: 0.85rem;
        color: red;
        display: inline-block;
        margin-left: 5px;
    }

    /* This makes the table scroll horizontally on small devices */
    @media (max-width: 768px) {
        .table-responsive {
            overflow-x: auto;
        }

        .d-sm-none {
            display: none !important;
        }
    }
</style>

<div class="container mt-4">
    <h1>Operations Plan</h1>
    
    <div class="row">
        <div class="container-fluid">
            <div class="card mb-3">
                <div class="card-header">Weekly Plan <?= htmlspecialchars($displayStartDate); ?> to <?= htmlspecialchars($displayEndDate); ?></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Charlie Day</th>
                                    <th>Charlie Night</th>
                                    <th>Dan Day</th>
                                    <th>Dan Night</th>
                                    <th>Notes</th>
                                    <th>Meal</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dailyOps as $op): ?>
                                <tr>
                                    <td class="day-header">
                                        <?= date('l jS F Y', strtotime($op['Date'])); ?>
                                        
                                    </td>
                                    <td>
                                        <?php if ($op['CKWorkDayIcon']): ?>
                                            <img src="<?= htmlspecialchars($op['CKWorkDayIcon']); ?>" alt="Icon" class="icon-img">
                                        <?php endif; ?>
                                        <?= htmlspecialchars($op['CKWorkDayLocation']); ?>
                                    </td>
                                    <td>
                                        <?php if ($op['CKDayIcon']): ?>
                                            <img src="<?= htmlspecialchars($op['CKDayIcon']); ?>" alt="Icon" class="icon-img">
                                        <?php endif; ?>
                                        <?= htmlspecialchars($op['CKDayLocation']); ?>
                                    </td>
                                    <td>
                                        <?php if ($op['DCWorkDayIcon']): ?>
                                            <img src="<?= htmlspecialchars($op['DCWorkDayIcon']); ?>" alt="Icon" class="icon-img">
                                        <?php endif; ?>
                                        <?= htmlspecialchars($op['DCWorkDayLocation']); ?>
                                    </td>
                                    <td>
                                        <?php if ($op['DCDayIcon']): ?>
                                            <img src="<?= htmlspecialchars($op['DCDayIcon']); ?>" alt="Icon" class="icon-img">
                                        <?php endif; ?>
                                        <?= htmlspecialchars($op['DCDayLocation']); ?>
                                    </td>
                                    <td class="notes-cell">
                                        <?= nl2br(htmlspecialchars($op['Notes'])); ?>
                                        <div class="on-call-notes">
                                            <?php if ($op['CKOnCall']): ?>
                                                <img src="https://assets.dcworld.uk/images/RedPhone.png" alt="On Call" class="on-call-phone-notes"> CK
                                            <?php endif; ?>
                                            <?php if ($op['DCOnCall']): ?>
                                                <img src="https://assets.dcworld.uk/images/RedPhone.png" alt="On Call" class="on-call-phone-notes"> DC
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($op['MealID']): ?>
                                            <a href="view_meal.php?mealId=<?= $op['MealID']; ?>" class="meal-link"><?= htmlspecialchars($op['MealShortName']); ?></a>
                                        <?php else: ?>
                                            <?= htmlspecialchars($op['MealShortName']); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if (empty($dailyOps)): ?>
                            <p>No operations plan found for this week.</p>
                        <?php endif; ?>
                    </div>
                    <!-- Navigation Buttons -->
                    <div class="d-flex justify-content-between mt-3">
                        <?php if ($weekOffset > 0): ?>
                            <a href="?week=<?= $weekOffset - 1; ?>" class="btn btn-primary">&laquo; Previous 7 Days</a>
                        <?php else: ?>
                            <span></span>
                        <?php endif; ?>
                        <a href="?week=<?= $weekOffset + 1; ?>" class="btn btn-primary">Next 7 Days &raquo;</a>
                    </div>
                    <!-- End of Navigation Buttons -->
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>

</body>
</html>
