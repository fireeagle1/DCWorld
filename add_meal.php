<?php
require 'config.php';
session_start();

require 'auth.php';

$currentUserID = $_SESSION['userID'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mealShortName = $_POST['mealShortName'];
    $ingredients = json_encode($_POST['ingredients']); // JSON encoding the array of ingredients
    $linkToRecipe = $_POST['linkToRecipe'];
    $instructions = $_POST['instructions'];
    $addedByUserID = $_POST['addedByUserID'];
    $minsToMake = $_POST['minsToMake'];
    $approxCost = $_POST['approxCost'];
    $tags = $_POST['tags'];
    $dateAdded = date('Y-m-d H:i:s');
    $lastUpdatedDate = $dateAdded;
    $lastEditedUser = $addedByUserID;

    // SQL to insert new meal
    $sql = "INSERT INTO DCMeals (MealShortName, Ingredients, LinkToRecipe, Instructions, AddedbyUserID, MinsToMake, ApproxCost, Tags, DateAdded, LastUpdatedDate, LastEditedUser) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $link->prepare($sql);
    $stmt->bind_param("ssssiidsssi", $mealShortName, $ingredients, $linkToRecipe, $instructions, $addedByUserID, $minsToMake, $approxCost, $tags, $dateAdded, $lastUpdatedDate, $lastEditedUser);
    $stmt->execute();
    $newMealId = $stmt->insert_id;
    $stmt->close();
    $link->close();

    // Redirect to view_meal.php with the new meal ID
    header("Location: view_meal.php?mealId=" . $newMealId);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Meal</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container mt-4">
        <h2>Add a New Meal</h2>
        <form method="POST" action="add_meal.php" id="mealForm">
            <div class="form-group">
                <label for="mealShortName">Meal Short Name:</label>
                <input type="text" class="form-control" id="mealShortName" name="mealShortName" required>
            </div>
            <div class="form-group" id="ingredient-list">
                <label for="ingredients">Ingredients:</label>
                <button type="button" class="btn btn-info mb-2" id="addIngredient">Add Ingredient</button>
                <div class="input-group mb-3 ingredient-input">
                    <input type="text" class="form-control" name="ingredients[]" placeholder="10 X Tomatoes">
                    <div class="input-group-append">
                        <button class="btn btn-danger removeIngredient" type="button">Remove</button>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label for="linkToRecipe">Link to Recipe:</label>
                <input type="url" class="form-control" id="linkToRecipe" name="linkToRecipe">
            </div>
            <div class="form-group">
                <label for="instructions">Instructions:</label>
                <textarea class="form-control" id="instructions" name="instructions" required></textarea>
            </div>
            <div class="form-group">
                <label for="addedByUserID">Added by User ID:</label>
                <input type="hidden" class="form-control" id="addedByUserID" name="addedByUserID" value="<?= $currentUserID ?>" required>
            </div>
            <div class="form-group">
                <label for="minsToMake">Minutes to Make:</label>
                <input type="number" class="form-control" id="minsToMake" name="minsToMake">
            </div>
            <div class="form-group">
                <label for="approxCost">Approximate Cost:</label>
                <input type="number" class="form-control" id="approxCost" name="approxCost" step="0.01">
            </div>
            <div class="form-group">
                <label for="tags">Tags:</label>
                <input type="text" class="form-control" id="tags" name="tags">
            </div>
            <button type="submit" class="btn btn-primary">Submit</button>
        </form>
    </div>

    <script>
        // jQuery script to dynamically add or remove ingredient fields
        $(document).ready(function () {
            $("#addIngredient").click(function () {
                var newIngredient = `
                    <div class="input-group mb-3 ingredient-input">
                        <input type="text" class="form-control" name="ingredients[]" placeholder="10 X Tomatoes">
                        <div class="input-group-append">
                            <button class="btn btn-danger removeIngredient" type="button">Remove</button>
                        </div>
                    </div>`;
                $("#ingredient-list").append(newIngredient);
            });

            $(document).on('click', '.removeIngredient', function () {
                $(this).closest('.ingredient-input').remove();
            });
        });
    </script>

   <?php include 'footer.php'; ?>

</body>
</html>
