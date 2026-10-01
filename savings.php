<?php
session_start();
require 'config.php';
require 'auth.php';

// Fetch all bank accounts and user names
$sqlAccounts = "
    SELECT 
        DCBankAccounts.AccountID, 
        DCBankAccounts.ShortDesc, 
        DCBankAccounts.LongDesc, 
        DCBankAccounts.Balance, 
        DC_Users.Name AS UserName
    FROM DCBankAccounts
    JOIN DC_Users ON DCBankAccounts.UserID = DC_Users.UserID
";
$result = $link->query($sqlAccounts);
$accounts = [];
while ($row = $result->fetch_assoc()) {
    $accounts[] = $row;
}

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
    <title>Manage Savings</title>
    <!-- Include Bootstrap CSS -->
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <!-- Include jQuery (necessary for Bootstrap's JavaScript plugins) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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
    <!-- Include header.php here -->
    <?php include 'header.php'; ?>

    <div class="container mt-4">
        <h2>Manage Savings</h2>

        <!-- Savings Table with User Names -->
        <form id="savings-form">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Account</th>
                        <th>Description</th>
                        <th>Balance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($accounts as $account): ?>
                        <tr>
                            <td><?= htmlspecialchars($account['UserName']) ?></td>
                            <td>
                                <input type="text" class="form-control" name="ShortDesc_<?= $account['AccountID'] ?>" value="<?= htmlspecialchars($account['ShortDesc']) ?>" disabled>
                            </td>
                            <td>
                                <input type="text" class="form-control" name="LongDesc_<?= $account['AccountID'] ?>" value="<?= htmlspecialchars($account['LongDesc']) ?>" disabled>
                            </td>
                            <td>
                                <input type="number" class="form-control" name="Balance_<?= $account['AccountID'] ?>" value="<?= htmlspecialchars($account['Balance']) ?>" step="0.01">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="button" class="btn btn-primary" id="save-btn">Save Changes</button>
        </form>
        
            
<br>

    
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
                    
                        <div class="thermometer-label" id="thermometer-label"></div>
                        
                    </div>
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
    <script>
        // Handle save button click
        $('#save-btn').on('click', function () {
            var formData = $('#savings-form').serialize(); // Serialize all form data

            $.ajax({
                url: '', // We use the same page to handle the request
                method: 'POST',
                data: formData + '&action=save_savings',
                success: function (response) {
                    alert('Changes saved successfully!');
                },
                error: function (xhr, status, error) {
                    console.error('Save failed:', error);
                    alert('Failed to save changes. Please try again.');
                }
            });
        });
    </script>

    <?php include 'footer.php'; ?>

</body>
</html>

<?php
// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_savings') {
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'ShortDesc_') === 0 || strpos($key, 'LongDesc_') === 0 || strpos($key, 'Balance_') === 0) {
            $fieldParts = explode('_', $key);
            $field = $fieldParts[0]; // ShortDesc, LongDesc, or Balance
            $accountID = $fieldParts[1];

            // Update the database
            $sqlUpdate = "UPDATE DCBankAccounts SET $field = ? WHERE AccountID = ?";
            $stmt = $link->prepare($sqlUpdate);
            $stmt->bind_param('si', $value, $accountID);
            $stmt->execute();
            $stmt->close();
        }
    }
    echo 'success';
    exit;
}
?>
