<?php
require 'config.php';
session_start();
require 'auth.php';


if (!isset($_GET['mealId'])) {
    echo "Meal ID is required.";
    exit;
}

$mealId = $_GET['mealId'];

// Fetch the meal details including user information
$sql = "SELECT m.*, 
               u1.Name as AddedByName, u1.IMGURL as AddedByImg,
               u2.Name as LastEditedByName
        FROM DCMeals m 
        JOIN DC_Users u1 ON m.AddedbyUserID = u1.UserID
        LEFT JOIN DC_Users u2 ON m.LastEditedUser = u2.UserID
        WHERE m.MealID = ?";
$stmt = $link->prepare($sql);
$stmt->bind_param("i", $mealId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    echo "No meal found with ID: " . htmlspecialchars($mealId);
    exit;
}

$meal = $result->fetch_assoc();

$ingredients = json_decode($meal['Ingredients'], true); // Decode the JSON-encoded ingredients

$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Meal</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .profile-img {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            margin-right: 10px;
        }
        .preserve-spaces {
            white-space: pre-wrap; /* CSS for preserving whitespace and line breaks */
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container mt-4">
        <div class="row"> 
            <div class="col-md-8">
                
                <h2><?= htmlspecialchars($meal['MealShortName']) ?></h2>
                <p><strong>Ingredients:</strong></p>
                <ul>
                <?php foreach ($ingredients as $ingredient): ?>
                    <li><?= htmlspecialchars($ingredient) ?></li>
                <?php endforeach; ?>
                </ul>
                <p><strong>Link to Recipe:</strong> <a href="<?= htmlspecialchars($meal['LinkToRecipe']) ?>"><?= htmlspecialchars($meal['LinkToRecipe']) ?></a></p>
                <p><strong>Instructions:</strong> <span class="preserve-spaces"><?= htmlspecialchars($meal['Instructions']) ?></span></p>
            </div>
            <div class="col-md-4">
                <div class="card text-white bg-secondary mb-3">
                    <div class="card-header">Information </div>
                    <div class="card-body">
                                            <button class="btn btn-primary float-right" onclick="window.location='edit_meal.php?mealId=<?= htmlspecialchars($meal['MealID']) ?>'">Edit Recipe</button>

                        <p class="card-text">
                            <img src="<?= htmlspecialchars($meal['AddedByImg']) ?>" alt="User Image" class="profile-img">
                            <strong>Added by:</strong> <?= htmlspecialchars($meal['AddedByName']) ?> <br>
                            <strong>Last Edited by:</strong> <?= htmlspecialchars($meal['LastEditedByName']) ?> <br>
                            <strong>Last Updated:</strong> <?= date("F j, Y, g:i a", strtotime($meal['LastUpdatedDate'])) ?><br>
                            <strong>Date Added:</strong> <?= date("F j, Y, g:i a", strtotime($meal['DateAdded'])) ?><br>
                            <strong>Minutes to Make:</strong> <?= htmlspecialchars($meal['MinsToMake']) ?><br>
                            <strong>Approx Cost:</strong> <?= htmlspecialchars($meal['ApproxCost']) ?><br>
                            <strong>Tags:</strong> <?= htmlspecialchars($meal['Tags']) ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include 'footer.php'; ?>

</body>
</html>
