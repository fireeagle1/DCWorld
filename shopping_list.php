<?php
session_start();
require 'config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
   require 'auth.php';


    $userID = $_SESSION['userID'];
    $sqlUser = "SELECT Name FROM DC_Users WHERE UserID = ?";
    $stmtUser = $link->prepare($sqlUser);
    $stmtUser->bind_param("i", $userID);
    $stmtUser->execute();
    $stmtUser->bind_result($Name);
    $stmtUser->fetch();
    $stmtUser->close();

    // Get distinct week commencing dates from DCDailyOpsPlan
    $sqlWeeks = "SELECT DISTINCT DATE_FORMAT(Date, '%x-%v') AS YearWeek, MIN(Date) AS WeekCommencing
                 FROM DCDailyOpsPlan
                 GROUP BY YearWeek
                 ORDER BY WeekCommencing DESC";
    $resultWeeks = $link->query($sqlWeeks);
    $weeks = [];
    while ($row = $resultWeeks->fetch_assoc()) {
        $weeks[] = $row;
    }

    // Get the current week in the format used in the dropdown
    $currentWeek = (new DateTime())->format('Y-W');

    // Default to the current week if no week is selected
    $selectedWeek = $_GET['week'] ?? $currentWeek;

    // Fetch ingredients if a week is selected
    $ingredients = [];
    $mealIngredients = [];
    if ($selectedWeek) {
        $sqlOps = "SELECT MealID FROM DCDailyOpsPlan WHERE DATE_FORMAT(Date, '%x-%v') = ?";
        $stmtOps = $link->prepare($sqlOps);
        $stmtOps->bind_param("s", $selectedWeek);
        $stmtOps->execute();
        $stmtOps->bind_result($MealID);
        $mealIDs = [];
        while ($stmtOps->fetch()) {
            $mealIDs[] = $MealID;
        }
        $stmtOps->close();

        if (!empty($mealIDs)) {
            $mealIDs = implode(',', array_map('intval', $mealIDs));
            $sqlMeals = "SELECT MealID, Ingredients, MealShortName FROM DCMeals WHERE MealID IN ($mealIDs)";
            $resultMeals = $link->query($sqlMeals);
            while ($row = $resultMeals->fetch_assoc()) {
                $mealIngredients[$row['MealShortName']] = json_decode($row['Ingredients'], true);
                if (is_array($mealIngredients[$row['MealShortName']])) {
                    foreach ($mealIngredients[$row['MealShortName']] as $ingredient) {
                        if (isset($ingredients[$ingredient])) {
                            $ingredients[$ingredient]++;
                        } else {
                            $ingredients[$ingredient] = 1;
                        }
                    }
                }
            }
        }
    }

    // Handle "Create Shopping List" button click
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_list'])) {
        $ingredientsList = [];
        foreach ($ingredients as $ingredient => $quantity) {
            $ingredientsList[] = [
                'Name' => $ingredient,
                'Needed' => 'Yes',
                'Comments' => ''
            ];
        }
        $ingredientsJson = json_encode($ingredientsList);
        $dateForShop = date('Y-m-d'); // You can adjust this as needed
        $weekCommencingDate = $weeks[array_search($selectedWeek, array_column($weeks, 'YearWeek'))]['WeekCommencing'];
        $listName = "Week Commencing " . date('l, jS F Y', strtotime($weekCommencingDate)) . " Shopping List";

        $sqlInsertList = "INSERT INTO DCLists (ListName, ListType, Ingredients, DateForShop) VALUES (?, 'Shopping', ?, ?)";
        $stmtInsertList = $link->prepare($sqlInsertList);
        $stmtInsertList->bind_param('sss', $listName, $ingredientsJson, $dateForShop);
        $stmtInsertList->execute();
        $stmtInsertList->close();

        echo "<div class='alert alert-success'>Shopping list created successfully!</div>";
    }

    $link->close();
} catch (mysqli_sql_exception $e) {
    die('Database error: ' . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shopping List</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .hidden { display: none; }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="container mt-4">
        <h1>Welcome, <?= htmlspecialchars($Name); ?></h1>
        <h2>Select a week to view the shopping list</h2>
        <div class="form-group">
            <label for="weekSelect">Week Commencing:</label>
            <select id="weekSelect" class="form-control">
                <?php foreach ($weeks as $week): ?>
                <option value="<?= htmlspecialchars($week['YearWeek']); ?>" <?= $week['YearWeek'] == $selectedWeek ? 'selected' : ''; ?>>
                    Week Commencing <?= date('l, jS F Y', strtotime($week['WeekCommencing'])); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="btn-group mb-3" role="group" aria-label="View options">
            <button type="button" class="btn btn-secondary" id="groupByMealBtn">Group by Meal</button>
            <button type="button" class="btn btn-secondary" id="sortAZBtn">Sort A-Z</button>
        </div>

        <form method="post" id="shoppingListForm">
            <button type="submit" name="create_list" class="btn btn-primary mb-3">Create Shopping List</button>
        </form>

        <div id="groupByMealView" class="hidden">
            <h2>Ingredients Grouped by Meal for Week Commencing <?= date('l, jS F Y', strtotime($weeks[array_search($selectedWeek, array_column($weeks, 'YearWeek'))]['WeekCommencing'])); ?></h2>
            <?php foreach ($mealIngredients as $meal => $ingredientsList): ?>
            <h3><?= htmlspecialchars($meal); ?></h3>
            <ul class="list-group">
                <?php foreach ($ingredientsList as $ingredient): ?>
                <li class="list-group-item"><?= htmlspecialchars($ingredient); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endforeach; ?>
        </div>

        <div id="sortAZView">
            <h2>Ingredients Sorted A-Z for Week Commencing <?= date('l, jS F Y', strtotime($weeks[array_search($selectedWeek, array_column($weeks, 'YearWeek'))]['WeekCommencing'])); ?></h2>
            <ul class="list-group">
                <?php 
                ksort($ingredients);
                foreach ($ingredients as $ingredient => $quantity): ?>
                <li class="list-group-item"><?= htmlspecialchars($ingredient); ?> (<?= $quantity; ?>)</li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php include 'footer.php'; ?>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Handle week selection change
        document.getElementById('weekSelect').addEventListener('change', function() {
            window.location.href = '?week=' + this.value;
        });

        // Handle view change buttons
        document.getElementById('groupByMealBtn').addEventListener('click', function() {
            document.getElementById('groupByMealView').classList.remove('hidden');
            document.getElementById('sortAZView').classList.add('hidden');
        });

        document.getElementById('sortAZBtn').addEventListener('click', function() {
            document.getElementById('groupByMealView').classList.add('hidden');
            document.getElementById('sortAZView').classList.remove('hidden');
        });

        // Set default view
        document.getElementById('sortAZView').classList.remove('hidden');
    });
    </script>
</body>
</html>
