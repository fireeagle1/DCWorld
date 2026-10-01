<?php
session_start();
require '../config.php';

require '../auth.php';


// Fetch the logged-in user's information
$userID = $_SESSION['userID'];
$sqlUser = "SELECT Name FROM DC_Users WHERE UserID = ?";
$stmtUser = $link->prepare($sqlUser);
$stmtUser->bind_param("i", $userID);
$stmtUser->execute();
$stmtUser->bind_result($Name);
$stmtUser->fetch();
$stmtUser->close();

// For demonstration, using placeholders. Replace with real data if desired:
$houseAddress = "";
$houseCost = 2;
$houseDeposit = 2;
$stampDuty = 2; // Example for first-time buyers or as needed
$moveInDateString = "2025-06-09"; // Used for countdown (YYYY-MM-DD)

$target = 50000; // For the savings thermometer
$totalBalance = 46000; // Example: total savings

/* ============================================================
   FETCH DYNAMIC TASKS
   ============================================================
   Retrieve the latest 5 tasks from DC_Tasks and join with DC_Users to 
   display the assigned user's name.
*/
$sqlTasks = "SELECT t.*, u.Name AS AssignedName 
             FROM DC_Tasks t 
             LEFT JOIN DC_Users u ON t.AssignedTo = u.UserID 
             ORDER BY t.StartDate DESC LIMIT 5";
$resultTasks = $link->query($sqlTasks);
if (!$resultTasks) {
    die("Error fetching tasks: " . $link->error);
}

/* ============================================================
   FETCH DYNAMIC FREEBIES
   ============================================================
   Retrieve the latest 5 freebies from DC_Freebies and join with Contacts.
   The contact is displayed as: KnownAs (FirstName LastName)
*/
$sqlFreebies = "SELECT f.*, CONCAT(c.KnownAs, ' (', c.FirstName, ' ', c.LastName, ')') AS ContactName 
                FROM DC_Freebies f 
                LEFT JOIN Contacts c ON f.ContactID = c.ContactID 
                ORDER BY f.EnteredTime DESC LIMIT 5";
$resultFreebies = $link->query($sqlFreebies);
if (!$resultFreebies) {
    die("Error fetching freebies: " . $link->error);
}

$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>House Dashboard</title>
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
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
    /* Left Dashboard Styling */
    .dashboard-item {
      background: #fff;
      border-radius: 8px;
      box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
      margin-bottom: 1rem;
      padding: 10px;
    }
    .dashboard-item .card-header {
      border-top-left-radius: 8px;
      border-top-right-radius: 8px;
    }
    .status-dot {
      width: 15px;
      height: 15px;
      border-radius: 50%;
      display: inline-block;
      margin-right: 0.3rem;
    }
    .status-green { background-color: #28a745; }
    .status-amber { background-color: #ffc107; }
    .status-grey  { background-color: #6c757d; }
    /* Right Column: House Info */
    .house-info-card {
      background-color: #fff;
      border-radius: 8px;
      box-shadow: 0 4px 6px rgba(0,0,0,0.1);
      margin-bottom: 1rem;
    }
    .house-info-card img {
      width: 100%;
      height: auto;
      border-top-left-radius: 8px;
      border-top-right-radius: 8px;
      object-fit: cover;
    }
    .house-info-card .card-body {
      padding: 1rem;
    }
    /* Small Thermometer */
    .thermometer-container-sm {
      position: relative;
      height: 200px;
      width: 60px;
      margin: 0 auto;
      background-color: #e0e0e0;
      border-radius: 25px;
      overflow: hidden;
    }
    .thermometer-inner-sm {
      position: absolute;
      bottom: 0;
      width: 100%;
      background-color: #29af8f;
      border-radius: 25px;
      transition: height 0.5s ease-in-out;
    }
    .small-thermometer-scale {
      position: absolute;
      top: 0;
      left: -40px;
      height: 100%;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      align-items: flex-end;
      font-size: 0.8rem;
    }
    .small-thermometer-label {
      text-align: center;
      margin-top: 10px;
      font-size: 0.9rem;
    }
    /* Countdown text style */
    .countdown-text {
      font-size: 1.2rem;
      font-weight: bold;
      color: #dc3545;
      margin-top: 10px;
    }
    /* Table Styles for Dashboard Sections */
    .table th, .table td {
      vertical-align: middle;
    }
    .freebie-thumb {
      width: 60px;
      height: 60px;
      object-fit: cover;
      border: 1px solid #ccc;
    }
    .btn-view {
      margin: 0;
      padding: 4px 8px;
    }
    .spacer {
      height: 20px;
    }
  </style>
</head>
<body>
  <?php include '../header.php'; ?>
  <?php include 'subheader.php'; ?>

  <div class="container mt-4">
    <h1>Welcome, <?= htmlspecialchars($Name) ?></h1>
    
    <div class="row">
      <!-- LEFT COLUMN: Dashboard Items -->
      <div class="col-md-8">
        <!-- Dynamic Upcoming Tasks Section -->
        <div class="card dashboard-item">
          <div class="card-header">Upcoming Tasks</div>
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-bordered table-striped">
                <thead class="thead-dark">
                  <tr>
                    <th>Category</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th>Assigned To</th>
                    <th>Short Description</th>
                    <th>Status</th>
                    <th>RAG</th>
                    <th>View</th>
                  </tr>
                </thead>
                <tbody>
                  <?php while($task = $resultTasks->fetch_assoc()): ?>
                  <tr>
                    <td><?= htmlspecialchars($task['TaskCategory']) ?></td>
                    <td><?= htmlspecialchars($task['StartDate']) ?></td>
                    <td><?= htmlspecialchars($task['EndDate']) ?></td>
                    <td><?= htmlspecialchars($task['AssignedName'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($task['ShortDesc']) ?></td>
                    <td><?= htmlspecialchars($task['Status']) ?></td>
                    <td><?= htmlspecialchars($task['RAGStatus']) ?></td>
                    <td>
                      <a href="/home/view_task.php?TaskID=<?= $task['TaskID'] ?>" class="btn btn-sm btn-info btn-view" title="View Task">
                        <i class="fas fa-search"></i>
                      </a>
                    </td>
                  </tr>
                  <?php endwhile; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Key Milestone Dates (Static) -->
        <div class="card dashboard-item">
          <div class="card-header">Key Milestones</div>
          <div class="card-body">
            <table class="table table-bordered mb-0">
              <thead>
                <tr>
                  <th>Milestone</th>
                  <th>Target Date</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td>Mortgage Application</td>
                  <td>5th March</td>
                  <td>Complete</td>
                </tr>
                <tr>
                  <td>Surveys</td>
                  <td>14th April</td>
                  <td>Booked</td>
                </tr>
                <tr>
                  <td>Exchange of Contracts</td>
                  <td>4th June</td>
                  <td>Pending</td>
                </tr>
                <tr>
                  <td>Completion Day</td>
                  <td>6th June</td>
                  <td>Pending</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Dynamic Freebies Section (Offers of Furniture) -->
        <div class="card dashboard-item">
          <div class="card-header">Offers of Furniture</div>
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-hover mb-0">
                <thead class="thead-dark">
                  <tr>
                    <th>Item</th>
                    <th>Image</th>
                    <th>Category</th>
                    <th>Status</th>
                    <th>Contact</th>
                    <th>View</th>
                  </tr>
                </thead>
                <tbody>
                  <?php while($freebie = $resultFreebies->fetch_assoc()): ?>
                  <tr>
                    <td><?= htmlspecialchars($freebie['ShortDesc']) ?></td>
                    <td>
                      <?php if(!empty($freebie['ImageOfItem'])): ?>
                        <img src="<?= htmlspecialchars($freebie['ImageOfItem'] ?? '') ?>" alt="Freebie Image" class="freebie-thumb">
                      <?php else: ?>
                        <i class="fas fa-image fa-2x text-muted"></i>
                      <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($freebie['Category']) ?></td>
                    <td><?= htmlspecialchars($freebie['Status']) ?></td>
                    <td><?= htmlspecialchars($freebie['ContactName'] ?? '') ?></td>
                    <td>
                      <a href="freebie_details.php?id=<?= $freebie['FreebiesID'] ?>" class="btn btn-sm btn-info btn-view" title="View Freebie Details">
                        <i class="fas fa-search"></i>
                      </a>
                    </td>
                  </tr>
                  <?php endwhile; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- You can add more sections here if needed -->
      </div>

      <!-- RIGHT COLUMN: House Info, Countdown, and Thermometer -->
      <div class="col-md-4">
        <!-- House Image & Basic Info -->
        <div class="card house-info-card">
          <img src="https://assets.dcworld.uk/images/brand_67ee6d1d5fa3b5.24326362.jpeg" alt="House Image" />
          <div class="card-body">
            <h5 class="card-title">Address</h5>
            <p><?= htmlspecialchars($houseAddress) ?></p>

            <h6>Cost of the House</h6>
            <p>£<?= number_format($houseCost) ?></p>

            <h6>Deposit</h6>
            <p>£<?= number_format($houseDeposit) ?></p>

            <h6>Stamp Duty Estimate</h6>
            <p>£<?= number_format($stampDuty) ?></p>
          </div>
        </div>

        <!-- Countdown to Move-In -->
        <div class="card house-info-card">
          <div class="card-header">Countdown</div>
          <div class="card-body text-center">
            <div id="countdownDisplay" class="countdown-text">Loading...</div>
          </div>
        </div>

        <!-- Smaller Thermometer -->
        <div class="card house-info-card">
          <div class="card-header">Savings Progress</div>
          <div class="card-body text-center">
            <div class="thermometer-container-sm">
             
            <div class="small-thermometer-label" id="thermometerLabelSm"></div>
          </div>
        </div>
      </div>
    </div> <!-- end row -->
  </div> <!-- end container -->

  <?php include '../footer.php'; ?>

  <script>
    // --- Smaller Thermometer ---
    (function() {
      const currentBalance = <?= json_encode($totalBalance) ?>; 
      const target = <?= json_encode($target) ?>;
      const thermometerInnerSm = document.getElementById('thermometerInnerSm');
      const thermometerLabelSm = document.getElementById('thermometerLabelSm');
      let percentage = (currentBalance / target) * 100;
      if (percentage > 100) percentage = 100;
      thermometerInnerSm.style.height = percentage + '%';
      thermometerLabelSm.textContent = "Savings: £" + currentBalance.toLocaleString();
    })();

    // --- Countdown to Move-In ---
    (function() {
      const countdownElement = document.getElementById('countdownDisplay');
      const moveInDate = new Date("<?= $moveInDateString ?>T00:00:00");
      
      function updateCountdown() {
        const now = new Date();
        const diff = moveInDate - now;
        if (diff <= 0) {
          countdownElement.textContent = "Move-In Day is here!";
          return;
        }
        const days = Math.floor(diff / (1000 * 60 * 60 * 24));
        countdownElement.textContent = days + " days until Move-In Day";
      }

      updateCountdown();
      setInterval(updateCountdown, 24 * 60 * 60 * 1000);
    })();
  </script>
</body>
</html>
