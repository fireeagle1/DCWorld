<?php
session_start();
require '../config.php';
require '../auth.php';


// Get freebie ID from the query string
if (!isset($_GET['id'])) {
    header("Location: freebies.php");
    exit();
}
$freebieID = (int)$_GET['id'];

// Process new comment submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_comment'])) {
    $newComment = trim($_POST['comment']);
    if (!empty($newComment)) {
        // Retrieve existing comments (as JSON) and decode into an array
        $stmt = $link->prepare("SELECT Comments FROM DC_Freebies WHERE FreebiesID = ?");
        $stmt->bind_param("i", $freebieID);
        $stmt->execute();
        $stmt->bind_result($commentsJSON);
        $stmt->fetch();
        $stmt->close();
        
        $commentsArray = [];
        if ($commentsJSON) {
            $commentsArray = json_decode($commentsJSON, true);
            if (!is_array($commentsArray)) {
                $commentsArray = [];
            }
        }
        // Append new comment with timestamp and user ID
        $commentsArray[] = [
            'comment' => $newComment,
            'userID' => $_SESSION['userID'],
            'timestamp' => date("Y-m-d H:i:s")
        ];
        $newCommentsJSON = json_encode($commentsArray);
        
        // Update the record
        $stmt = $link->prepare("UPDATE DC_Freebies SET Comments = ? WHERE FreebiesID = ?");
        $stmt->bind_param("si", $newCommentsJSON, $freebieID);
        $stmt->execute();
        $stmt->close();
        
        header("Location: freebie_details.php?id=" . $freebieID);
        exit();
    }
}

// Fetch freebie details with updated contact name formatting
$stmt = $link->prepare("SELECT f.*, CONCAT(c.KnownAs, ' (', c.FirstName, ' ', c.LastName, ')') AS ContactName 
                        FROM DC_Freebies f 
                        LEFT JOIN Contacts c ON f.ContactID = c.ContactID
                        WHERE f.FreebiesID = ?");
$stmt->bind_param("i", $freebieID);
$stmt->execute();
$result = $stmt->get_result();
$freebie = $result->fetch_assoc();
$stmt->close();

if (!$freebie) {
    echo "Freebie not found.";
    exit();
}

// Decode comments for display
$commentsArray = [];
if ($freebie['Comments']) {
    $commentsArray = json_decode($freebie['Comments'], true);
    if (!is_array($commentsArray)) {
        $commentsArray = [];
    }
}

// Build a lookup array for user names from DC_Users so we can display names in comments
$userLookup = [];
$userResult = $link->query("SELECT UserID, Name FROM DC_Users");
while ($uRow = $userResult->fetch_assoc()) {
    $userLookup[$uRow['UserID']] = $uRow['Name'];
}
$userResult->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Freebie Details</title>
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <!-- Font Awesome for icons -->
  <link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.15.4/css/all.css">
  <style>
    /* Limit displayed image size */
    .freebie-full-img {
        max-width: 200px;
        height: auto;
    }
  </style>
</head>
<body>
<?php include '../header.php'; ?>
<?php include 'subheader.php'; ?>

<div class="container mt-4">
  <h1>Freebie Details</h1>
  <div class="card">
    <div class="card-header">
      <?= htmlspecialchars($freebie['ShortDesc'] ?? '') ?>
    </div>
    <div class="card-body">
      <p><strong>Long Description:</strong> <?= htmlspecialchars($freebie['LongDesc'] ?? '') ?></p>
      <p><strong>Category:</strong> <?= htmlspecialchars($freebie['Category'] ?? '') ?></p>
      <p><strong>Time Limit:</strong> <?= htmlspecialchars($freebie['TimeLimit'] ?? '') ?></p>
      <?php if($freebie['TimeLimit'] === 'Yes'): ?>
      <p><strong>Deadline:</strong> <?= htmlspecialchars($freebie['Deadline'] ?? '') ?></p>
      <?php endif; ?>
      <p><strong>Status:</strong> <?= htmlspecialchars($freebie['Status'] ?? '') ?></p>
      <p><strong>Contact:</strong> <?= htmlspecialchars($freebie['ContactName'] ?? '') ?></p>
      <p><strong>Entered Time:</strong> <?= htmlspecialchars($freebie['EnteredTime'] ?? '') ?></p>
      <?php if(!empty($freebie['ImageOfItem'])): ?>
      <p><strong>Image:</strong></p>
      <!-- The image is limited in size, and clicking it opens the full image in a new tab -->
      <a href="<?= htmlspecialchars($freebie['ImageOfItem'] ?? '') ?>" target="_blank">
        <img src="<?= htmlspecialchars($freebie['ImageOfItem'] ?? '') ?>" alt="Freebie Image" class="freebie-full-img">
      </a>
      <?php endif; ?>
    </div>
  </div>
  
  <hr>
  
  <h3>Comments</h3>
  <?php if(count($commentsArray) > 0): ?>
    <?php foreach($commentsArray as $comment): ?>
      <div class="border p-2 mb-2">
        <p><?= htmlspecialchars($comment['comment'] ?? '') ?></p>
        <small>
          User: 
          <?php 
            $userID = $comment['userID'] ?? '';
            echo isset($userLookup[$userID]) ? htmlspecialchars($userLookup[$userID]) : htmlspecialchars($userID);
          ?>, 
          At: <?= htmlspecialchars($comment['timestamp'] ?? '') ?>
        </small>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p>No comments yet.</p>
  <?php endif; ?>
  
  <hr>
  
  <h3>Add Comment</h3>
  <form method="POST">
    <div class="form-group">
      <textarea name="comment" class="form-control" required placeholder="Enter your comment here"></textarea>
    </div>
    <input type="hidden" name="add_comment" value="1">
    <button type="submit" class="btn btn-primary">Submit Comment</button>
  </form>
  
  <br>
  <a href="freebies.php" class="btn btn-secondary">Back to Freebies List</a>
</div>

<?php include '../footer.php'; ?>

<!-- Required JS -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.3/dist/umd/popper.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>
