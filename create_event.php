<?php 
session_start();
require 'config.php';
require 'auth.php';

// Fetch list of users for assignment dropdown
$users = [];
$sqlUsers = "SELECT UserID, Name FROM DC_Users ORDER BY Name ASC";
$resultUsers = $link->query($sqlUsers);
if ($resultUsers) {
    while ($row = $resultUsers->fetch_assoc()) {
        $users[] = $row;
    }
    $resultUsers->free();
}

// Initialize variables and error message
$errorMsg = '';
$eventTitle = $eventLongDesc = $startDateTime = $endDateTime = $location = "";
$allDay = 0;
$recurring = 0;
$recurrencePattern = "";
$recurrenceEnd = "";
$assignedUser = ""; // will store the selected user id; if "Joint Event" is chosen, this will be 0

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // If "Cancel" button was pressed, redirect immediately.
    if (isset($_POST['cancel'])) {
        header("Location: calendar.php");
        exit();
    }
    
    // Retrieve submitted data
    $eventTitle = trim($_POST['EventTitle']);
    $eventLongDesc = trim($_POST['EventLongDesc']);
    $startDateTime = trim($_POST['StartDateTime']);
    $endDateTime = trim($_POST['EndDateTime']);
    $location = trim($_POST['Location']);
    $allDay = isset($_POST['AllDay']) ? 1 : 0;
    $recurring = isset($_POST['Recurring']) ? 1 : 0;
    $recurrencePattern = isset($_POST['RecurrencePattern']) ? $_POST['RecurrencePattern'] : null;
    $recurrenceEnd = isset($_POST['RecurrenceEnd']) ? $_POST['RecurrenceEnd'] : null;
    $assignedUser = isset($_POST['AssignedUser']) ? intval($_POST['AssignedUser']) : 0;

    // Validate required fields
    if (empty($eventTitle) || empty($startDateTime) || empty($endDateTime) || empty($location)) {
        $errorMsg = "Please fill in all required fields.";
    } elseif (strtotime($endDateTime) <= strtotime($startDateTime)) {
        $errorMsg = "End date/time must be after start date/time.";
    }
    
    // If no error, proceed with insertion
    if (empty($errorMsg)) {
        if ($recurring) {
            $sql = "INSERT INTO RecurringEvents (EventTitle, EventLongDesc, StartTime, EndTime, Location, RecurrencePattern, RecurrenceEnd, AllDay, userID)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $link->prepare($sql);
            $stmt->bind_param("ssssssisi", 
                $eventTitle, 
                $eventLongDesc, 
                $startDateTime, 
                $endDateTime, 
                $location, 
                $recurrencePattern, 
                $recurrenceEnd, 
                $allDay, 
                $assignedUser
            );
        } else {
            $sql = "INSERT INTO Events (EventTitle, EventLongDesc, StartDateTime, EndDateTime, Location, AllDay, userID)
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $link->prepare($sql);
            $stmt->bind_param("sssssii", 
                $eventTitle, 
                $eventLongDesc, 
                $startDateTime, 
                $endDateTime, 
                $location, 
                $allDay, 
                $assignedUser
            );
        }
        if ($stmt->execute()) {
            $stmt->close();
            $link->close();
            header("Location: calendar.php");
            exit();
        } else {
            $errorMsg = "Database error: " . $stmt->error;
            $stmt->close();
        }
    }
    $link->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create Event</title>
  <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
  <style>
    .event-form {
      background: #fff;
      padding: 30px;
      border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      margin-top: 20px;
    }
    .note {
      font-size: 0.9rem;
      color: #555;
      margin-bottom: 15px;
    }
  </style>
</head>
<body>
  <?php include 'header.php'; ?>
  <div class="container mt-4">
    <h1>Create Event</h1>
    <div class="note">
      Note: The background image for events is generated from our image bank. To change the default backgrounds, please visit <a href="/calendar-images.php">Calendar Image Bank</a>.
    </div>
    <?php if (!empty($errorMsg)) : ?>
      <div class="alert alert-danger"><?= htmlspecialchars($errorMsg); ?></div>
    <?php endif; ?>
    <div class="event-form">
      <form action="create_event.php" method="post">
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
          <input type="datetime-local" name="StartDateTime" class="form-control" id="startDateTime" required>
        </div>
        <div class="form-group">
          <label for="EndDateTime">End Date &amp; Time</label>
          <input type="datetime-local" name="EndDateTime" class="form-control" id="endDateTime" required>
        </div>
        <div class="form-group">
          <label for="Location">Location</label>
          <input type="text" name="Location" id="Location" class="form-control" required>
        </div>
        <div class="form-check mb-3">
          <input type="checkbox" name="AllDay" id="allDayCheck" class="form-check-input">
          <label class="form-check-label" for="allDayCheck">All Day Event</label>
        </div>
        <div class="form-check mb-3">
          <input type="checkbox" name="Recurring" id="recurringCheck" class="form-check-input">
          <label class="form-check-label" for="recurringCheck">Recurring Event</label>
        </div>
        <div id="recurrenceFields" style="display: none;">
          <div class="form-group">
            <label for="RecurrencePattern">Recurrence Pattern</label>
            <select name="RecurrencePattern" id="RecurrencePattern" class="form-control">
              <option value="Daily">Daily</option>
              <option value="Weekly">Weekly</option>
              <option value="Monthly">Monthly</option>
              <option value="Annual">Annual</option>
            </select>
          </div>
          <div class="form-group">
            <label for="RecurrenceEnd">Recurrence End Date</label>
            <input type="date" name="RecurrenceEnd" id="RecurrenceEnd" class="form-control">
          </div>
        </div>
        <div class="form-group">
          <label for="AssignedUser">Assign Event To</label>
          <select name="AssignedUser" id="AssignedUser" class="form-control" required>
            <option value="0">Joint Event</option>
            <?php foreach ($users as $user): ?>
              <option value="<?= $user['UserID']; ?>" <?= (isset($assignedUser) && $user['UserID'] == $assignedUser) ? 'selected' : ''; ?>>
                <?= htmlspecialchars($user['Name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="submit" class="btn btn-primary" name="update">Create Event</button>
        <button type="submit" name="cancel" class="btn btn-secondary">Cancel</button>
      </form>
    </div>
  </div>
  
  <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
  <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
  <script>
    document.getElementById('recurringCheck').addEventListener('change', function() {
      var recurrenceFields = document.getElementById('recurrenceFields');
      recurrenceFields.style.display = this.checked ? 'block' : 'none';
    });
    document.getElementById('allDayCheck').addEventListener('change', function() {
      var startDateTime = document.getElementById('startDateTime');
      var endDateTime = document.getElementById('endDateTime');
      if (this.checked) {
          startDateTime.type = 'date';
          endDateTime.type = 'date';
      } else {
          startDateTime.type = 'datetime-local';
          endDateTime.type = 'datetime-local';
      }
    });
  </script>
  <?php include 'footer.php'; ?>
</body>
</html>
