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

    // Sort ingredients alphabetically ignoring numbers
    usort($ingredients, function ($a, $b) {
        return preg_replace('/\d/', '', $a['Name']) <=> preg_replace('/\d/', '', $b['Name']);
    });

    // Handle form submission for ticking off items
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['toggle_needed'])) {
            $index = $_POST['item_index'];
            $ingredients[$index]['Needed'] = $ingredients[$index]['Needed'] === 'Yes' ? 'No' : 'Yes';
        } elseif (isset($_POST['complete_shopping'])) {
            $amount = $_POST['amount'];
            $vendor = $_POST['vendor'];

            // Update list to completed
            $sqlUpdateList = "UPDATE DCLists SET Completed = 'Y' WHERE ListID = ?";
            $stmtUpdateList = $link->prepare($sqlUpdateList);
            $stmtUpdateList->bind_param("i", $listID);
            $stmtUpdateList->execute();
            $stmtUpdateList->close();

            // Insert into DCSpending
            $userID = $_SESSION['userID'];
            $dateTime = date('Y-m-d H:i:s');
            $category = 'Shopping';
            $comment = $listName;

            $sqlInsertSpending = "INSERT INTO DCSpending (DateTime, UserID, Category, Vendor, Comment, Value)
                                  VALUES (?, ?, ?, ?, ?, ?)";
            $stmtInsertSpending = $link->prepare($sqlInsertSpending);
            $stmtInsertSpending->bind_param("sisssd", $dateTime, $userID, $category, $vendor, $comment, $amount);
            $stmtInsertSpending->execute();
            $stmtInsertSpending->close();

            // Redirect to avoid resubmission
            header("Location: shoppingmode.php?list_id=" . $listID);
            exit();
        }

        // Save updated ingredients
        $ingredientsJson = json_encode($ingredients);
        $sqlUpdateIngredients = "UPDATE DCLists SET Ingredients = ? WHERE ListID = ?";
        $stmtUpdateIngredients = $link->prepare($sqlUpdateIngredients);
        $stmtUpdateIngredients->bind_param("si", $ingredientsJson, $listID);
        $stmtUpdateIngredients->execute();
        $stmtUpdateIngredients->close();

        // Refresh the page to show updated data
        header("Location: shoppingmode.php?list_id=" . $listID);
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
    <title>Shopping Mode</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .item-got { background-color: lightgreen; }
        .item-needed { background-color: lightcoral; }
    </style>
    <script>
    function completeShopping() {
        const amount = prompt("Enter the cost of the shop:");
        if (amount === null) return; // Cancel was pressed

        const vendor = prompt("Enter the vendor:");
        if (vendor === null) return; // Cancel was pressed

        const form = document.createElement('form');
        form.method = 'post';
        form.action = '';

        const amountInput = document.createElement('input');
        amountInput.type = 'hidden';
        amountInput.name = 'amount';
        amountInput.value = amount;
        form.appendChild(amountInput);

        const vendorInput = document.createElement('input');
        vendorInput.type = 'hidden';
        vendorInput.name = 'vendor';
        vendorInput.value = vendor;
        form.appendChild(vendorInput);

        const completeInput = document.createElement('input');
        completeInput.type = 'hidden';
        completeInput.name = 'complete_shopping';
        completeInput.value = '1';
        form.appendChild(completeInput);

        document.body.appendChild(form);
        form.submit();
    }
    </script>
</head>
<body>
    <?php include 'header.php'; ?>
    <div class="container mt-4">
        <h1>Shopping Mode for <?= htmlspecialchars($listName); ?></h1>
        <p>Date of Shopping List: <?= date('l, jS F Y', strtotime($dateForShop)); ?></p>
        <button onclick="completeShopping()" class="btn btn-success mb-3">Complete Shopping</button>
        
        <h2>Items</h2>
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
                <tr class="<?= $item['Needed'] === 'Yes' ? 'item-needed' : 'item-got'; ?>">
                    <td><?= htmlspecialchars($item['Name']); ?></td>
                    <td><?= htmlspecialchars($item['Needed']); ?></td>
                    <td><?= htmlspecialchars($item['Comments']); ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="item_index" value="<?= $index; ?>">
                            <button type="submit" name="toggle_needed" class="btn btn-primary">
                                <?= $item['Needed'] === 'Yes' ? 'Tick Off' : 'Mark Needed'; ?>
                            </button>
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
