<?php

require 'auth.php';


// Fetch the logged-in user's information
$userID = $_SESSION['userID'];
$sqlUser = "SELECT UserID, Name, IMGURL FROM DC_Users WHERE UserID = ?";
$stmtUser = $link->prepare($sqlUser);
$stmtUser->bind_param("i", $userID);
$stmtUser->execute();
$stmtUser->bind_result($userID, $Name, $IMGURL);
$stmtUser->fetch();
$stmtUser->close();

// Fetch release log
$sqlReleases = "SELECT ReleaseID, UserID, Date, MinorOrMajor, Description FROM DCReleases ORDER BY Date DESC";
$resultReleases = $link->query($sqlReleases);
$releases = [];
while ($row = $resultReleases->fetch_assoc()) {
    $releases[] = $row;
}

// Fetch planned changes
$sqlChanges = "SELECT ChangeID, UserID, Description, DatePlanned, Comments, ChangeComplete, MajorOrMinor FROM DCChanges ORDER BY DatePlanned DESC";
$resultChanges = $link->query($sqlChanges);
$changes = [];
while ($row = $resultChanges->fetch_assoc()) {
    $changes[] = $row;
}

// Fetch all user details for displaying faces
$sqlUsers = "SELECT UserID, Name, IMGURL FROM DC_Users";
$resultUsers = $link->query($sqlUsers);
$users = [];
while ($row = $resultUsers->fetch_assoc()) {
    $users[$row['UserID']] = $row;
}

// Fetch latest release Ref
$sqlLatestRef = "SELECT Ref FROM DCReleases ORDER BY Ref DESC LIMIT 1";
$resultLatestRef = $link->query($sqlLatestRef);
$latestRef = 1.0;
if ($resultLatestRef->num_rows > 0) {
    $latestRef = $resultLatestRef->fetch_assoc()['Ref'];
}

$link->close();

// Server and PHP info
$phpVersion = phpversion();
$serverSoftware = $_SERVER['SERVER_SOFTWARE'];
$serverTime = date('Y-m-d H:i:s');

// Function to increment version
function incrementVersion($currentRef, $isMajor) {
    if ($isMajor) {
        $parts = explode('.', $currentRef);
        $major = (int)$parts[0] + 1;
        return $major . '.0';
    } else {
        return number_format($currentRef + 0.1, 1);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .card-header {
            background-color: #007ea7;
            color: #000;
        }
        .btn-run, .btn-toggle, .btn-edit, .btn-new, .btn-push, .btn-info {
            background-color: #28a745;
            color: #fff;
            margin: 2px;
        }
        .error-log {
            background-color: black;
            color: white;
            padding: 10px;
            margin-top: 10px;
            display: none;
            max-height: 400px;
            overflow-y: auto;
        }
    </style>
    <script>
        function toggleErrorLog() {
            const errorLog = document.getElementById('error-log');
            if (errorLog.style.display === 'none') {
                errorLog.style.display = 'block';
            } else {
                errorLog.style.display = 'none';
            }
        }

        function pushToRelease(changeId, majorOrMinor) {
            if (confirm("Are you sure you want to push this change to release?")) {
                window.location.href = "push_to_release.php?change_id=" + changeId + "&major_or_minor=" + majorOrMinor;
            }
        }

        function showModal(modalId) {
            const modal = new bootstrap.Modal(document.getElementById(modalId));
            modal.show();
        }

        function setModalContent(modalId, title, content) {
            document.getElementById(modalId + '-title').innerText = title;
            document.getElementById(modalId + '-content').innerHTML = content;
        }
    </script>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container mt-4">
        <h1>Admin Panel</h1>
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header">Release Log</div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Description</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($releases as $release): ?>
                                    <tr>
                                        <td>
                                            <?php if (isset($users[$release['UserID']])): ?>
                                                <img src="<?php echo htmlspecialchars($users[$release['UserID']]['IMGURL']); ?>" alt="<?php echo htmlspecialchars($users[$release['UserID']]['Name']); ?>" class="img-fluid rounded-circle" width="50">
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('l jS F Y H:i', strtotime($release['Date'])); ?></td>
                                        <td><?php echo htmlspecialchars($release['MinorOrMajor']); ?></td>
                                        <td><?php echo htmlspecialchars($release['Description']); ?></td>
                                        <td>
                                            <button class="btn btn-info" onclick="setModalContent('modal-info', 'Release Info', 
                                                'User: <?php echo htmlspecialchars($users[$release['UserID']]['Name']); ?><br>' + 
                                                'Date: <?php echo date('l jS F Y H:i', strtotime($release['Date'])); ?><br>' + 
                                                'Type: <?php echo htmlspecialchars($release['MinorOrMajor']); ?><br>' + 
                                                'Description: <?php echo htmlspecialchars($release['Description']); ?>'); showModal('modal-info')">More Info</button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (empty($releases)): ?>
                                <p>No release logs found.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header">Requests / Planned Changes</div>
                    <div class="card-body">
                        <button class="btn btn-new" onclick="window.location.href='new_change.php'">New Change</button>
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Date Planned</th>
                                        <th>Description</th>
                                        <th>Complete</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($changes as $change): ?>
                                    <tr>
                                        <td>
                                            <?php if (isset($users[$change['UserID']])): ?>
                                                <img src="<?php echo htmlspecialchars($users[$change['UserID']]['IMGURL']); ?>" alt="<?php echo htmlspecialchars($users[$change['UserID']]['Name']); ?>" class="img-fluid rounded-circle" width="50">
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('l jS F Y H:i', strtotime($change['DatePlanned'])); ?></td>
                                        <td><?php echo htmlspecialchars($change['Description']); ?></td>
                                        <td><?php echo htmlspecialchars($change['ChangeComplete']); ?></td>
                                        <td>
                                            <button class="btn btn-push" onclick="pushToRelease(<?php echo $change['ChangeID']; ?>, '<?php echo $change['MajorOrMinor']; ?>')">Push to Release</button>
                                            <button class="btn btn-info" onclick="setModalContent('modal-info', 'Change Info', 
                                                'User: <?php echo htmlspecialchars($users[$change['UserID']]['Name']); ?><br>' + 
                                                'Date Planned: <?php echo date('l jS F Y H:i', strtotime($change['DatePlanned'])); ?><br>' + 
                                                'Description: <?php echo htmlspecialchars($change['Description']); ?><br>' + 
                                                'Comments: <?php echo htmlspecialchars($change['Comments']); ?><br>' + 
                                                'Complete: <?php echo htmlspecialchars($change['ChangeComplete']); ?><br>' + 
                                                'Type: <?php echo htmlspecialchars($change['MajorOrMinor']); ?>'); showModal('modal-info')">More Info</button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php if (empty($changes)): ?>
                                <p>No planned changes found.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header">System Info</div>
                    <div class="card-body">
                        <p><strong>PHP Version:</strong> <?php echo htmlspecialchars($phpVersion); ?></p>
                        <p><strong>Server Software:</strong> <?php echo htmlspecialchars($serverSoftware); ?></p>
                        <p><strong>Server Time:</strong> <?php echo htmlspecialchars($serverTime); ?></p>
                        <p><strong>User Information:</strong> <?php echo htmlspecialchars($Name); ?></p>
                        <p><strong>Current Version:</strong> <strong><?php echo htmlspecialchars($latestRef); ?></strong></p>
                    </div>
                </div>

                <button class="btn btn-dark mb-3" onclick="toggleErrorLog()">Show Error Log</button>
                <div id="error-log" class="error-log">
                    <?php
                    $errorLogPath = ini_get('error_log');
                    if (file_exists($errorLogPath)) {
                        echo nl2br(file_get_contents($errorLogPath));
                    } else {
                        echo "Error log file not found.";
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modal-info" tabindex="-1" aria-labelledby="modal-info-title" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modal-info-title">More Info</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="modal-info-content">
                    ...
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.5.4/dist/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
