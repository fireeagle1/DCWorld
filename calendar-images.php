<?php
session_start();
require 'config.php';
include 'header.php';
require 'auth.php';


$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['brand_image']) && $_FILES['brand_image']['error'] === 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $fileName    = $_FILES['brand_image']['name'];
        $fileTmpName = $_FILES['brand_image']['tmp_name'];
        $fileExt     = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (in_array($fileExt, $allowed)) {
            // Define upload directory and create a unique filename
            $uploadDir   = '/home/xohpwhmm/assets.dcworld.uk/images/';
            $newFileName = uniqid('brand_', true) . '.' . $fileExt;
            $uploadPath  = $uploadDir . $newFileName;

            if (move_uploaded_file($fileTmpName, $uploadPath)) {
                // Construct the URL for the uploaded image
                $brandImageURL = "https://assets.dcworld.uk/images/" . $newFileName;
                $keywords = $link->real_escape_string(trim($_POST['keywords']));

                $sql = "INSERT INTO BrandImages (BrandImageURL, Keywords) VALUES (?, ?)";
                $stmt = $link->prepare($sql);
                $stmt->bind_param("ss", $brandImageURL, $keywords);
                if ($stmt->execute()) {
                    $msg = "Image uploaded and added successfully.";
                } else {
                    $msg = "Database error: " . $stmt->error;
                }
                $stmt->close();
            } else {
                $msg = "Error moving uploaded file.";
            }
        } else {
            $msg = "Invalid file type. Allowed types: " . implode(', ', $allowed);
        }
    } else {
        $msg = "No file uploaded or there was an upload error.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Add New Brand Image</title>
  <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
  <div class="container mt-4">
    <h1>Add New Brand Image</h1>
    <?php if ($msg) { echo '<div class="alert alert-info">' . $msg . '</div>'; } ?>
    <form action="" method="post" enctype="multipart/form-data">
      <div class="form-group">
        <label for="brand_image">Upload Image:</label>
        <input type="file" name="brand_image" id="brand_image" class="form-control" required>
      </div>
      <div class="form-group">
        <label for="keywords">Keywords (comma separated):</label>
        <input type="text" name="keywords" id="keywords" class="form-control" required>
      </div>
      <button type="submit" class="btn btn-primary">Upload Image</button>
    </form>
  </div>
</body>
</html>
<?php include 'footer.php'; ?>

