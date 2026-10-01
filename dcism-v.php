<?php
session_start();
require 'config.php';
require 'auth.php';


// Ensure the ID parameter is present and valid
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid Saying ID.");
}

$sayingID = intval($_GET['id']);

// Fetch the saying details from the database
$sqlSaying = "SELECT Saying, SaidBy, Context, DateAdded FROM FunnySayings WHERE SayingID = ?";
$stmt = $link->prepare($sqlSaying);
$stmt->bind_param('i', $sayingID);
$stmt->execute();
$stmt->bind_result($saying, $saidBy, $context, $dateAdded);
$stmt->fetch();
$stmt->close();

if (empty($saying)) {
    die("Saying not found.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Funny Saying</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: Arial, sans-serif; background-color: #f8f9fa; }
        .saying-container {
            max-width: 600px;
            margin: 50px auto;
            background-color: #ffffff;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .saying-header {
            text-align: center;
            margin-bottom: 20px;
        }
        .saying-header h1 { color: #007ea7; }
        .saying-details {
            margin-bottom: 20px;
        }
        .saying-details p {
            font-size: 1.2rem;
            margin: 10px 0;
        }
        .saying-details strong { color: #005b96; }
        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
        }
        .back-link a {
            color: #007ea7;
            text-decoration: none;
            font-weight: bold;
        }
        .back-link a:hover {
            color: #005b96;
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="saying-container">
        <div class="saying-header">
            <h1>Funny Saying</h1>
        </div>
        <div class="saying-details">
            <p><strong>Saying:</strong> <?= htmlspecialchars($saying); ?></p>
            <p><strong>Said By:</strong> <?= htmlspecialchars($saidBy ?: 'Unknown'); ?></p>
            <p><strong>Context:</strong> <?= htmlspecialchars($context ?: 'No context provided.'); ?></p>
            <p><strong>Date Added:</strong> <?= htmlspecialchars($dateAdded); ?></p>
        </div>
        <div class="back-link">
            <a href="dashboard.php">&laquo; Back to Dashboard</a>
        </div>
        <div class="back-link">
            <a href="dcism.php">&laquo; Add your own DC'ISM</a>
        </div>
    </div>

    <?php include 'footer.php'; ?>
</body>
</html>
