<?php
session_start();
require '../config.php';
require '../auth.php';


$userID = $_SESSION['userID'];

// Retrieve TaskID from the query string
$taskID = isset($_GET['TaskID']) ? (int)$_GET['TaskID'] : 0;
if ($taskID < 1) {
    header("Location: tasks.php");
    exit();
}

// -- Step 1: Fetch basic task info, to also get "AssignedTo" user ID --
$sqlTask = "
    SELECT t.*,
           u.Name AS AssignedUserName,
           u.Email AS AssignedUserEmail
    FROM DC_Tasks t
    LEFT JOIN DC_Users u ON t.AssignedTo = u.UserID
    WHERE t.TaskID = ?
";
$stmtTask = $link->prepare($sqlTask);
$stmtTask->bind_param("i", $taskID);
$stmtTask->execute();
$resultTask = $stmtTask->get_result();
$task = $resultTask->fetch_assoc();
$stmtTask->close();

if (!$task) {
    // Task not found
    header("Location: tasks.php");
    exit();
}

// Decode existing comments
$comments = $task['Comments'] ? json_decode($task['Comments'], true) : [];
if (!is_array($comments)) {
    $comments = [];
}

// If a new comment is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['newComment'])) {
    $newComment = trim($_POST['newComment']);
    
    if (!empty($newComment)) {
        // 1. Append the new comment in local array
        $comments[] = [
            "userID"  => $userID,
            "time"    => date('Y-m-d H:i:s'),
            "comment" => $newComment
        ];

        // 2. Update DC_Tasks.Comments in the database
        $updatedComments = json_encode($comments);
        $sqlUpdateComments = "UPDATE DC_Tasks SET Comments = ? WHERE TaskID = ?";
        $stmtUpdate = $link->prepare($sqlUpdateComments);
        $stmtUpdate->bind_param("si", $updatedComments, $taskID);
        $stmtUpdate->execute();
        $stmtUpdate->close();

        // 3. Email the "owner" (AssignedTo) that a new comment was added
        //    Make sure we have an email address
        if (!empty($task['AssignedUserEmail'])) {
            $ownerEmail = $task['AssignedUserEmail'];
            $ownerName  = $task['AssignedUserName'];
            
            // Build Subject & Content
            $subject = "New Comment on Task #{$task['TaskID']}";
            // We'll include just the new comment text or a short summary
            $emailContent = "Hello {$ownerName},\n\n"
                . "A new comment has been added to the task you own:\n"
                . "Task: {$task['ShortDesc']}\n"
                . "Comment: \"{$newComment}\"\n"
                . "Please visit https://tyche.dcworld.uk/home/view_task.php?TaskID={$task['TaskID']} to see all comments.\n\n"
                . "Regards,\nYour Dashboard";

            // Insert into DCEmailsLog (Sent='No' so cron will handle it)
            $sqlEmail = "
                INSERT INTO DCEmailsLog (`to`, Subject, Content, DateTimeSent, Sent)
                VALUES (?, ?, ?, NOW(), 'No')
            ";
            $stmtEmail = $link->prepare($sqlEmail);
            $stmtEmail->bind_param("sss", $ownerEmail, $subject, $emailContent);
            $stmtEmail->execute();
            $stmtEmail->close();
        }
    }

    // Redirect to avoid form re-submission on refresh
    header("Location: view_task.php?TaskID=$taskID");
    exit();
}

// -- Step 2: Build a dictionary of userID => userName for each comment posted --

// Gather unique userIDs from the comments array
$userIDs = array_column($comments, 'userID');
$userIDs = array_unique($userIDs);
$userNameMap = [];

// If we have any userIDs, fetch their names
if (count($userIDs) > 0) {
    $placeholders = rtrim(str_repeat('?,', count($userIDs)), ',');
    $types = str_repeat('i', count($userIDs));
    $sqlUserNames = "SELECT UserID, Name FROM DC_Users WHERE UserID IN ($placeholders)";
    $stmtUsers = $link->prepare($sqlUserNames);
    $stmtUsers->bind_param($types, ...$userIDs);
    $stmtUsers->execute();
    $resultUsers = $stmtUsers->get_result();
    while ($rowU = $resultUsers->fetch_assoc()) {
        $userNameMap[$rowU['UserID']] = $rowU['Name'];
    }
    $stmtUsers->close();
}

// Sort comments by time ascending
usort($comments, function($a, $b) {
    return strtotime($a['time']) - strtotime($b['time']);
});

$link->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>View Task</title>
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <style>
    .rag-badge {
      padding: 0.3rem 0.6rem;
      border-radius: 5px;
      color: #fff;
      font-size: 0.8rem;
    }
    .rag-red {
      background-color: #dc3545; /* red */
    }
    .rag-amber {
      background-color: #ffc107; /* amber */
      color: #212529;           /* dark text */
    }
    .rag-green {
      background-color: #28a745; /* green */
    }
    .comment-box {
      background: #f8f9fa;
      border-radius: 5px;
      padding: 0.75rem 1rem;
      margin-bottom: 0.5rem;
    }
    .comment-author {
      font-weight: bold;
    }
    .comment-time {
      font-size: 0.85rem;
      color: #6c757d;
      margin-left: 10px;
    }
  </style>
</head>
<body>

<?php include '../header.php'; ?>
<?php include 'subheader.php'; ?>

<div class="container mt-4">
  <h1>Task Details</h1>
  <div class="card mb-3">
    <div class="card-header">Basic Information</div>
    <div class="card-body">
      <p><strong>Task ID:</strong> <?= htmlspecialchars($task['TaskID']) ?></p>
      <p><strong>Category:</strong> <?= htmlspecialchars($task['TaskCategory']) ?></p>
      <p><strong>Short Desc:</strong> <?= htmlspecialchars($task['ShortDesc']) ?></p>
      <p><strong>Long Desc:</strong> <?= nl2br(htmlspecialchars($task['LongDesc'])) ?></p>
      <p><strong>Assigned To:</strong>
        <?= htmlspecialchars($task['AssignedUserName'] ?? 'Unknown') ?>
      </p>
      <p><strong>Start Date:</strong> <?= htmlspecialchars($task['StartDate']) ?></p>
      <p><strong>End Date:</strong> <?= htmlspecialchars($task['EndDate']) ?></p>
      <p><strong>Status:</strong> <?= htmlspecialchars($task['Status']) ?></p>
      <?php 
        $rag = strtolower($task['RAGStatus']);
        $ragClass = ($rag === 'red')
          ? 'rag-red'
          : (($rag === 'amber') ? 'rag-amber' : 'rag-green');
      ?>
      <p><strong>RAG Status:</strong> 
        <span class="rag-badge <?= $ragClass ?>"><?= htmlspecialchars($task['RAGStatus']) ?></span>
      </p>
    </div>
  </div>

  <!-- Comments Section -->
  <div class="card mb-3">
    <div class="card-header">Comments</div>
    <div class="card-body">
      <?php if (count($comments) === 0): ?>
        <p>No comments yet.</p>
      <?php else: ?>
        <?php foreach($comments as $comment): 
          $commentUserID = $comment['userID'];
          $commentAuthor = isset($userNameMap[$commentUserID]) 
                           ? $userNameMap[$commentUserID] 
                           : ("User #" . $commentUserID);
          $commentTime = $comment['time'];
          $commentText = $comment['comment'];
        ?>
          <div class="comment-box">
            <span class="comment-author"><?= htmlspecialchars($commentAuthor) ?></span>
            <span class="comment-time"><?= htmlspecialchars($commentTime) ?></span>
            <p class="mb-0"><?= nl2br(htmlspecialchars($commentText)) ?></p>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <!-- Add New Comment Form -->
      <form method="post" action="view_task.php?TaskID=<?= $taskID ?>" class="mt-3">
        <div class="form-group">
          <label for="newComment"><strong>Add a Comment:</strong></label>
          <textarea 
            name="newComment" 
            id="newComment" 
            rows="3" 
            class="form-control" 
            placeholder="Enter your comment here..."
          ></textarea>
        </div>
        <button type="submit" class="btn btn-primary mt-2">Submit Comment</button>
      </form>
    </div>
  </div>

  <!-- Navigation Buttons -->
  <a href="tasks.php" class="btn btn-secondary">Back to Tasks</a>
  <a href="edit_task.php?TaskID=<?= $task['TaskID'] ?>" class="btn btn-warning">Edit Task</a>
</div>

<?php include '../footer.php'; ?>
</body>
</html>
