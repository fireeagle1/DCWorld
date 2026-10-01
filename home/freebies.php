<?php
session_start();
require '../config.php';
require '../auth.php';


// Folder for uploaded images – ensure it is writeable by the web server
$uploadDir = '/home/xohpwhmm/assets.dcworld.uk/images/freebies/';

// Process form submission for adding a new freebie
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_freebie'])) {
    // Get posted values (all required except Constraints)
    $contactID   = (int)$_POST['contact_id'];
    $shortDesc   = trim($_POST['short_desc']);
    $longDesc    = trim($_POST['long_desc']);
    $category    = trim($_POST['category']);
    $timeLimit   = $_POST['time_limit']; // 'Yes' or 'No'
    // Convert deadline if provided and if timeLimit is "Yes"
    if ($timeLimit === 'Yes' && !empty($_POST['deadline'])) {
        $deadline = str_replace("T", " ", $_POST['deadline']);
    } else {
        $deadline = NULL;
    }
    $constraints = trim($_POST['constraints']); // optional
    $status      = trim($_POST['status']); // Offered, etc.
    $enteredUserID = $_SESSION['userID'];
    
    // Process image upload (required)
    $imageOfItem = '';
    if (isset($_FILES['image_item']) && $_FILES['image_item']['error'] == UPLOAD_ERR_OK) {
        $fileName = basename($_FILES['image_item']['name']);
        $targetFile = $uploadDir . $fileName;
        if (move_uploaded_file($_FILES['image_item']['tmp_name'], $targetFile)) {
            // Store relative path with https prefix
            $imageOfItem = 'https://assets.dcworld.uk/images/freebies/' . $fileName;
        } else {
            echo "<script>alert('Error uploading file');</script>";
        }
    } else {
        echo "<script>alert('Please upload an image');</script>";
    }
    
    // Insert new freebie record
    $stmt = $link->prepare("INSERT INTO DC_Freebies 
        (ContactID, ShortDesc, LongDesc, ImageOfItem, Category, TimeLimit, Deadline, Constraints, Status, EnteredUserID)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isssssissi", 
        $contactID, 
        $shortDesc, 
        $longDesc, 
        $imageOfItem, 
        $category, 
        $timeLimit, 
        $deadline, 
        $constraints, 
        $status, 
        $enteredUserID
    );
    
    if ($stmt->execute()) {
        header("Location: freebies.php");
        exit();
    } else {
        echo "<script>alert('Error: " . $stmt->error . "');</script>";
    }
}

// Fetch freebies for display with updated contact name formatting
$sql = "SELECT f.*, CONCAT(c.KnownAs, ' (', c.FirstName, ' ', c.LastName, ')') AS ContactName 
        FROM DC_Freebies f 
        LEFT JOIN Contacts c ON f.ContactID = c.ContactID
        ORDER BY f.EnteredTime DESC";
$result = $link->query($sql);

// Fetch contacts for dropdown with desired format
$sqlContacts = "SELECT ContactID, CONCAT(KnownAs, ' (', FirstName, ' ', LastName, ')') AS ContactName 
                FROM Contacts 
                ORDER BY ContactName";
$resultContacts = $link->query($sqlContacts);
$contacts = [];
while ($row = $resultContacts->fetch_assoc()) {
    $contacts[] = $row;
}
$resultContacts->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Freebies Offers</title>
  <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
  <!-- Font Awesome for icons -->
  <link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.15.4/css/all.css">
  <style>
    .freebie-img {
        width: 80px;
        height: 80px;
        object-fit: cover;
        border: 1px solid #ccc;
    }
    .magnifier {
        cursor: pointer;
        color: #007bff;
    }
  </style>
</head>
<body>
<?php include '../header.php'; ?>
<?php include 'subheader.php'; ?>

<div class="container mt-4">
  <h1>Freebies Offers</h1>
  
  <!-- Button to trigger Add Freebie Modal -->
  <button type="button" class="btn btn-primary mb-3" data-toggle="modal" data-target="#freebieModal">
    Add New Freebie
  </button>
  
  <!-- Freebies Table -->
  <table class="table table-bordered table-striped">
    <thead class="thead-dark">
      <tr>
        <th>Image</th>
        <th>Short Description</th>
        <th>Category</th>
        <th>Status</th>
        <th>Contact</th>
        <th>Entered</th>
        <th><!-- Details --></th>
      </tr>
    </thead>
    <tbody>
      <?php while($freebie = $result->fetch_assoc()): ?>
      <tr>
        <td>
          <?php if(!empty($freebie['ImageOfItem'])): ?>
            <img src="<?= htmlspecialchars($freebie['ImageOfItem'] ?? '') ?>" alt="Freebie Image" class="freebie-img">
          <?php else: ?>
            <i class="fas fa-image fa-2x text-muted"></i>
          <?php endif; ?>
        </td>
        <td><?= htmlspecialchars($freebie['ShortDesc'] ?? '') ?></td>
        <td><?= htmlspecialchars($freebie['Category'] ?? '') ?></td>
        <td><?= htmlspecialchars($freebie['Status'] ?? '') ?></td>
        <td><?= htmlspecialchars($freebie['ContactName'] ?? '') ?></td>
        <td><?= htmlspecialchars($freebie['EnteredTime'] ?? '') ?></td>
        <td class="text-center">
          <!-- Link to view details page -->
          <a href="freebie_details.php?id=<?= $freebie['FreebiesID'] ?>" class="btn btn-sm btn-info">
            <i class="fas fa-search"></i>
          </a>
        </td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>

<!-- Modal for Adding New Freebie -->
<div class="modal fade" id="freebieModal" tabindex="-1" role="dialog" aria-labelledby="freebieModalLabel" aria-hidden="true">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <form method="POST" enctype="multipart/form-data">
      <div class="modal-header">
        <h5 class="modal-title" id="freebieModalLabel">Add New Freebie</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
          <!-- Contact -->
          <div class="form-group">
              <label for="contact_id">Contact:</label>
              <select name="contact_id" id="contact_id" class="form-control" required>
                  <option value="">-- Select Contact --</option>
                  <?php foreach ($contacts as $contact): ?>
                      <option value="<?= $contact['ContactID'] ?>"><?= htmlspecialchars($contact['ContactName'] ?? '') ?></option>
                  <?php endforeach; ?>
              </select>
          </div>
          <!-- Short Description -->
          <div class="form-group">
              <label for="short_desc">Short Description:</label>
              <input type="text" name="short_desc" id="short_desc" class="form-control" required>
          </div>
          <!-- Long Description -->
          <div class="form-group">
              <label for="long_desc">Long Description:</label>
              <textarea name="long_desc" id="long_desc" class="form-control" required></textarea>
          </div>
          <!-- Image Upload (required) -->
          <div class="form-group">
              <label for="image_item">Image (Upload):</label>
              <input type="file" name="image_item" id="image_item" class="form-control-file" required>
          </div>
          <!-- Category Dropdown -->
          <div class="form-group">
              <label for="category">Category:</label>
              <select name="category" id="category" class="form-control" required>
                  <option value="">-- Select Category --</option>
                  <option value="Furniture">Furniture</option>
                  <option value="White Goods">White Goods</option>
                  <option value="Tools">Tools</option>
                  <option value="Bedding">Bedding</option>
                  <!-- Add other categories as needed -->
              </select>
          </div>
          <!-- Time Limit -->
          <div class="form-group">
              <label for="time_limit">Time Limit:</label>
              <select name="time_limit" id="time_limit" class="form-control" required>
                  <option value="No" selected>No</option>
                  <option value="Yes">Yes</option>
              </select>
          </div>
          <!-- Deadline: Shown only if Time Limit is Yes -->
          <div class="form-group" id="deadline_section" style="display:none;">
              <label for="deadline">Deadline:</label>
              <input type="datetime-local" name="deadline" id="deadline" class="form-control">
          </div>
          <!-- Constraints (optional) -->
          <div class="form-group">
              <label for="constraints">Constraints (optional):</label>
              <textarea name="constraints" id="constraints" class="form-control"></textarea>
          </div>
          <!-- Status -->
          <div class="form-group">
              <label for="status">Status:</label>
              <select name="status" id="status" class="form-control" required>
                  <option value="Offered" selected>Offered</option>
                  <option value="Offer Rejected">Offer Rejected</option>
                  <option value="Offer Accepted - Pending Collection">Offer Accepted - Pending Collection</option>
                  <option value="Offer Accepted - Collected">Offer Accepted - Collected</option>
              </select>
          </div>
      </div>
      <div class="modal-footer">
          <input type="hidden" name="add_freebie" value="1">
          <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-success">Save Freebie</button>
      </div>
      </form>
    </div>
  </div>
</div>

<?php include '../footer.php'; ?>

<!-- Required JS -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.3/dist/umd/popper.min.js"></script>
<script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
<script>
  // Show deadline field only if Time Limit is Yes
  $('#time_limit').on('change', function(){
    if($(this).val() === 'Yes'){
      $('#deadline_section').show();
      $('#deadline').attr('required', true);
    } else {
      $('#deadline_section').hide();
      $('#deadline').removeAttr('required');
    }
  });
</script>
</body>
</html>
