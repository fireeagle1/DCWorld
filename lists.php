<?php
session_start();
require 'config.php';
require 'auth.php';

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$showCompleted = isset($_GET['show_completed']) && $_GET['show_completed'] === 'true';

try {
    // Fetch user details
    $sqlUsers = "SELECT UserID, Name, IMGURL FROM DC_Users";
    $resultUsers = $link->query($sqlUsers);
    $users = [];
    while ($row = $resultUsers->fetch_assoc()) {
        $users[$row['UserID']] = $row;
    }

    // Fetch lists based on completion status
    $completedCondition = $showCompleted ? 'Y' : 'N';
    $sqlLists = "SELECT ListID, ListName, Ingredients, DateForShop, Completed, AssignedTo
                 FROM DCLists
                 WHERE Completed = ?
                 ORDER BY DateForShop ASC";
    $stmtLists = $link->prepare($sqlLists);
    $stmtLists->bind_param("s", $completedCondition);
    $stmtLists->execute();
    $resultLists = $stmtLists->get_result();
    $lists = [];
    while ($row = $resultLists->fetch_assoc()) {
        $ingredients = json_decode($row['Ingredients'], true);
        $totalItems = count($ingredients);
        $notGotYet = count(array_filter($ingredients, fn($item) => $item['Needed'] === 'Yes'));
        $row['TotalItems'] = $totalItems;
        $row['NotGotYet'] = $notGotYet;
        $lists[] = $row;
    }
    $stmtLists->close();

    // Handle form actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $selectedLists = isset($_POST['selected_lists']) ? $_POST['selected_lists'] : [];
        if (empty($selectedLists)) {
            header("Location: lists.php?show_completed=" . $showCompleted);
            exit();
        }

        if (isset($_POST['delete'])) {
            foreach ($selectedLists as $listID) {
                $stmtDelete = $link->prepare("DELETE FROM DCLists WHERE ListID = ?");
                $stmtDelete->bind_param("i", $listID);
                $stmtDelete->execute();
                $stmtDelete->close();
            }
        } elseif (isset($_POST['merge'])) {
            if (count($selectedLists) !== 2) {
                header("Location: lists.php?show_completed=" . $showCompleted);
                exit();
            }
            $listID1 = $selectedLists[0];
            $listID2 = $selectedLists[1];

            // Fetch lists
            $stmtFetch1 = $link->prepare("SELECT Ingredients FROM DCLists WHERE ListID = ?");
            $stmtFetch1->bind_param("i", $listID1);
            $stmtFetch1->execute();
            $stmtFetch1->bind_result($ingredientsJson1);
            $stmtFetch1->fetch();
            $stmtFetch1->close();

            $stmtFetch2 = $link->prepare("SELECT Ingredients FROM DCLists WHERE ListID = ?");
            $stmtFetch2->bind_param("i", $listID2);
            $stmtFetch2->execute();
            $stmtFetch2->bind_result($ingredientsJson2);
            $stmtFetch2->fetch();
            $stmtFetch2->close();

            $ingredients1 = json_decode($ingredientsJson1, true);
            $ingredients2 = json_decode($ingredientsJson2, true);

            // Merge ingredients
            $mergedIngredients = array_merge($ingredients1, $ingredients2);
            $mergedIngredientsJson = json_encode($mergedIngredients);

            // Update list1 with merged ingredients
            $stmtUpdate1 = $link->prepare("UPDATE DCLists SET Ingredients = ? WHERE ListID = ?");
            $stmtUpdate1->bind_param("si", $mergedIngredientsJson, $listID1);
            $stmtUpdate1->execute();
            $stmtUpdate1->close();

            // Delete list2
            $stmtDelete = $link->prepare("DELETE FROM DCLists WHERE ListID = ?");
            $stmtDelete->bind_param("i", $listID2);
            $stmtDelete->execute();
            $stmtDelete->close();

            header("Location: view_list.php?list_id=" . $listID1);
            exit();
        } elseif (isset($_POST['duplicate'])) {
            foreach ($selectedLists as $listID) {
                $stmtFetch = $link->prepare("SELECT ListName, Ingredients, DateForShop, AssignedTo, Completed FROM DCLists WHERE ListID = ?");
                $stmtFetch->bind_param("i", $listID);
                $stmtFetch->execute();
                $stmtFetch->bind_result($listName, $ingredients, $dateForShop, $assignedTo, $completed);
                $stmtFetch->fetch();
                $stmtFetch->close();

                $newListName = $listName . " (copy)";
                $stmtDuplicate = $link->prepare("INSERT INTO DCLists (ListName, Ingredients, DateForShop, AssignedTo, Completed) VALUES (?, ?, ?, ?, ?)");
                $stmtDuplicate->bind_param("sssis", $newListName, $ingredients, $dateForShop, $assignedTo, $completed);
                $stmtDuplicate->execute();
                $newListID = $stmtDuplicate->insert_id;
                $stmtDuplicate->close();

                header("Location: view_list.php?list_id=" . $newListID);
                exit();
            }
        }

        header("Location: lists.php?show_completed=" . $showCompleted);
        exit();
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
    <title>All Lists</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .user-img {
            width: 30px;
            height: 30px;
            border-radius: 50%;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="container mt-4">
        <h1>All Lists</h1>
        <a href="?show_completed=<?= $showCompleted ? 'false' : 'true'; ?>" class="btn btn-secondary mb-3">
            <?= $showCompleted ? 'Show Not Completed Lists' : 'Show Completed Lists'; ?>
        </a>
        <form method="post">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Select</th>
                        <th>List Name</th>
                        <th>Number of Items (Not Got Yet)</th>
                        <th>Date of Shopping List</th>
                        <th>Assigned To</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lists as $list): ?>
                    <tr>
                        <td><input type="checkbox" name="selected_lists[]" value="<?= $list['ListID']; ?>"></td>
                        <td><?= htmlspecialchars($list['ListName']); ?></td>
                        <td><?= $list['TotalItems']; ?> (<?= $list['NotGotYet']; ?>)</td>
                        <td><?= date('l, jS F Y', strtotime($list['DateForShop'])); ?></td>
                        <td>
                            <?php if ($list['AssignedTo'] && isset($users[$list['AssignedTo']])): ?>
                                <img src="<?= htmlspecialchars($users[$list['AssignedTo']]['IMGURL']); ?>" alt="<?= htmlspecialchars($users[$list['AssignedTo']]['Name']); ?>" class="user-img">
                                <?= htmlspecialchars($users[$list['AssignedTo']]['Name']); ?>
                            <?php else: ?>
                                Not Assigned
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="view_list.php?list_id=<?= $list['ListID']; ?>" class="btn btn-primary">View and Edit</a>
                            <a href="shoppingmode.php?list_id=<?= $list['ListID']; ?>" class="btn btn-secondary">Shopping Mode</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="btn-group">
                <button type="submit" name="delete" class="btn btn-danger">Delete</button>
                <button type="submit" name="merge" class="btn btn-warning">Merge</button>
                <button type="submit" name="duplicate" class="btn btn-info">Duplicate</button>
            </div>
        </form>
    </div>
    
     <?php include 'footer.php'; ?>

</body>
</html>
