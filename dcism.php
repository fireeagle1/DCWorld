<?php
session_start();
require 'config.php';

require 'auth.php';


// Fetch the user's name from DC_Users
$userID = $_SESSION['userID'];
$sqlUser = "SELECT Name FROM DC_Users WHERE UserID = ?";
$stmtUser = $link->prepare($sqlUser);
$stmtUser->bind_param("i", $userID);
$stmtUser->execute();
$stmtUser->bind_result($userName);
$stmtUser->fetch();
$stmtUser->close();

// Handle form submission to add a new saying
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['saying'], $_POST['saidBy'], $_POST['context'])) {
    $saying = $link->real_escape_string($_POST['saying']);
    $saidBy = $link->real_escape_string($_POST['saidBy']);
    $context = $link->real_escape_string($_POST['context']);

    $sqlInsert = "INSERT INTO FunnySayings (Saying, SaidBy, Context, AddedBy) VALUES (?, ?, ?, ?)";
    $stmt = $link->prepare($sqlInsert);
    $stmt->bind_param('sssi', $saying, $saidBy, $context, $userID);

    if ($stmt->execute()) {
        $successMessage = "Funny saying added successfully!";
    } else {
        $errorMessage = "Failed to add saying: " . $stmt->error;
    }

    $stmt->close();
}

// Fetch all sayings with lookup for the AddedBy name
$sqlFetch = "
    SELECT fs.SayingID, fs.Saying, fs.SaidBy, fs.Context, fs.DateAdded, u.Name AS AddedByName
    FROM FunnySayings fs
    LEFT JOIN DC_Users u ON fs.AddedBy = u.UserID
    ORDER BY fs.DateAdded DESC";
$result = $link->query($sqlFetch);

// Fetch sayings for the spinning wheel with all required fields
$sayings = [];
while ($row = $result->fetch_assoc()) {
    $sayings[] = [
        'text' => $row['Saying'],
        'saidBy' => $row['SaidBy'],
        'context' => $row['Context']
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Funny Sayings</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: Arial, sans-serif; background-color: #f8f9fa; }
        .card-header { background-color: #007ea7; color: #fff; font-weight: bold; }
        .btn-toggle { margin-bottom: 20px; }
        .wheel-container { text-align: center; margin: 20px 0; }
        #wheel { margin: 0 auto; width: 300px; height: 300px; border-radius: 50%; position: relative; overflow: hidden; }
        .wheel-segment {
            position: absolute;
            width: 100%;
            height: 100%;
            clip-path: polygon(50% 50%, 100% 0, 100% 100%);
            transform-origin: center;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            text-align: center;
            writing-mode: vertical-lr;
            text-orientation: mixed;
            font-weight: bold;
            padding: 5px;
            color: white;
        }
        .wheel-segment:nth-child(odd) { background-color: #007ea7; }
        .wheel-segment:nth-child(even) { background-color: #29af8f; }
        #spin-button { margin-top: 20px; }
        .context-display {
            text-align: center;
            font-size: 1.2rem;
            background-color: #e9ecef;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .table-modern th { background-color: #007ea7; color: white; font-weight: bold; }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container mt-4">
        <h1 class="text-center">Funny Sayings</h1>

        <!-- Display Context -->
        <div id="context-display" class="context-display">
            Spin the wheel to see a saying, who said it, and the context!
        </div>

        <!-- Add New Saying Button -->
        <button class="btn btn-primary btn-toggle" onclick="toggleForm()">Add New Saying</button>

        <!-- Form to Add a New Saying -->
        <div class="card mb-4" id="add-saying-form" style="display: none;">
            <div class="card-header">Add a New Saying</div>
            <div class="card-body">
                <?php if (isset($successMessage)) echo "<div class='alert alert-success'>$successMessage</div>"; ?>
                <?php if (isset($errorMessage)) echo "<div class='alert alert-danger'>$errorMessage</div>"; ?>
                <form method="POST">
                    <div class="form-group">
                        <label for="saying">Funny Saying</label>
                        <textarea class="form-control" id="saying" name="saying" rows="3" required></textarea>
                    </div>
                    <div class="form-group">
                        <label for="saidBy">Said By</label>
                        <input type="text" class="form-control" id="saidBy" name="saidBy" required>
                    </div>
                    <div class="form-group">
                        <label for="context">Context</label>
                        <input type="text" class="form-control" id="context" name="context">
                    </div>
                    <button type="submit" class="btn btn-primary">Add Saying</button>
                </form>
            </div>
        </div>

        <!-- Random Saying Spinner -->
        <div class="wheel-container">
            <h3>Spin for a Random Saying!</h3>
            <div id="wheel"></div>
            <button class="btn btn-success" id="spin-button" onclick="spinWheel()">Spin</button>
        </div>

        <!-- Table to Display All Sayings -->
        <div class="card mt-4">
            <div class="card-header">All Funny Sayings</div>
            <div class="card-body">
                <table class="table table-bordered table-modern">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Saying</th>
                            <th>Said By</th>
                            <th>Context</th>
                            <th>Added By</th>
                            <th>Date Added</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $result->data_seek(0); while ($row = $result->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['SayingID']); ?></td>
                                <td><?= htmlspecialchars($row['Saying']); ?></td>
                                <td><?= htmlspecialchars($row['SaidBy']); ?></td>
                                <td><?= htmlspecialchars($row['Context']); ?></td>
                                <td><?= htmlspecialchars($row['AddedByName']); ?></td>
                                <td><?= htmlspecialchars($row['DateAdded']); ?></td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php include 'footer.php'; ?>

    <script>
        const sayings = <?= json_encode($sayings, JSON_HEX_TAG); ?>;

        const wheel = document.getElementById('wheel');
        const contextDisplay = document.getElementById('context-display');
        const anglePerSegment = 360 / sayings.length;

        // Generate wheel segments
        sayings.forEach((saying, index) => {
            const segment = document.createElement('div');
            segment.className = 'wheel-segment';
            segment.style.transform = `rotate(${index * anglePerSegment}deg) skewY(-60deg)`;
            segment.innerHTML = saying.text;
            wheel.appendChild(segment);
        });

        let spinning = false;

        // Spin the wheel
        function spinWheel() {
            if (spinning) return;
            spinning = true;

            const randomRotation = Math.floor(Math.random() * 360) + 3600; // Random spin
            wheel.style.transition = 'transform 4s cubic-bezier(0.25, 0.1, 0.25, 1)';
            wheel.style.transform = `rotate(${randomRotation}deg)`;

            setTimeout(() => {
                const selectedIndex = Math.floor((randomRotation % 360) / anglePerSegment);
                const selectedSaying = sayings[selectedIndex];

                // Update context display with all details
                contextDisplay.innerHTML = `
                    <strong>Saying:</strong> ${selectedSaying.text} <br>
                    <strong>Said By:</strong> ${selectedSaying.saidBy} <br>
                    <strong>Context:</strong> ${selectedSaying.context || 'No context provided'}
                `;
                spinning = false;
            }, 4000);
        }

        // Toggle Add Saying Form
        function toggleForm() {
            const form = document.getElementById('add-saying-form');
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        }
    </script>
</body>
</html>
