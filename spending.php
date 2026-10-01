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

// Fetch all spending data
$sqlSpending = "SELECT Spen_TransID, DateTime, Value, Category, Vendor, Comment
                FROM DCSpending
                ORDER BY DateTime DESC";
$resultSpending = $link->query($sqlSpending);
$spending = [];
$totalCost = 0;
while ($row = $resultSpending->fetch_assoc()) {
    $spending[] = $row;
    $totalCost += $row['Value'];
}

$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Spending Overview</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
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
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container mt-4">
        <h1>Spending Overview</h1>
        <div class="alert alert-info">
            <strong>Total Spending:</strong> <?php echo number_format($totalCost, 2); ?>
        </div>
        <div class="card mb-3">
            <div class="card-header">All Transactions</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Amount</th>
                                <th>Category</th>
                                <th>Vendor</th>
                                <th>Comment</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($spending as $spend): ?>
                            <tr>
                                <td><?php echo date('l jS F Y', strtotime($spend['DateTime'])); ?></td>
                                <td><?php echo htmlspecialchars($spend['Value']); ?></td>
                                <td><?php echo htmlspecialchars($spend['Category']); ?></td>
                                <td><?php echo htmlspecialchars($spend['Vendor']); ?></td>
                                <td><?php echo htmlspecialchars($spend['Comment']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (empty($spending)): ?>
                        <p>No spending records found.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php include 'footer.php'; ?>

</body>
</html>
