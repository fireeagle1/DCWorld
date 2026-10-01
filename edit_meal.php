<?php
require 'config.php';
session_start();

require 'auth.php';

if (!isset($_GET['mealId'])) {
    die("Meal ID is required.");
}

$mealId = $_GET['mealId'];

// Fetch the meal details
$sql = "SELECT m.*, u.Name as AddedByName
        FROM DCMeals m
        JOIN DC_Users u ON m.AddedbyUserID = u.UserID
        WHERE m.MealID = ?";
$stmt = $link->prepare($sql);
$stmt->bind_param("i", $mealId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    die("No meal found with ID: " . htmlspecialchars($mealId));
}

$meal = $result->fetch_assoc();
$ingredients = json_decode($meal['Ingredients'], true) ?: [];  // Decode the JSON into an array

// Check for form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $mealShortName = $_POST['MealShortName'];
    $ingredients = json_encode(array_filter($_POST['Ingredients']));  // Re-encode the filtered array back to JSON
    $linkToRecipe = $_POST['LinkToRecipe'];
    $instructions = $_POST['Instructions'];
    $minsToMake = $_POST['MinsToMake'];
    $approxCost = $_POST['ApproxCost'];
    $tags = $_POST['Tags'];
    $lastUpdatedDate = date('Y-m-d H:i:s');
    $lastEditedUser = $_SESSION['userID'];

    $updateSql = "UPDATE DCMeals SET
                  MealShortName = ?, Ingredients = ?, LinkToRecipe = ?, Instructions = ?,
                  MinsToMake = ?, ApproxCost = ?, Tags = ?, LastUpdatedDate = ?, LastEditedUser = ?
                  WHERE MealID = ?";
    $updateStmt = $link->prepare($updateSql);
    $updateStmt->bind_param("ssssisssii", $mealShortName, $ingredients, $linkToRecipe, $instructions,
                            $minsToMake, $approxCost, $tags, $lastUpdatedDate, $lastEditedUser, $mealId);
    if ($updateStmt->execute()) {
        header("Location: view_meal.php?mealId=$mealId"); // Redirect to view page
        exit();
    } else {
        echo "Error updating record: " . $link->error;
    }
}

$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Meal</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .profile-img {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            margin-right: 10px;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container mt-4">
        <h2>Edit Meal: <?= htmlspecialchars($meal['MealShortName']) ?></h2>
        <form action="edit_meal.php?mealId=<?= $mealId ?>" method="post">
            <div class="form-group">
                <label for="MealShortName">Meal Short Name</label>
                <input type="text" class="form-control" id="MealShortName" name="MealShortName" value="<?= htmlspecialchars($meal['MealShortName']) ?>" required>
            </div>
            <div class="form-group">
                <label for="Ingredients">Ingredients</label>
                <div id="ingredient-list">
                    <?php foreach ($ingredients as $ingredient): ?>
                    <div class="input-group mb-2">
                        <input type="text" class="form-control" name="Ingredients[]" value="<?= htmlspecialchars($ingredient) ?>">
                        <div class="input-group-append">
                            <button class="btn btn-outline-secondary remove-ingredient" type="button">&times;</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-primary" id="add-ingredient">Add Ingredient</button>
            </div>
            <div class="form-group">
                <label for="LinkToRecipe">Link to Recipe</label>
                <input type="text" class="form-control" id="LinkToRecipe" name="LinkToRecipe" value="<?= htmlspecialchars($meal['LinkToRecipe']) ?>">
            </div>
            <div class="form-group">
                <label for="Instructions">Instructions</label>
                <textarea class="form-control" id="Instructions" name="Instructions" rows="4"><?= htmlspecialchars($meal['Instructions']) ?></textarea>
            </div>
            <div class="form-group">
                <label for="MinsToMake">Minutes to Make</label>
                <input type of="number" class="form-control" id="MinsToMake" name="MinsToMake" value="<?= htmlspecialchars($meal['MinsToMake']) ?>">
            </div>
            <div class="form-group">
                <label for="ApproxCost">Approx Cost</label>
                <input type="text" class="form-control" id="ApproxCost" name="ApproxCost" value="<?= htmlspecialchars($meal['ApproxCost']) ?>">
            </div>
            <div class="form-group">
                <label for="Tags">Tags</label>
                <input type="text" class="form-control" id="Tags" name="Tags" value="<?= htmlspecialchars($meal['Tags']) ?>">
            </div>
            <button type="submit" class="btn btn-primary">Update Meal</button>
            <button type="button" class="btn btn-secondary" onclick="window.location='menu.php'">Cancel and Return to Menu</button>
        </form>
    </div>

    <?php include 'footer.php'; ?>

    <script>
        $(document).ready(function() {
            $('#add-ingredient').click(function() {
                $('#ingredient-list').append('<div class="input-group mb-2"><input type="text" class="form-control" name="Ingredients[]" placeholder="10 X Tomatoes"><div class="input-group-append"><button class="btn btn-outline-secondary remove-ingredient" type="button">&times;</button></div></div>');
            });

            $(document).on('click', '.remove-ingredient', function() {
                $(this).closest('.input-group').remove();
            });
        });
    </script>
</body>
</html>
