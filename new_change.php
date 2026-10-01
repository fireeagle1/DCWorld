<?php
session_start();
require 'config.php';
require 'auth.php';


// Fetch the logged-in user's information
$userID = $_SESSION['userID'];
$sqlUser = "SELECT Name, IMGURL FROM DC_Users WHERE UserID = ?";
$stmtUser = $link->prepare($sqlUser);
$stmtUser->bind_param("i", $userID);
$stmtUser->execute();
$stmtUser->bind_result($Name, $IMGURL);
$stmtUser->fetch();
$stmtUser->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $description = $_POST['description'];
    $datePlanned = $_POST['date_planned'];
    $comments = $_POST['comments'];
    $majorOrMinor = $_POST['major_or_minor'];

    $sqlInsertChange = "INSERT INTO DCChanges (UserID, Description, DatePlanned, Comments, ChangeComplete, MajorOrMinor) VALUES (?, ?, ?, ?, 'No', ?)";
    $stmtInsertChange = $link->prepare($sqlInsertChange);
    $stmtInsertChange->bind_param("issss", $userID, $description, $datePlanned, $comments, $majorOrMinor);

    if ($stmtInsertChange->execute()) {
        header("Location: admin.php");
        exit();
    } else {
        $errorMessage = "Error creating new change.";
    }

    $stmtInsertChange->close();
}

$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Change</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .card-header {
            background-color: #007ea7;
            color: #000;
        }
        .btn-save {
            background-color: #007ea7;
            color: #fff;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container mt-4">
        <h1>New Change</h1>
        <div class="card mb-3">
            <div class="card-header">Create a New Planned Change</div>
            <div class="card-body">
                <?php if (isset($errorMessage)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($errorMessage); ?></div>
                <?php endif; ?>
                <form method="post">
                    <div class="form-group">
                        <label for="description">Description</label>
                        <input type="text" class="form-control" id="description" name="description" required>
                    </div>
                    <div class="form-group">
                        <label for="date_planned">Date Planned</label>
                        <input type="date" class="form-control" id="date_planned" name="date_planned" required>
                    </div>
                    <div class="form-group">
                        <label for="comments">Comments</label>
                        <textarea class="form-control" id="comments" name="comments" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="major_or_minor">Major or Minor</label>
                        <select class="form-control" id="major_or_minor" name="major_or_minor" required>
                            <option value="Major">Major</option>
                            <option value="Minor">Minor</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-save">Save Change</button>
                    <a href="admin.php" class="btn btn-secondary">Cancel</a>
                </form>
            </div>
        </div>
    </div>

   <?php include 'footer.php'; ?>

</body>
</html>
