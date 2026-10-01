<?php
session_start();
require 'config.php';

require 'auth.php';


// Get the event ID from the URL (e.g., view_event.php?eventId=123)
if (!isset($_GET['eventId'])) {
    die("No event specified.");
}
$eventId = intval($_GET['eventId']);

// Fetch event details along with registered user's info
$sql = "SELECT e.EventTitle, e.EventLongDesc, e.StartDateTime, e.EndDateTime, e.Location, e.Source, e.userID, u.IMGURL, u.Name AS userName
        FROM Events e
        LEFT JOIN DC_Users u ON e.userID = u.UserID
        WHERE e.EventID = ?";
$stmt = $link->prepare($sql);
$stmt->bind_param("i", $eventId);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows == 0) {
    die("Event not found.");
}
$stmt->bind_result($eventTitle, $eventLongDesc, $startDateTime, $endDateTime, $location, $eventSource, $eventUserID, $eventUserIMG, $eventUserName);
$stmt->fetch();
$stmt->close();

// Query brand images for the banner background
$sqlBrand = "SELECT * FROM BrandImages";
$resultBrand = $link->query($sqlBrand);
$brandImages = [];
if ($resultBrand) {
    while ($row = $resultBrand->fetch_assoc()) {
        $brandImages[] = $row;
    }
    $resultBrand->free();
}
$link->close();

// Determine the banner background style
$bannerStyle = '';
foreach ($brandImages as $brand) {
    $keywords = explode(',', $brand['Keywords']);
    foreach ($keywords as $kw) {
        if (stripos($eventTitle, trim($kw)) !== false) {
            $bannerStyle = "background: url('" . htmlspecialchars($brand['BrandImageURL']) . "') no-repeat center center; background-size: cover;";
            break 2;
        }
    }
}
// If no keyword match, use default image (BrandImageID 1)
if (empty($bannerStyle)) {
    foreach ($brandImages as $brand) {
        if ($brand['brandimageID'] == 1) {
            $bannerStyle = "background: url('" . htmlspecialchars($brand['BrandImageURL']) . "') no-repeat center center; background-size: cover;";
            break;
        }
    }
}
// For DutySheet events, force background image to BrandImageID 2
if ($eventSource === 'DutySheet') {
    foreach ($brandImages as $brand) {
        if ($brand['brandimageID'] == 2) {
            $bannerStyle = "background: url('" . htmlspecialchars($brand['BrandImageURL']) . "') no-repeat center center; background-size: cover;";
            break;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>View Event</title>
  <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
  <style>
    /* Banner at the top */
    .event-banner {
      position: relative;
      height: 300px;
      <?= $bannerStyle ?>
    }
    .event-banner::after {
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0,0,0,0.6);
    }
    .event-banner .banner-content {
      position: relative;
      z-index: 2;
      color: #fff;
      text-align: center;
      padding-top: 100px;
    }
    /* Registered user image next to the event details title */
    .registered-user-title {
      margin-left: 15px;
    }
    .registered-user-title img {
      width: 80px;
      height: 80px;
      border-radius: 50%;
    }
    /* Modern event details styling */
    .event-details {
      background: #fff;
      padding: 30px;
      border-radius: 8px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      margin-top: -50px;
      position: relative;
      z-index: 3;
    }
    .event-details h2 {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
  </style>
</head>
<body>
  <?php include 'header.php'; ?>
  <!-- Banner Section -->
  <div class="event-banner">
    <div class="banner-content">
      <h1><?= htmlspecialchars($eventTitle); ?></h1>
    </div>
  </div>
  <!-- Event Details Section -->
  <div class="container event-details">
    <h2>
      <span>Event Details</span>
      <div class="registered-user-title">
        <?php
          // Show profile image next to the title:
          // If the event's userID is 0 (joint) or no valid image, show default.
          if ((int)$eventUserID === 0 || empty($eventUserIMG)) {
              echo '<img src="https://assets.dcworld.uk/images/GPTempDownload%206.JPG" alt="Default">';
          } else {
              echo '<img src="' . htmlspecialchars($eventUserIMG) . '" alt="' . htmlspecialchars($eventUserName) . '">';
          }
        ?>
      </div>
    </h2>
    <p><strong>Description:</strong> <?= nl2br(htmlspecialchars($eventLongDesc)); ?></p>
    <p><strong>Start:</strong> <?= date('l, jS F Y, g:i a', strtotime($startDateTime)); ?></p>
    <p><strong>End:</strong> <?= date('l, jS F Y, g:i a', strtotime($endDateTime)); ?></p>
    <p><strong>Location:</strong> <?= htmlspecialchars($location); ?></p>
    <?php if ($eventSource === 'DutySheet'): ?>
      <a href="https://secure.dutysheet.com/p6" class="btn btn-secondary mt-4" target="_blank">View on DutySheet</a>
    <?php else: ?>
      <a href="edit_event.php?eventId=<?= $eventId; ?>" class="btn btn-primary mt-4">Edit Event</a>
    <?php endif; ?>
  </div>
  <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
  <?php include 'footer.php'; ?>
</body>
</html>
