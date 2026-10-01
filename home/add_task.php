<?php
session_start();
require '../config.php';

require '../auth.php';


// Function to fetch all users for the "Assigned To" dropdown
function getAllUsers($link) {
    $sqlUsers = "SELECT UserID, Name, Email FROM DC_Users ORDER BY Name";
    $resultUsers = $link->query($sqlUsers);
    $users = [];
    while ($row = $resultUsers->fetch_assoc()) {
        $users[] = $row;
    }
    $resultUsers->close();
    return $users;
}

// If the form is submitted:
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category   = $_POST['TaskCategory'];
    $startDate  = $_POST['StartDate'];
    $endDate    = $_POST['EndDate'];
    $assignedTo = $_POST['AssignedTo'];
    $shortDesc  = $_POST['ShortDesc'];
    $longDesc   = $_POST['LongDesc'];
    $status     = $_POST['Status'];
    $ragStatus  = $_POST['RAGStatus'];

    // Insert into DC_Tasks
    $sqlInsert = "INSERT INTO DC_Tasks
        (TaskCategory, StartDate, EndDate, AssignedTo, ShortDesc, LongDesc, Status, RAGStatus)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $link->prepare($sqlInsert);
    $stmt->bind_param("sssissss",
        $category, $startDate, $endDate, $assignedTo, $shortDesc, $longDesc, $status, $ragStatus
    );
    $stmt->execute();
    $newTaskID = $stmt->insert_id;  // Get the auto-generated TaskID if needed
    $stmt->close();

    // === Insert a new email log entry ===
    // 1. Fetch the assigned user's email
    $assignedUserEmail = '';
    $assignedUserName = '';
    $sqlUserEmail = "SELECT Name, Email FROM DC_Users WHERE UserID = ?";
    $stmtEmail = $link->prepare($sqlUserEmail);
    $stmtEmail->bind_param("i", $assignedTo);
    $stmtEmail->execute();
    $stmtEmail->bind_result($assignedUserName, $assignedUserEmail);
    $stmtEmail->fetch();
    $stmtEmail->close();

    // 2. Prepare subject & content
    $subject = "New Task Assigned: " . substr($shortDesc, 0, 50);
    $content = "Hello " . $assignedUserName . ",\n\n"
             . "A new task has been assigned to you:\n"
             . "Task: " . $shortDesc . "\n"
             . "Category: " . $category . "\n"
             . "Start Date: " . $startDate . "\n"
             . "End Date: " . $endDate . "\n\n"
             . "Please visit https://tyche.dcworld.uk/tasks.php for details.\n\n"
             . "Thank you,\nThe Dashboard";

    // 3. Insert into DCEmailsLog
    $sqlEmail = "INSERT INTO DCEmailsLog (`to`, Subject, Content, DateTimeSent, Sent)
                 VALUES (?, ?, ?, NOW(), 'No')";
    $stmtLog = $link->prepare($sqlEmail);
    $stmtLog->bind_param("sss", $assignedUserEmail, $subject, $content);
    $stmtLog->execute();
    $stmtLog->close();

    // Redirect back to tasks list
    header("Location: tasks.php");
    exit();
}

// Fetch users for the dropdown
$users = getAllUsers($link);
$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Add Task</title>
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
<?php include '../header.php'; ?>
  <?php include 'subheader.php'; ?>

  <div class="container mt-4">
    <h1>Add New Task</h1>
    <form method="POST" action="add_task.php">
      <div class="form-group">
        <label for="TaskCategory">Task Category</label>
        <input type="text" class="form-control" name="TaskCategory" id="TaskCategory" required>
      </div>

      <div class="form-group">
        <label for="ShortDesc">Short Description</label>
        <input type="text" class="form-control" name="ShortDesc" id="ShortDesc" required>
      </div>

      <div class="form-group">
        <label for="LongDesc">Long Description</label>
        <textarea class="form-control" name="LongDesc" id="LongDesc" rows="3"></textarea>
      </div>

      <div class="form-group">
        <label for="AssignedTo">Assigned To</label>
        <select class="form-control" name="AssignedTo" id="AssignedTo" required>
          <option value="">--Select User--</option>
          <?php foreach ($users as $user): ?>
            <option value="<?= $user['UserID'] ?>"><?= htmlspecialchars($user['Name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label for="StartDate">Start Date</label>
        <input type="date" class="form-control" name="StartDate" id="StartDate" required>
      </div>

      <div class="form-group">
        <label for="EndDate">End Date</label>
        <input type="date" class="form-control" name="EndDate" id="EndDate" required>
      </div>

      <div class="form-group">
        <label for="Status">Status</label>
        <select class="form-control" name="Status" id="Status" required>
          <option value="Not Started">Not Started</option>
          <option value="In Progress">In Progress</option>
          <option value="Done">Done</option>
        </select>
      </div>

      <div class="form-group">
        <label for="RAGStatus">RAG Status</label>
        <select class="form-control" name="RAGStatus" id="RAGStatus" required>
          <option value="Red">Red</option>
          <option value="Amber">Amber</option>
          <option value="Green">Green</option>
        </select>
      </div>

      <button type="submit" class="btn btn-primary">Add Task</button>
      <a href="tasks.php" class="btn btn-secondary">Cancel</a>
    </form>
  </div>

  <?php include '../footer.php'; ?>
</body>
</html>
