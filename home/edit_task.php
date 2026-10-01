<?php
session_start();

// Since config.php is in the parent directory
require '../config.php';
require '../auth.php';


// Get TaskID from URL
$taskID = isset($_GET['TaskID']) ? (int)$_GET['TaskID'] : 0;
if ($taskID < 1) {
    // Invalid or missing TaskID
    header("Location: tasks.php");
    exit();
}

// 1) Fetch the existing task
$sqlTask = "SELECT * FROM DC_Tasks WHERE TaskID = ?";
$stmt = $link->prepare($sqlTask);
$stmt->bind_param("i", $taskID);
$stmt->execute();
$result = $stmt->get_result();
$task = $result->fetch_assoc();
$stmt->close();

// If no task is found, redirect
if (!$task) {
    header("Location: tasks.php");
    exit();
}

// 2) Fetch list of users to populate "Assigned To" dropdown
$sqlUsers = "SELECT UserID, Name FROM DC_Users ORDER BY Name";
$resultUsers = $link->query($sqlUsers);
$users = [];
while ($row = $resultUsers->fetch_assoc()) {
    $users[] = $row;
}
$resultUsers->close();

// 3) If the form is submitted, process the UPDATE
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category   = $_POST['TaskCategory'];
    $startDate  = $_POST['StartDate'];
    $endDate    = $_POST['EndDate'];
    $assignedTo = $_POST['AssignedTo'];
    $shortDesc  = $_POST['ShortDesc'];
    $longDesc   = $_POST['LongDesc'];
    $status     = $_POST['Status'];
    $ragStatus  = $_POST['RAGStatus'];

    // Update statement
    $sqlUpdate = "
        UPDATE DC_Tasks
        SET TaskCategory = ?,
            StartDate = ?,
            EndDate = ?,
            AssignedTo = ?,
            ShortDesc = ?,
            LongDesc = ?,
            Status = ?,
            RAGStatus = ?
        WHERE TaskID = ?
    ";
    $stmtUpdate = $link->prepare($sqlUpdate);
    $stmtUpdate->bind_param(
        "sssissssi",
        $category,
        $startDate,
        $endDate,
        $assignedTo,
        $shortDesc,
        $longDesc,
        $status,
        $ragStatus,
        $taskID
    );
    $stmtUpdate->execute();
    $stmtUpdate->close();

    // Redirect back to view page (or tasks.php) after update
    header("Location: view_task.php?TaskID=$taskID");
    exit();
}

$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Edit Task</title>
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>

<!-- Include your main header and subheader here -->
<?php include '../header.php'; ?>
<?php include 'subheader.php'; ?>

<div class="container mt-4">
  <h1>Edit Task #<?= htmlspecialchars($task['TaskID']) ?></h1>

  <form method="POST" action="edit_task.php?TaskID=<?= $taskID ?>">
    <div class="form-group">
      <label for="TaskCategory">Task Category</label>
      <input
        type="text"
        class="form-control"
        name="TaskCategory"
        id="TaskCategory"
        value="<?= htmlspecialchars($task['TaskCategory']) ?>"
        required
      />
    </div>

    <div class="form-group">
      <label for="ShortDesc">Short Description</label>
      <input
        type="text"
        class="form-control"
        name="ShortDesc"
        id="ShortDesc"
        value="<?= htmlspecialchars($task['ShortDesc']) ?>"
        required
      />
    </div>

    <div class="form-group">
      <label for="LongDesc">Long Description</label>
      <textarea
        class="form-control"
        name="LongDesc"
        id="LongDesc"
        rows="3"
      ><?= htmlspecialchars($task['LongDesc']) ?></textarea>
    </div>

    <div class="form-group">
      <label for="AssignedTo">Assigned To</label>
      <select class="form-control" name="AssignedTo" id="AssignedTo" required>
        <option value="">--Select User--</option>
        <?php foreach ($users as $u): ?>
          <option
            value="<?= $u['UserID'] ?>"
            <?php if ($u['UserID'] == $task['AssignedTo']) echo 'selected'; ?>
          >
            <?= htmlspecialchars($u['Name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="form-group">
      <label for="StartDate">Start Date</label>
      <input
        type="date"
        class="form-control"
        name="StartDate"
        id="StartDate"
        value="<?= htmlspecialchars($task['StartDate']) ?>"
        required
      />
    </div>

    <div class="form-group">
      <label for="EndDate">End Date</label>
      <input
        type="date"
        class="form-control"
        name="EndDate"
        id="EndDate"
        value="<?= htmlspecialchars($task['EndDate']) ?>"
        required
      />
    </div>

    <div class="form-group">
      <label for="Status">Status</label>
      <select class="form-control" name="Status" id="Status" required>
        <option value="Not Started" <?php if ($task['Status'] === 'Not Started') echo 'selected'; ?>>Not Started</option>
        <option value="In Progress" <?php if ($task['Status'] === 'In Progress') echo 'selected'; ?>>In Progress</option>
        <option value="Done" <?php if ($task['Status'] === 'Done') echo 'selected'; ?>>Done</option>
      </select>
    </div>

    <div class="form-group">
      <label for="RAGStatus">RAG Status</label>
      <select class="form-control" name="RAGStatus" id="RAGStatus" required>
        <option value="Red"   <?php if ($task['RAGStatus'] === 'Red')   echo 'selected'; ?>>Red</option>
        <option value="Amber" <?php if ($task['RAGStatus'] === 'Amber') echo 'selected'; ?>>Amber</option>
        <option value="Green" <?php if ($task['RAGStatus'] === 'Green') echo 'selected'; ?>>Green</option>
      </select>
    </div>

    <button type="submit" class="btn btn-primary">Update Task</button>
    <a href="view_task.php?TaskID=<?= $taskID ?>" class="btn btn-secondary">Cancel</a>
  </form>
</div>

<!-- Include your footer if needed -->
<?php include '../footer.php'; ?>
</body>
</html>
