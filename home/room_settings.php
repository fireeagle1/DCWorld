<?php
session_start();
require '../config.php';
require '../auth.php';


// Handle save POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $RoomID = $_POST['RoomID'] ?? null;
    $Name = $_POST['Name'];
    $KnownAs = $_POST['KnownAs'];
    $Capacity = (int)$_POST['Capacity'];
    $AmenitiesRaw = $_POST['Amenities'] ?? '';
    $AmenitiesJSON = json_encode(array_map('trim', explode(',', $AmenitiesRaw)));

    if ($RoomID) {
        $stmt = $link->prepare("UPDATE HouseLocations SET Name=?, KnownAs=?, Capacity=?, Amenities=? WHERE RoomID=?");
        $stmt->bind_param("ssisi", $Name, $KnownAs, $Capacity, $AmenitiesJSON, $RoomID);
    } else {
        $stmt = $link->prepare("INSERT INTO HouseLocations (Name, KnownAs, Capacity, Amenities) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssis", $Name, $KnownAs, $Capacity, $AmenitiesJSON);
    }

    $stmt->execute();
    $stmt->close();

    header("Location: room_settings.php");
    exit();
}

// Step 1: Get rooms
$rooms = [];
$sql = "SELECT * FROM HouseLocations";
$result = $link->query($sql);
while ($row = $result->fetch_assoc()) {
    $row['Amenities'] = json_decode($row['Amenities'], true);
    $row['total_guests'] = 0; // default until counted
    $rooms[$row['RoomID']] = $row;
}

// Step 2: Get bookings and count guests per room
$bookingSQL = "SELECT RoomID, GuestsJSON FROM Bookings";
$bookingResult = $link->query($bookingSQL);
while ($b = $bookingResult->fetch_assoc()) {
    $guests = json_decode($b['GuestsJSON'], true);
    $count = isset($guests['guests']) && is_array($guests['guests'])
        ? count(array_filter($guests['guests']))
        : 0;

    if (isset($rooms[$b['RoomID']])) {
        $rooms[$b['RoomID']]['total_guests'] += $count;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Manage Room Settings</title>
  <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <style>
    .room-card {
      border: 1px solid #ccc;
      border-radius: 10px;
      padding: 15px;
      margin-bottom: 15px;
      cursor: pointer;
      background-color: #f9f9f9;
    }
    .room-card:hover {
      background-color: #f1f1f1;
    }
  </style>
</head>
<body>
<?php include '../header.php'; ?>
<?php include 'subheader.php'; ?>

<div class="container mt-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Room Settings</h2>
    <button class="btn btn-primary" data-toggle="modal" data-target="#roomModal" onclick="openRoomModal()">+ Add Room</button>
  </div>

  <div class="row">
    <?php foreach ($rooms as $room): ?>
      <div class="col-md-4">
        <div class="room-card" onclick='openRoomModal(<?= json_encode($room) ?>)'>
          <h5><?= htmlspecialchars($room['Name']) ?> (<?= htmlspecialchars($room['KnownAs']) ?>)</h5>
          <p><strong>Capacity:</strong> <?= $room['Capacity'] ?></p>
          <p><strong>Total Guests Hosted:</strong> <?= $room['total_guests'] ?></p>
          <p><strong>Amenities:</strong> <?= implode(', ', $room['Amenities'] ?? []) ?></p>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="roomModal" tabindex="-1" aria-labelledby="roomModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <form class="modal-content" method="post" action="">
      <div class="modal-header">
        <h5 class="modal-title" id="roomModalLabel">Add/Edit Room</h5>
        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="RoomID" id="RoomID">
        <div class="form-group">
          <label for="Name">Room Name</label>
          <input type="text" class="form-control" name="Name" id="Name" required>
        </div>
        <div class="form-group">
          <label for="KnownAs">Nickname</label>
          <input type="text" class="form-control" name="KnownAs" id="KnownAs">
        </div>
        <div class="form-group">
          <label for="Capacity">Capacity</label>
          <input type="number" class="form-control" name="Capacity" id="Capacity" min="1">
        </div>
        <div class="form-group">
          <label for="Amenities">Amenities (comma-separated)</label>
          <input type="text" class="form-control" name="Amenities" id="Amenities">
        </div>
      </div>
      <div class="modal-footer">
        <button type="submit" class="btn btn-success">Save Room</button>
        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script>
  function openRoomModal(room = null) {
    if (room) {
      $('#RoomID').val(room.RoomID);
      $('#Name').val(room.Name);
      $('#KnownAs').val(room.KnownAs);
      $('#Capacity').val(room.Capacity);
      $('#Amenities').val((room.Amenities || []).join(', '));
    } else {
      $('#RoomID, #Name, #KnownAs, #Capacity, #Amenities').val('');
    }
    $('#roomModal').modal('show');
  }
</script>
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.bundle.min.js"></script>
<?php include '../footer.php'; ?>
</body>
</html>
