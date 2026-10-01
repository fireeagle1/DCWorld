<?php
session_start();
require '../config.php';
require '../auth.php';


/*
  ---------- Handle Form Submission for New Transaction ----------
  The form includes a "Transaction Type" dropdown with two options:
    - "deposit" for money coming into the joint account.
    - "withdrawal" for money spent.
  
  For a deposit:
    - The originator is the person depositing money (selected from your users or entered manually).
    - The amount is stored in the Credit column.
    - The recipient is automatically set to "Joint Account".
  
  For a withdrawal:
    - The originator is chosen (it may be an individual or "Joint Account" if spending from the joint account).
    - The recipient (typically a vendor) is chosen (or entered manually).
    - The amount is stored in the Debit column.
*/
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $transactionType = $_POST['transaction_type']; // 'deposit' or 'withdrawal'
    $amount = $_POST['amount'];
    $reason = trim($_POST['reason']);
    $notes = trim($_POST['notes']);
    $transactionTime = trim($_POST['transaction_time']); // User provided transaction time

    // Set default values for all fields
    $originatorID = NULL;
    $originatorName = NULL;
    $recipientID = NULL;
    $recipientName = NULL;
    $debit = 0;
    $credit = 0;
    
    if ($transactionType === 'deposit') {
        // Process Originator for deposit
        if ($_POST['originator_select'] === 'other') {
            $originatorID = NULL;
            $originatorName = trim($_POST['originator_other']);
        } else {
            $originatorID = (int)$_POST['originator_select'];
            $originatorName = NULL;
        }
        // For deposits, amount is stored in Credit and recipient is fixed.
        $credit = $amount;
        $debit = 0;
        $recipientID = NULL;
        $recipientName = "Joint Account";
    } else {
        // Process withdrawal
        // Process Originator (could be "Joint Account" or an individual)
        if ($_POST['originator_select'] === 'other') {
            $originatorID = NULL;
            $originatorName = trim($_POST['originator_other']);
        } elseif ($_POST['originator_select'] === 'joint') {
            $originatorID = NULL;
            $originatorName = "Joint Account";
        } else {
            $originatorID = (int)$_POST['originator_select'];
            $originatorName = NULL;
        }
        
        // Process Recipient for withdrawal (typically a vendor)
        if ($_POST['recipient_select'] === 'other') {
            $recipientID = NULL;
            $recipientName = trim($_POST['recipient_other']);
        } else {
            if ($_POST['recipient_select'] !== '') {
                $recipientID = (int)$_POST['recipient_select'];
                $recipientName = NULL;
            } else {
                $recipientID = NULL;
                $recipientName = ""; // optional, can be left empty
            }
        }
        $debit = $amount;
        $credit = 0;
    }
    
    // For bind_param, set enteredBy from session.
    $enteredBy = $_SESSION['userID'];
    
    // Validate required fields
    if ($amount > 0 && is_numeric($amount) && !empty($transactionTime)) {
        $stmt = $link->prepare("INSERT INTO DC_House_Ledger 
            (TransactionTime, OriginatorID, OriginatorName, Debit, Credit, RecipientID, RecipientName, Reason, EnteredBy, Notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sisddisiss", 
            $transactionTime, 
            $originatorID, 
            $originatorName, 
            $debit, 
            $credit, 
            $recipientID, 
            $recipientName, 
            $reason, 
            $enteredBy, 
            $notes
        );
        
        if ($stmt->execute()) {
            echo "<script>alert('Transaction recorded successfully!');</script>";
        } else {
            echo "<script>alert('Error: " . $stmt->error . "');</script>";
        }
    } else {
        echo "<script>alert('Please enter a valid amount and transaction time.');</script>";
    }
}

/*
  ---------- Totals Calculation ----------
  We assume:
    - Partner 1 has UserID = 1
    - Partner 2 has UserID = 3
  
  For deposits (Total In) per partner:
*/
$result1In = $link->query("SELECT SUM(Credit) AS totalIn FROM DC_House_Ledger WHERE OriginatorID = 1");
$partner1In = $result1In->fetch_assoc()['totalIn'] ?? 0;

$result2In = $link->query("SELECT SUM(Credit) AS totalIn FROM DC_House_Ledger WHERE OriginatorID = 3");
$partner2In = $result2In->fetch_assoc()['totalIn'] ?? 0;

/*
  Joint Account Balance: 
    = Total joint deposits - total joint withdrawals.
    For deposits, we check where RecipientName is 'Joint Account'.
    For withdrawals, we check where OriginatorName is 'Joint Account'.
*/
$resultJointDeposit = $link->query("SELECT SUM(Credit) AS totalJointDeposit FROM DC_House_Ledger WHERE RecipientName = 'Joint Account'");
$totalJointDeposit = $resultJointDeposit->fetch_assoc()['totalJointDeposit'] ?? 0;

$resultJointWithdrawal = $link->query("SELECT SUM(Debit) AS totalJointWithdrawal FROM DC_House_Ledger WHERE OriginatorName = 'Joint Account'");
$totalJointWithdrawal = $resultJointWithdrawal->fetch_assoc()['totalJointWithdrawal'] ?? 0;

$jointBalance = $totalJointDeposit - $totalJointWithdrawal;

/*
  Direct spending by each partner: sum of Debit where OriginatorID equals the partner.
  Joint spending (where OriginatorName = 'Joint Account') is split equally between the partners.
*/
$result1DirectOut = $link->query("SELECT SUM(Debit) AS totalOut FROM DC_House_Ledger WHERE OriginatorID = 1");
$partner1DirectOut = $result1DirectOut->fetch_assoc()['totalOut'] ?? 0;

$result2DirectOut = $link->query("SELECT SUM(Debit) AS totalOut FROM DC_House_Ledger WHERE OriginatorID = 3");
$partner2DirectOut = $result2DirectOut->fetch_assoc()['totalOut'] ?? 0;

$resultJointOut = $link->query("SELECT SUM(Debit) AS jointOut FROM DC_House_Ledger WHERE OriginatorName = 'Joint Account'");
$jointOut = $resultJointOut->fetch_assoc()['jointOut'] ?? 0;

$partner1Spent = $partner1DirectOut + ($jointOut / 2);
$partner2Spent = $partner2DirectOut + ($jointOut / 2);

/*
  You may also want the net balance per partner:
    Net Balance = Total In - Amount Spent.
*/
$partner1Net = $partner1In - $partner1Spent;
$partner2Net = $partner2In - $partner2Spent;

/*
  ---------- Fetch All Transactions for Display ----------
  We use COALESCE to show either the user’s name (if available) or the manual entry.
  Also, we pull the name of the user who entered the transaction.
*/
$sql = "SELECT t.*, 
        COALESCE(u1.Name, t.OriginatorName) AS OriginatorDisplay, 
        COALESCE(u2.Name, t.RecipientName) AS RecipientDisplay,
        (SELECT Name FROM DC_Users WHERE UserID = t.EnteredBy) AS EnteredByName
        FROM DC_House_Ledger t
        LEFT JOIN DC_Users u1 ON t.OriginatorID = u1.UserID
        LEFT JOIN DC_Users u2 ON t.RecipientID = u2.UserID
        ORDER BY t.TransactionTime DESC";
$result = $link->query($sql);

/*
  ---------- Fetch Users for Dropdowns ----------
*/
$sqlUsers = "SELECT UserID, Name FROM DC_Users ORDER BY Name";
$resultUsers = $link->query($sqlUsers);
$users = [];
while ($row = $resultUsers->fetch_assoc()) {
    $users[] = $row;
}
$resultUsers->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Finance and Cost Tracking</title>
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <!-- Font Awesome for icons -->
  <link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.15.4/css/all.css">
  <style>
    .info-box {
        border: 1px solid #ccc;
        padding: 15px;
        margin-bottom: 15px;
        border-radius: 5px;
    }
    .sidebar {
        border-left: 2px solid #ddd;
        padding-left: 20px;
    }
    .magnifier {
        cursor: pointer;
        color: #007bff;
    }
  </style>
</head>
<body>

<?php include '../header.php'; ?>
<?php include 'subheader.php'; ?>


<div class="container mt-4">
  <div class="row">
    <!-- Left Column: Transactions Table -->
    <div class="col-md-8">
      <h1>House Ledger</h1>
      
      <!-- Add Transaction Button -->
      <button type="button" class="btn btn-primary mb-3" data-toggle="modal" data-target="#transactionModal">
        Add Transaction
      </button>
      
      <!-- Transactions Table -->
      <table class="table table-bordered table-striped">
        <thead class="thead-dark">
          <tr>
            <th>#</th>
            <th>Originator</th>
            <th>Recipient</th>
            <th>Type</th>
            <th>Amount</th>
            <th>Transaction Time</th>
            <th><!-- Details --></th>
          </tr>
        </thead>
        <tbody>
          <?php while($row = $result->fetch_assoc()): ?>
            <tr>
              <td><?= $row['TransID'] ?></td>
              <td><?= htmlspecialchars($row['OriginatorDisplay']) ?></td>
              <td><?= htmlspecialchars($row['RecipientDisplay'] ?? 'N/A') ?></td>
              <td>
                <?php 
                  echo ($row['Credit'] > 0) ? "Deposit" : "Withdrawal";
                ?>
              </td>
              <td>£<?= number_format(($row['Credit'] > 0 ? $row['Credit'] : $row['Debit']), 2) ?></td>
              <td><?= htmlspecialchars($row['TransactionTime']) ?></td>
              <td class="text-center">
                <!-- Magnifying glass icon for details -->
                <span class="magnifier" data-toggle="modal" data-target="#detailModal"
                  data-transid="<?= $row['TransID'] ?>"
                  data-originator="<?= htmlspecialchars($row['OriginatorDisplay']) ?>"
                  data-recipient="<?= htmlspecialchars($row['RecipientDisplay'] ?? 'N/A') ?>"
                  data-type="<?= ($row['Credit'] > 0) ? 'Deposit' : 'Withdrawal' ?>"
                  data-amount="£<?= number_format(($row['Credit'] > 0 ? $row['Credit'] : $row['Debit']), 2) ?>"
                  data-reason="<?= htmlspecialchars($row['Reason']) ?>"
                  data-ttime="<?= htmlspecialchars($row['TransactionTime']) ?>"
                  data-tentered="<?= htmlspecialchars($row['TimeEntered']) ?>"
                  data-enteredby="<?= htmlspecialchars($row['EnteredByName']) ?>"
                  data-notes="<?= htmlspecialchars($row['Notes']) ?>"
                >
                  <i class="fa fa-search"></i>
                </span>
              </td>
            </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>
    
    <!-- Right Column: Balances Sidebar -->
    <div class="col-md-4 sidebar">
      <h3>Balances</h3>
      <div class="info-box">
        <strong>Charlie Total In:</strong>
        <p>£<?= number_format($partner1In, 2) ?></p>
      </div>
      <div class="info-box">
        <strong>Daniel Total In:</strong>
        <p>£<?= number_format($partner2In, 2) ?></p>
      </div>
      <div class="info-box">
        <strong>Joint Account Balance:</strong>
        <p>£<?= number_format($jointBalance, 2) ?></p>
      </div>
      <div class="info-box">
        <strong>Amount Spent from Charlie’s Money:</strong>
        <p>£<?= number_format($partner1Spent, 2) ?></p>
        <p>Net: £<?= number_format($partner1Net, 2) ?></p>
      </div>
      <div class="info-box">
        <strong>Amount Spent from Daniel’s Money:</strong>
        <p>£<?= number_format($partner2Spent, 2) ?></p>
        <p>Net: £<?= number_format($partner2Net, 2) ?></p>
      </div>
    </div>
  </div>
</div>

<!-- Modal for Transaction Details -->
<div class="modal fade" id="detailModal" tabindex="-1" role="dialog" aria-labelledby="detailModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="detailModalLabel">Transaction Details</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <p><strong>Transaction ID:</strong> <span id="d_transid"></span></p>
        <p><strong>Originator:</strong> <span id="d_originator"></span></p>
        <p><strong>Recipient:</strong> <span id="d_recipient"></span></p>
        <p><strong>Type:</strong> <span id="d_type"></span></p>
        <p><strong>Amount:</strong> <span id="d_amount"></span></p>
        <p><strong>Reason:</strong> <span id="d_reason"></span></p>
        <p><strong>Transaction Time:</strong> <span id="d_ttime"></span></p>
        <p><strong>Time Entered:</strong> <span id="d_tentered"></span></p>
        <p><strong>Entered By:</strong> <span id="d_enteredby"></span></p>
        <p><strong>Notes:</strong> <span id="d_notes"></span></p>
      </div>
    </div>
  </div>
</div>

<!-- Modal for Adding Transaction -->
<div class="modal fade" id="transactionModal" tabindex="-1" role="dialog" aria-labelledby="transactionModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="POST">
      <div class="modal-header">
        <h5 class="modal-title" id="transactionModalLabel">Add New Transaction</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
          <!-- Transaction Type -->
          <div class="form-group">
              <label for="transaction_type">Transaction Type</label>
              <select name="transaction_type" id="transaction_type" class="form-control" required>
                  <option value="">-- Select Type --</option>
                  <option value="deposit">Deposit</option>
                  <option value="withdrawal">Withdrawal</option>
              </select>
          </div>
          <!-- Transaction Time -->
          <div class="form-group">
              <label for="transaction_time">Transaction Date &amp; Time</label>
              <input type="datetime-local" name="transaction_time" id="transaction_time" class="form-control" required>
          </div>
          <!-- Amount -->
          <div class="form-group">
              <label for="amount">Amount (£):</label>
              <input type="number" step="0.01" name="amount" id="amount" class="form-control" required>
          </div>
          
          <!-- Originator Section -->
          <div class="form-group">
              <label for="originator_select">Originator:</label>
              <select name="originator_select" id="originator_select" class="form-control" required>
                  <option value="">-- Select a user --</option>
                  <?php foreach ($users as $user): ?>
                      <option value="<?= $user['UserID'] ?>"><?= htmlspecialchars($user['Name']) ?></option>
                  <?php endforeach; ?>
                  <option value="other">Other</option>
                  <option value="joint">Joint Account</option>
              </select>
          </div>
          <div class="form-group" id="originator_other_div" style="display:none;">
              <label for="originator_other">Enter Originator Name</label>
              <input type="text" name="originator_other" id="originator_other" class="form-control" placeholder="Vendor/Other Name">
          </div>
          
          <!-- Recipient Section (only for withdrawal) -->
          <div class="form-group" id="recipient_section" style="display:none;">
              <label for="recipient_select">Recipient (Vendor):</label>
              <select name="recipient_select" id="recipient_select" class="form-control">
                  <option value="">-- Select a user --</option>
                  <?php foreach ($users as $user): ?>
                      <option value="<?= $user['UserID'] ?>"><?= htmlspecialchars($user['Name']) ?></option>
                  <?php endforeach; ?>
                  <option value="other">Other</option>
              </select>
          </div>
          <div class="form-group" id="recipient_other_div" style="display:none;">
              <label for="recipient_other">Enter Recipient Name</label>
              <input type="text" name="recipient_other" id="recipient_other" class="form-control" placeholder="Vendor/Other Name">
          </div>
          
          <!-- Reason -->
          <div class="form-group">
              <label for="reason">Reason:</label>
              <input type="text" name="reason" id="reason" class="form-control">
          </div>
          <!-- Notes -->
          <div class="form-group">
              <label for="notes">Notes:</label>
              <textarea name="notes" id="notes" class="form-control"></textarea>
          </div>
      </div>
      <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-success">Save Transaction</button>
      </div>
      </form>
    </div>
  </div>
</div>

<?php include '../footer.php'; ?>

<!-- Required JS -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.3/dist/umd/popper.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
<!-- Font Awesome for icons -->
<script src="https://use.fontawesome.com/releases/v5.15.4/js/all.js"></script>

<script>
  // Toggle manual originator field
  $('#originator_select').on('change', function(){
    if($(this).val() === 'other'){
      $('#originator_other_div').show();
    } else {
      $('#originator_other_div').hide();
    }
  });
  
  // For withdrawals, show the recipient section; for deposits, hide it.
  $('#transaction_type').on('change', function(){
    if($(this).val() === 'withdrawal'){
      $('#recipient_section').show();
    } else {
      $('#recipient_section').hide();
      $('#recipient_other_div').hide();
    }
  });
  
  // Toggle manual recipient field
  $('#recipient_select').on('change', function(){
    if($(this).val() === 'other'){
      $('#recipient_other_div').show();
    } else {
      $('#recipient_other_div').hide();
    }
  });
  
  // Fill in the details modal when clicking the magnifying glass icon
  $('.magnifier').on('click', function(){
    $('#d_transid').text($(this).data('transid'));
    $('#d_originator').text($(this).data('originator'));
    $('#d_recipient').text($(this).data('recipient'));
    $('#d_type').text($(this).data('type'));
    $('#d_amount').text($(this).data('amount'));
    $('#d_reason').text($(this).data('reason'));
    $('#d_ttime').text($(this).data('ttime'));
    $('#d_tentered').text($(this).data('tentered'));
    $('#d_enteredby').text($(this).data('enteredby'));
    $('#d_notes').text($(this).data('notes'));
  });
</script>
</body>
</html>
