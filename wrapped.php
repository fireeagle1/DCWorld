<?php
session_start();
require 'config.php';
// auth.php must run BEFORE header.php. header.php sends HTML output immediately,
// and auth.php redirects logged-out users with header("Location: login.php"),
// which fails once output has started. Including the header first let the page
// render to unauthenticated visitors.
require 'auth.php';
require 'header.php';


// Fetch descriptive mappings for work and night locations
$workLocationMapping = [];
$nightLocationMapping = [];

// Fetch WorkLocation descriptions
$sqlWorkLocationDesc = "SELECT WorkID, WLocationDesc FROM DC_WorkLocation";
$resultWorkLocationDesc = $link->query($sqlWorkLocationDesc);
while ($row = $resultWorkLocationDesc->fetch_assoc()) {
    $workLocationMapping[$row['WorkID']] = $row['WLocationDesc'];
}

// Fetch NightLocation descriptions
$sqlNightLocationDesc = "SELECT LocationID, LocationDesc FROM DC_Locations";
$resultNightLocationDesc = $link->query($sqlNightLocationDesc);
while ($row = $resultNightLocationDesc->fetch_assoc()) {
    $nightLocationMapping[$row['LocationID']] = $row['LocationDesc'];
}

// Fetch work stats for Charlie
$sqlCharlieWorkStats = "
    SELECT CKWorkLocation, COUNT(*) AS count
    FROM DCDailyOpsPlan
    WHERE CKWorkLocation NOT IN (4, 6, 7, 8, 9)
    GROUP BY CKWorkLocation";
$resultCharlieWork = $link->query($sqlCharlieWorkStats);
$charlieWorkStats = [];
while ($row = $resultCharlieWork->fetch_assoc()) {
    $workDesc = $workLocationMapping[$row['CKWorkLocation']] ?? 'Unknown';
    $charlieWorkStats[$workDesc] = ($charlieWorkStats[$workDesc] ?? 0) + $row['count'];
}

// Fetch work stats for Daniel
$sqlDanielWorkStats = "
    SELECT DCWorkLocation, COUNT(*) AS count
    FROM DCDailyOpsPlan
    WHERE DCWorkLocation NOT IN (4, 6, 7, 8, 9)
    GROUP BY DCWorkLocation";
$resultDanielWork = $link->query($sqlDanielWorkStats);
$danielWorkStats = [];
while ($row = $resultDanielWork->fetch_assoc()) {
    $workDesc = $workLocationMapping[$row['DCWorkLocation']] ?? 'Unknown';
    $danielWorkStats[$workDesc] = ($danielWorkStats[$workDesc] ?? 0) + $row['count'];
}

// Fetch night stats for Charlie
$sqlCharlieNightStats = "
    SELECT CKLocation, COUNT(*) AS count
    FROM DCDailyOpsPlan
    WHERE CKLocation NOT IN (6, 7, 8)
    GROUP BY CKLocation";
$resultCharlieNight = $link->query($sqlCharlieNightStats);
$charlieNightStats = [];
while ($row = $resultCharlieNight->fetch_assoc()) {
    $nightDesc = $nightLocationMapping[$row['CKLocation']] ?? 'Unknown';
    $charlieNightStats[$nightDesc] = ($charlieNightStats[$nightDesc] ?? 0) + $row['count'];
}

// Fetch night stats for Daniel
$sqlDanielNightStats = "
    SELECT DCLocation, COUNT(*) AS count
    FROM DCDailyOpsPlan
    WHERE DCLocation NOT IN (6, 7, 8)
    GROUP BY DCLocation";
$resultDanielNight = $link->query($sqlDanielNightStats);
$danielNightStats = [];
while ($row = $resultDanielNight->fetch_assoc()) {
    $nightDesc = $nightLocationMapping[$row['DCLocation']] ?? 'Unknown';
    $danielNightStats[$nightDesc] = ($danielNightStats[$nightDesc] ?? 0) + $row['count'];
}

// Fetch shared nights
$sqlSharedNights = "
    SELECT CKLocation, COUNT(*) AS count
    FROM DCDailyOpsPlan
    WHERE CKLocation = DCLocation AND CKLocation NOT IN (6, 7, 8)
    GROUP BY CKLocation";
$resultSharedNights = $link->query($sqlSharedNights);
$sharedNights = [];
while ($row = $resultSharedNights->fetch_assoc()) {
    $sharedDesc = $nightLocationMapping[$row['CKLocation']] ?? 'Unknown';
    $sharedNights[$sharedDesc] = ($sharedNights[$sharedDesc] ?? 0) + $row['count'];
}

$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tyche Wrapped</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(-45deg, #2250e6, #97cdfd, #2250e6);
            background-size: 400% 400%;
            animation: gradient 10s ease infinite;
            color: #ffffff; /* Midpoint blue */
            font-family: 'Arial', sans-serif;
        }

        @keyframes gradient {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .card {
            background-color: #121212;
            color: #5f8fe1;
            border: none;
        }

        .card-header {
            background-color: #1db954;
            color: white;
        }

        .highlight {
            color: #1db954;
        }

        .wrapped-container {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        .wrapped-column {
            flex: 1;
            min-width: 300px;
        }

        .footer {
            text-align: center;
            margin-top: 50px;
            font-size: 1.5rem;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <h1 class="text-center mb-4">🎉 Tyche Wrapped 🎉</h1>
        <div class="footer">
            Merry Christmas from the DC World Team 🎄
        </div> <br>
        <div class="wrapped-container">
            
            <!-- Charlie's Wrapped -->
            <div class="wrapped-column">
                <div class="card mb-4">
                    <div class="card-header">🎉 Charlie Wrapped</div>
                    <div class="card-body">
                        <h5>Work Stats</h5>
                        <ul>
                            <?php foreach ($charlieWorkStats as $workDesc => $count): ?>
                                <li>Charlie worked from <span class="highlight"><?= htmlspecialchars($workDesc) ?></span> <?= $count ?> times.</li>
                            <?php endforeach; ?>
                        </ul>
                        <h5>Night Stats</h5>
                        <ul>
                            <?php foreach ($charlieNightStats as $nightDesc => $count): ?>
                                <li>Charlie stayed at <span class="highlight"><?= htmlspecialchars($nightDesc) ?></span> <?= $count ?> times.</li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Daniel's Wrapped -->
            <div class="wrapped-column">
                <div class="card mb-4">
                    <div class="card-header">🎉 Daniel Wrapped</div>
                    <div class="card-body">
                        <h5>Work Stats</h5>
                        <ul>
                            <?php foreach ($danielWorkStats as $workDesc => $count): ?>
                                <li>Daniel worked from <span class="highlight"><?= htmlspecialchars($workDesc) ?></span> <?= $count ?> times.</li>
                            <?php endforeach; ?>
                        </ul>
                        <h5>Night Stats</h5>
                        <ul>
                            <?php foreach ($danielNightStats as $nightDesc => $count): ?>
                                <li>Daniel stayed at <span class="highlight"><?= htmlspecialchars($nightDesc) ?></span> <?= $count ?> times.</li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Shared Nights -->
            <div class="wrapped-column">
                <div class="card mb-4">
                    <div class="card-header">🎉 Shared Nights</div>
                    <div class="card-body">
                        <h5>Where You Stayed Together</h5>
                        <ul>
                            <?php foreach ($sharedNights as $sharedDesc => $count): ?>
                                <li>You both stayed at <span class="highlight"><?= htmlspecialchars($sharedDesc) ?></span> <?= $count ?> times.</li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        
    </div>

    <?php 
    
require 'footer.php';
    
    ?>
</body>
</html>
