<?php
require 'config.php'; // Ensure you have a config file with your database connection details

require 'auth.php';


// Fetch all meals with user names instead of user IDs
$sql = "SELECT m.MealID, m.MealShortName, u.Name as AddedByName, m.MinsToMake, m.ApproxCost, m.Tags 
        FROM DCMeals m 
        JOIN DC_Users u ON m.AddedbyUserID = u.UserID";
$result = $link->query($sql);

$meals = [];
while($row = $result->fetch_assoc()) {
    $meals[] = $row;
}

$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Meal Management</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container">
        <h2>Meals</h2>
        
        <div class="mb-3">
            <button class="btn btn-primary" onclick="window.location='add_meal.php'">Add Meal</button>
        </div>

        <input type="text" id="searchInput" class="form-control mb-3" placeholder="Search meals..." onkeyup="searchTable()">
        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th>Meal ID</th>
                    <th>Meal Short Name</th>
                    <th>Added by Name</th>
                    <th>Minutes to Make</th>
                    <th>Approx Cost</th>
                    <th>Tags</th>
                    <th>View Meal</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($meals as $meal): ?>
                <tr>
                    <td><?= htmlspecialchars($meal['MealID']) ?></td>
                    <td><?= htmlspecialchars($meal['MealShortName']) ?></td>
                    <td><?= htmlspecialchars($meal['AddedByName']) ?></td>
                    <td><?= htmlspecialchars($meal['MinsToMake']) ?></td>
                    <td><?= htmlspecialchars($meal['ApproxCost']) ?></td>
                    <td><?= htmlspecialchars($meal['Tags']) ?></td>
                    <td><button class="btn btn-info" onclick="window.location='view_meal.php?mealId=<?= $meal['MealID'] ?>'">View</button></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

 <?php include 'footer.php'; ?>

    <script>
        function searchTable() {
            var input, filter, table, tr, td, i, txtValue;
            input = document.getElementById("searchInput");
            filter = input.value.toUpperCase();
            table = document.querySelector("table");
            tr = table.getElementsByTagName("tr");

            for (i = 0; i < tr.length; i++) {
                td = tr[i].getElementsByTagName("td");
                if (td.length > 0) {
                    txtValue = "";
                    for (let j = 0; j < td.length; j++) {
                        txtValue += td[j].textContent || td[j].innerText;
                    }
                    if (txtValue.toUpperCase().indexOf(filter) > -1) {
                        tr[i].style.display = "";
                    } else {
                        tr[i].style.display = "none";
                    }
                }
            }
        }
    </script>
</body>
</html>
