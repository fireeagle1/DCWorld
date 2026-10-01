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

// Fetch all bank accounts for the user
$sqlAccounts = "SELECT AccountID, Balance, ShortDesc FROM DCBankAccounts";
$resultAccounts = $link->query($sqlAccounts);
$savingAccounts = [];
$totalBalance = 0;
$balanceWithoutBonus = 0;
$helpToBuyBonus = 0;  // Track total Help to Buy ISA bonus
$target = 30000;      // Savings target

while ($row = $resultAccounts->fetch_assoc()) {
    $bonus = 0;
    $balance = $row['Balance'];

    // Calculate bonus only for 'Help to Buy' accounts
    if (stripos($row['ShortDesc'], 'Help to Buy') !== false) {
        $bonus = $balance * 0.25;  // 25% bonus for Help to Buy accounts
        $helpToBuyBonus += $bonus; // Add bonus to total Help to Buy bonus
    }

    // Calculate total balance (with and without bonus)
    $balanceWithoutBonus += $balance;        // Balance without any bonus
    $totalBalance += $balance + $bonus;      // Total balance with bonus if applicable

    $savingAccounts[] = $row;
}

$resultAccounts->close();
$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Operation House buy</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f8f9fa;
        }
        .card-header {
            background-color: #007ea7;
            color: #fff;
            font-weight: bold;
        }
        .thermometer-container {
            position: relative;
            height: 400px;
            width: 80px; /* Thinner thermometer */
            margin: 0 auto;
            background-color: #e0e0e0;
            border-radius: 25px;
            overflow: hidden;
        }
        .thermometer-inner {
            position: absolute;
            bottom: 0;
            width: 100%;
            background-color: #29af8f;
            border-radius: 25px;
            transition: height 0.5s ease-in-out;
        }
        .thermometer-scale {
            position: absolute;
            top: 0;
            left: -50px;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: flex-end;
            font-size: 0.9rem;
        }
        .goal-text {
            text-align: center;
            font-size: 1.5rem;
            margin-top: 10px;
        }
        .table-modern {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .table-modern th {
            background-color: #007ea7;
            color: white;
            font-weight: bold;
        }
        .status-dot {
            width: 15px;
            height: 15px;
            border-radius: 50%;
            display: inline-block;
        }
        .status-green { background-color: #28a745; }
        .status-amber { background-color: #ffc107; }
        .status-grey { background-color: #6c757d; }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container mt-4">
        <h1>Welcome, <?= htmlspecialchars($Name) ?></h1>

        <div class="row">
            <!-- Left column: To-Do List -->
            <div class="col-md-6">
                <div class="card mb-3 table-modern">
                    <div class="card-header">To-Do List</div>
                    <div class="card-body">
                        <table class="table table-bordered">
                            <thead>
                                <tr>
                                    <th>Task</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>Deposit Saved</td>
                                    <td><span class="status-dot status-green"></span> Done</td>
                                </tr>
                                <tr>
                                    <td>Start Mortgage Research</td>
                                    <td><span class="status-dot status-green"></span> Done</td>
                                </tr>
                                <tr>
                                    <td>Mortgage Advisor</td>
                                    <td><span class="status-dot status-green"></span> Done</td>
                                </tr>
                                <tr>
                                    <td>Look for a Solicitor</td>
                                    <td><span class="status-dot status-amber"></span> Not Started</td>
                                </tr>
                                <tr>
                                    <td>View Potential Properties</td>
                                    <td><span class="status-dot status-amber"></span> In Progress</td>
                                </tr>
                                <tr>
                                    <td>Organise Moving Logistics</td>
                                    <td><span class="status-dot status-grey"></span> Not Applicable</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Right column: Savings Thermometer -->
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header">Savings Progress</div>
                    <div class="card-body">
                        <div class="thermometer-container">
                            <div class="thermometer-scale">
                                <div>£50k</div>
                                <div>£40k</div>
                                <div>£30k</div>
                                <div>£20k</div>
                                <div>£10k</div>
                                <div>£0</div>
                            </div>
                            <div class="thermometer-inner" id="thermometer-inner" style="height: 0;"></div>
                        </div>
                        <div class="goal-text">Goal: £50,000</div>
                        <div class="thermometer-label" id="thermometer-label"></div>
                        <button class="btn btn-primary mt-3" onclick="toggleThermometer()">Toggle With/Without Help to Buy Bonus</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let withHelpToBuy = false; // Default without Help-to-Buy Bonus

        function updateThermometer() {
            const totalBalance = <?= json_encode($totalBalance); ?>;
            const balanceWithoutBonus = <?= json_encode($balanceWithoutBonus); ?>;
            const target = 50000; // Updated target
            const thermometerInner = document.getElementById('thermometer-inner');
            const thermometerLabel = document.getElementById('thermometer-label');
            
            let currentBalance = withHelpToBuy ? totalBalance : balanceWithoutBonus;
            let percentage = (currentBalance / target) * 100;

            if (percentage > 100) percentage = 100; // Cap at 100%

            thermometerInner.style.height = percentage + '%';
            thermometerLabel.textContent = "Current Savings: £" + currentBalance.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }

        function toggleThermometer() {
            withHelpToBuy = !withHelpToBuy;
            updateThermometer();
        }

        updateThermometer(); // Initialize thermometer
    </script>

    <?php include 'footer.php'; ?>
</body>
</html>
