<?php
session_start();
require 'config.php';
require 'auth.php';

$listID = isset($_GET['list_id']) ? (int)$_GET['list_id'] : 0;

if (!$listID) {
    die('Invalid list ID');
}

try {
    // Fetch list details
    $sqlList = "SELECT ListName, Ingredients, DateForShop, AssignedTo, Completed
                FROM DCLists
                WHERE ListID = ?";
    $stmtList = $link->prepare($sqlList);
    $stmtList->bind_param("i", $listID);
    $stmtList->execute();
    $stmtList->bind_result($listName, $ingredientsJson, $dateForShop, $assignedTo, $completed);
    $stmtList->fetch();
    $stmtList->close();

    $ingredients = json_decode($ingredientsJson, true);

    // Fetch user details
    $sqlUsers = "SELECT UserID, Name, IMGURL FROM DC_Users";
    $resultUsers = $link->query($sqlUsers);
    $users = [];
    while ($row = $resultUsers->fetch_assoc()) {
        $users[$row['UserID']] = $row;
    }

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Update list details
        if (isset($_POST['update_list'])) {
            $assignedTo = $_POST['assigned_to'];
            $dateForShop = $_POST['date_for_shop'];
            $completed = $_POST['completed'];
            $listName = $_POST['list_name'];

            $sqlUpdateList = "UPDATE DCLists SET AssignedTo = ?, DateForShop = ?, Completed = ?, ListName = ? WHERE ListID = ?";
            $stmtUpdateList = $link->prepare($sqlUpdateList);
            $stmtUpdateList->bind_param("isssi", $assignedTo, $dateForShop, $completed, $listName, $listID);
            $stmtUpdateList->execute();
            $stmtUpdateList->close();
        }

        // Add new item
        if (isset($_POST['add_item'])) {
            $newItem = [
                'Name' => $_POST['item_name'],
                'Needed' => 'Yes',
                'Comments' => ''
            ];
            $ingredients[] = $newItem;
        }

        // Delete item
        if (isset($_POST['delete_item'])) {
            $index = $_POST['item_index'];
            array_splice($ingredients, $index, 1);
        }

        // Update item comments
        if (isset($_POST['update_comment'])) {
            $index = $_POST['item_index'];
            $ingredients[$index]['Comments'] = $_POST['comment'];
        }

        // Save updated ingredients
        $ingredientsJson = json_encode($ingredients);
        $sqlUpdateIngredients = "UPDATE DCLists SET Ingredients = ? WHERE ListID = ?";
        $stmtUpdateIngredients = $link->prepare($sqlUpdateIngredients);
        $stmtUpdateIngredients->bind_param("si", $ingredientsJson, $listID);
        $stmtUpdateIngredients->execute();
        $stmtUpdateIngredients->close();

        // Refresh the page to show updated data
        header("Location: view_list.php?list_id=" . $listID);
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
    <title>View List</title>
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
        <h1><?= htmlspecialchars($listName); ?></h1>
        <form method="post">
            <div class="form-group">
                <label for="list_name">List Name</label>
                <input type="text" class="form-control" id="list_name" name="list_name" value="<?= htmlspecialchars($listName); ?>" required>
            </div>
            <div class="form-group">
                <label for="date_for_shop">Date of Shopping List</label>
                <input type="date" class="form-control" id="date_for_shop" name="date_for_shop" value="<?= htmlspecialchars($dateForShop); ?>" required>
            </div>
            <div class="form-group">
                <label for="assigned_to">Assigned To</label>
                <select class="form-control" id="assigned_to" name="assigned_to">
                    <option value="">Not Assigned</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= $user['UserID']; ?>" <?= $assignedTo == $user['UserID'] ? 'selected' : ''; ?>>
                            <?= htmlspecialchars($user['Name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="completed">Status</label>
                <select class="form-control" id="completed" name="completed">
                    <option value="N" <?= $completed == 'N' ? 'selected' : ''; ?>>Not Completed</option>
                    <option value="Y" <?= $completed == 'Y' ? 'selected' : ''; ?>>Completed</option>
                </select>
            </div>
            <button type="submit" name="update_list" class="btn btn-primary">Update List</button>
            <a href="shoppingmode.php?list_id=<?= $listID; ?>" class="btn btn-secondary">Shopping Mode</a>
        </form>

        <h2 class="mt-4">Items</h2>
        <form method="post">
            <div class="form-group">
                <label for="item_name">Add New Item</label>
                <input type="text" class="form-control" id="item_name" name="item_name" required>
                <button type="submit" name="add_item" class="btn btn-success mt-2">Add Item</button>
            </div>
        </form>

        <table class="table table-bordered">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Needed</th>
                    <th>Comments</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($ingredients as $index => $item): ?>
                <tr>
                    <td><?= htmlspecialchars($item['Name']); ?></td>
                    <td><?= htmlspecialchars($item['Needed']); ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="item_index" value="<?= $index; ?>">
                            <input type="text" class="form-control" name="comment" value="<?= htmlspecialchars($item['Comments']); ?>">
                            <button type="submit" name="update_comment" class="btn btn-info mt-1">Update Comment</button>
                        </form>
                    </td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="item_index" value="<?= $index; ?>">
                            <button type="submit" name="delete_item" class="btn btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php include 'footer.php'; ?>

</body>
</html>
