<?php 
session_start();
require 'config.php';
require 'auth.php';


// Get the event ID from the URL
if (!isset($_GET['eventId'])) {
    die("No event specified.");
}
$eventId = intval($_GET['eventId']);

// Fetch event details for editing (including assigned user)
$sql = "SELECT EventTitle, EventLongDesc, StartDateTime, EndDateTime, Location, AllDay, userID 
        FROM Events 
        WHERE EventID = ?";
$stmt = $link->prepare($sql);
$stmt->bind_param("i", $eventId);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows == 0) {
    die("Event not found.");
}
$stmt->bind_result($eventTitle, $eventLongDesc, $startDateTime, $endDateTime, $location, $allDay, $eventUserID);
$stmt->fetch();
$stmt->close();

// Fetch list of users for the assignment dropdown
$users = [];
$sqlUsers = "SELECT UserID, Name FROM DC_Users ORDER BY Name ASC";
$resultUsers = $link->query($sqlUsers);
if ($resultUsers) {
    while ($row = $resultUsers->fetch_assoc()) {
        $users[] = $row;
    }
    $resultUsers->free();
}

// Initialize error message
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Delete event without checking mandatory fields
    if (isset($_POST['delete'])) {
        $sql = "DELETE FROM Events WHERE EventID = ?";
        $stmt = $link->prepare($sql);
        $stmt->bind_param("i", $eventId);
        $stmt->execute();
        $stmt->close();
        header("Location: calendar.php");
        exit();
    }
    
    // Update event details
    $eventTitle    = trim($_POST['EventTitle']);
    $eventDesc     = trim($_POST['EventLongDesc']);
    $startDateTime = trim($_POST['StartDateTime']);
    $endDateTime   = trim($_POST['EndDateTime']);
    $location      = trim($_POST['Location']);
    $allDay        = isset($_POST['AllDay']) ? 1 : 0;
    $assignedUser  = intval($_POST['AssignedUser']); // from dropdown

    // Validate required fields (only for update)
    if (empty($eventTitle) || empty($startDateTime) || empty($endDateTime) || empty($location)) {
        $errorMsg = "Please fill in all required fields.";
    } elseif (strtotime($endDateTime) <= strtotime($startDateTime)) {
        $errorMsg = "End date/time must be after start date/time.";
    } else {
        $sql = "UPDATE Events 
                SET EventTitle = ?, EventLongDesc = ?, StartDateTime = ?, EndDateTime = ?, Location = ?, AllDay = ?, userID = ? 
                WHERE EventID = ?";
        $stmt = $link->prepare($sql);
        $stmt->bind_param("sssssiii", $eventTitle, $eventDesc, $startDateTime, $endDateTime, $location, $allDay, $assignedUser, $eventId);
        $stmt->execute();
        $stmt->close();
        header("Location: view_event.php?eventId=" . $eventId);
        exit();
    }
}

// Convert date/time for input fields
$startDateTimeFormatted = date('Y-m-d\TH:i', strtotime($startDateTime));
$endDateTimeFormatted = date('Y-m-d\TH:i', strtotime($endDateTime));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edit Event</title>
  <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .note {
      font-size: 0.9rem;
      color: #555;
      margin-bottom: 15px;
    }
    /* Optional: additional styling to make the form look modern */
    .event-form {
      background: #fff;
      padding: 30px;
      border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      margin-top: 20px;
    }
    /* Ensure any header text for form remains legible */
    h1, h2 {
      color: #333;
    }
  </style>
</head>
<body>
  <?php include 'header.php'; ?>

  <div class="container mt-4">
    <h1>Edit Event</h1>
    <div class="note">
      Note: The background image for events is generated from our image bank. To change the default background, please visit <a href="/calendar-images.php">Calendar Image Bank</a>.
    </div>
    <?php if ($errorMsg) : ?>
      <div class="alert alert-danger"><?= htmlspecialchars($errorMsg); ?></div>
    <?php endif; ?>
    <div class="event-form">
      <form action="edit_event.php?eventId=<?= $eventId; ?>" method="post">
        <div class="form-group">
          <label for="EventTitle">Event Title</label>
          <input type="text" name="EventTitle" id="EventTitle" class="form-control" value="<?= htmlspecialchars($eventTitle); ?>" required>
        </div>
        <div class="form-group">
          <label for="EventLongDesc">Event Description</label>
          <textarea name="EventLongDesc" id="EventLongDesc" class="form-control"><?= htmlspecialchars($eventLongDesc); ?></textarea>
        </div>
        <div class="form-group">
          <label for="StartDateTime">Start Date &amp; Time</label>
          <input type="datetime-local" name="StartDateTime" id="StartDateTime" class="form-control" value="<?= $startDateTimeFormatted; ?>" required>
        </div>
        <div class="form-group">
          <label for="EndDateTime">End Date &amp; Time</label>
          <input type="datetime-local" name="EndDateTime" id="EndDateTime" class="form-control" value="<?= $endDateTimeFormatted; ?>" required>
        </div>
        <div class="form-group">
          <label for="Location">Location</label>
          <input type="text" name="Location" id="Location" class="form-control" value="<?= htmlspecialchars($location); ?>" required>
        </div>
        <div class="form-check mb-3">
          <input type="checkbox" name="AllDay" id="AllDay" class="form-check-input" <?= $allDay ? 'checked' : ''; ?>>
          <label class="form-check-label" for="AllDay">All Day Event</label>
        </div>
        <div class="form-group">
          <label for="AssignedUser">Assign Event To</label>
          <select name="AssignedUser" id="AssignedUser" class="form-control" required>
            <option value="0" <?= ($eventUserID == 0) ? 'selected' : ''; ?>>Joint Event</option>
            <?php foreach ($users as $user): ?>
              <option value="<?= $user['UserID']; ?>" <?= ($user['UserID'] == $eventUserID && $eventUserID != 0) ? 'selected' : ''; ?>>
                <?= htmlspecialchars($user['Name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" name="update" class="btn btn-primary">Save Changes</button>
        <button type="submit" name="delete" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this event? This action cannot be undone.');">Delete Event</button>
      </form>
    </div>
  </div>
  
  <script>
    document.getElementById('AllDay').addEventListener('change', function() {
      var startDateTime = document.getElementById('StartDateTime');
      var endDateTime = document.getElementById('EndDateTime');
      if (this.checked) {
          startDateTime.type = 'date';
          endDateTime.type = 'date';
      } else {
          startDateTime.type = 'datetime-local';
          endDateTime.type = 'datetime-local';
      }
    });
    // Trigger change event on page load to set the correct input type
    document.getElementById('AllDay').dispatchEvent(new Event('change'));
  </script>
  
  <?php include 'footer.php'; ?>
</body>
</html>
