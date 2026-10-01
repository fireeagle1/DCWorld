<?php
session_start();
require '../config.php';
require '../auth.php';


// 1. Process Filters (From GET)
$filterStatus = isset($_GET['status']) ? trim($_GET['status']) : '';
$filterAssigned = isset($_GET['assigned']) ? (int)$_GET['assigned'] : 0;

// 2. Build the Query Dynamically
$sql = "SELECT t.*, u.Name AS AssignedName
        FROM DC_Tasks t
        LEFT JOIN DC_Users u ON t.AssignedTo = u.UserID
        WHERE 1=1";

$params = [];
$types = "";

// Filter by Status if provided
if ($filterStatus !== '') {
    $sql .= " AND t.Status = ?";
    $params[] = $filterStatus;
    $types .= "s";
}

// Filter by AssignedTo if provided
if ($filterAssigned > 0) {
    $sql .= " AND t.AssignedTo = ?";
    $params[] = $filterAssigned;
    $types .= "i";
}

$sql .= " ORDER BY t.StartDate ASC"; // Or your preferred ordering

$stmt = $link->prepare($sql);
if ($types !== "") {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// 3. Fetch All Users for the Assigned-To Filter
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
  <title>Tasks</title>
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <style>
    .status-badge {
      font-size: 0.8rem;
      padding: 0.3rem 0.6rem;
      border-radius: 5px;
      color: #fff;
    }
    /* Example color coding for RAGStatus */
    .rag-red {
      background-color: #dc3545;
    }
    .rag-amber {
      background-color: #ffc107;
      color: #212529; /* Dark text on amber background */
    }
    .rag-green {
      background-color: #28a745;
    }
  </style>
</head>
<body>

<?php include '../header.php'; ?>
<?php include 'subheader.php'; ?>

<div class="container mt-4">
  <h1>Tasks Dashboard</h1>
  
  <!-- Filter Form -->
  <form method="GET" class="form-inline mb-3">
    <label class="mr-2" for="statusFilter">Status:</label>
    <select name="status" id="statusFilter" class="form-control mr-3">
      <option value="">All</option>
      <!-- Add whatever status options you use -->
      <option value="Not Started" <?php if($filterStatus==='Not Started') echo 'selected'; ?>>Not Started</option>
      <option value="In Progress" <?php if($filterStatus==='In Progress') echo 'selected'; ?>>In Progress</option>
      <option value="Done" <?php if($filterStatus==='Done') echo 'selected'; ?>>Done</option>
    </select>
    
    <label class="mr-2" for="assignedFilter">Assigned To:</label>
    <select name="assigned" id="assignedFilter" class="form-control mr-3">
      <option value="0">All</option>
      <?php foreach ($users as $user): ?>
        <option value="<?= $user['UserID'] ?>" 
          <?= ($filterAssigned == $user['UserID']) ? 'selected' : '' ?>>
          <?= htmlspecialchars($user['Name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    
    <button type="submit" class="btn btn-primary">Filter</button>
    
    <!-- Add Task Button -->
    <a href="add_task.php" class="btn btn-success ml-3">Add Task</a>
  </form>

  <!-- Tasks Table -->
  <table class="table table-bordered table-striped">
    <thead class="thead-dark">
      <tr>
        <th>#</th>
        <th>Category</th>
        <th>Short Desc</th>
        <th>Assigned</th>
        <th>Start Date</th>
        <th>End Date</th>
        <th>Status</th>
        <th>RAG</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php while($row = $result->fetch_assoc()): ?>
        <tr>
          <td><?= $row['TaskID'] ?></td>
          <td><?= htmlspecialchars($row['TaskCategory']) ?></td>
          <td><?= htmlspecialchars($row['ShortDesc']) ?></td>
          <td><?= htmlspecialchars($row['AssignedName'] ?? 'N/A') ?></td>
          <td><?= htmlspecialchars($row['StartDate']) ?></td>
          <td><?= htmlspecialchars($row['EndDate']) ?></td>
          <td><?= htmlspecialchars($row['Status']) ?></td>
          <td>
            <?php
              $rag = strtolower($row['RAGStatus']);
              $ragClass = ($rag === 'red') ? 'rag-red'
                       : (($rag === 'amber') ? 'rag-amber' : 'rag-green');
            ?>
            <span class="status-badge <?= $ragClass ?>">
              <?= htmlspecialchars($row['RAGStatus']) ?>
            </span>
          </td>
          <td>
            <!-- Example actions: View, Edit, Delete -->
            <a href="view_task.php?TaskID=<?= $row['TaskID'] ?>" class="btn btn-sm btn-info">View</a>
            <a href="edit_task.php?TaskID=<?= $row['TaskID'] ?>" class="btn btn-sm btn-warning">Edit</a>
            <a href="delete_task.php?TaskID=<?= $row['TaskID'] ?>" class="btn btn-sm btn-danger"
               onclick="return confirm('Are you sure you want to delete this task?');">
               Delete
            </a>
          </td>
        </tr>
      <?php endwhile; ?>
    </tbody>
  </table>

</div>

  <?php include '../footer.php'; ?>
</body>
</html>
