<?php
session_start();
require 'config.php';
require 'auth.php';


// Fetch the logged-in user's information
$userID = $_SESSION['userID'];
$sqlUser = "SELECT Name, Email, PhoneNumber, SalaryAfterTax, IMGURL FROM DC_Users WHERE UserID = ?";
$stmtUser = $link->prepare($sqlUser);
$stmtUser->bind_param("i", $userID);
$stmtUser->execute();
$stmtUser->bind_result($Name, $Email, $PhoneNumber, $SalaryAfterTax, $IMGURL);
$stmtUser->fetch();
$stmtUser->close();

// Define settings definitions
$settingsDefinitions = [
    'DailyOpsColor' => ['type' => 'color', 'label' => 'Daily Ops Event Color'],
    'EventColor' => ['type' => 'color', 'label' => 'Regular Event Color'],
    'OnCallColor' => ['type' => 'color', 'label' => 'On Call Event Color'],
    'DutySheetColor' => ['type' => 'color', 'label' => 'DutySheet Event Color'],
    'ShowWorkLocation' => [
        'type' => 'select',
        'label' => 'Show Work Location Events by default',
        'options' => ['1' => 'Yes', '0' => 'No']
    ],
    'ShowNightLocation' => [
        'type' => 'select',
        'label' => 'Show Night Location Events by default',
        'options' => ['1' => 'Yes', '0' => 'No']
    ],
    'ShowEvents' => [
        'type' => 'select',
        'label' => 'Show Regular Events by default',
        'options' => ['1' => 'Yes', '0' => 'No']
    ],
    'ShowOnCall' => [
        'type' => 'select',
        'label' => 'Show On Call Events by default',
        'options' => ['1' => 'Yes', '0' => 'No']
    ],
    'ShowDutySheet' => [
        'type' => 'select',
        'label' => 'Show DutySheet Events by default',
        'options' => ['1' => 'Yes', '0' => 'No']
    ],
    // Add other settings as needed
];

// Fetch the user's settings
$sqlSettings = "SELECT SettingKey, SettingValue FROM UserSettings WHERE UserID = ?";
$stmtSettings = $link->prepare($sqlSettings);
$stmtSettings->bind_param("i", $userID);
$stmtSettings->execute();
$resultSettings = $stmtSettings->get_result();
$userSettings = [];
while ($row = $resultSettings->fetch_assoc()) {
    $userSettings[$row['SettingKey']] = $row['SettingValue'];
}
$stmtSettings->close();

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    foreach ($settingsDefinitions as $settingKey => $settingInfo) {
        $newSettingValue = isset($_POST[$settingKey]) ? $_POST[$settingKey] : '';

        // Validate color input
        if ($settingInfo['type'] === 'color') {
            if (!preg_match('/^#[a-f0-9]{6}$/i', $newSettingValue)) {
                // Invalid color code; you can handle this as needed
                continue;
            }
        }

        // Check if setting already exists
        if (array_key_exists($settingKey, $userSettings)) {
            // Update existing setting
            $sqlUpdateSetting = "UPDATE UserSettings SET SettingValue = ? WHERE UserID = ? AND SettingKey = ?";
            $stmtUpdateSetting = $link->prepare($sqlUpdateSetting);
            $stmtUpdateSetting->bind_param("sis", $newSettingValue, $userID, $settingKey);
            $stmtUpdateSetting->execute();
            $stmtUpdateSetting->close();
        } else {
            // Insert new setting
            $sqlInsertSetting = "INSERT INTO UserSettings (UserID, SettingKey, SettingValue) VALUES (?, ?, ?)";
            $stmtInsertSetting = $link->prepare($sqlInsertSetting);
            $stmtInsertSetting->bind_param("iss", $userID, $settingKey, $newSettingValue);
            $stmtInsertSetting->execute();
            $stmtInsertSetting->close();
        }

        // Update the $userSettings array
        $userSettings[$settingKey] = $newSettingValue;
    }

    $settingsMessage = "Settings updated successfully.";
}

// Handle password reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];

    if ($newPassword === $confirmPassword) {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $sqlUpdatePassword = "UPDATE DC_Users SET Password = ? WHERE UserID = ?";
        $stmtUpdatePassword = $link->prepare($sqlUpdatePassword);
        $stmtUpdatePassword->bind_param("si", $hashedPassword, $userID);

        if ($stmtUpdatePassword->execute()) {
            $passwordMessage = "Password reset successfully.";
        } else {
            $passwordMessage = "Error resetting password.";
        }

        $stmtUpdatePassword->close();
    } else {
        $passwordMessage = "Passwords do not match.";
    }
}

$link->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Profile</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Fix for header being white */
        .card-header {
            background-color: #007ea7 !important;
            color: #fff !important;
        }
        .navbar {
            background-color: #003459 !important;
        }
        .navbar-brand, .navbar-nav .nav-link {
            color: #fff !important;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container mt-4">
        <h1>My Profile</h1>

        <!-- Personal Information Card -->
        <div class="card mb-3">
            <div class="card-header">Personal Information</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <img src="<?php echo htmlspecialchars($IMGURL); ?>" alt="Profile Image" class="img-fluid rounded-circle">
                    </div>
                    <div class="col-md-8">
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($Name); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($Email); ?></p>
                        <p><strong>Phone Number:</strong> <?php echo htmlspecialchars($PhoneNumber); ?></p>
                        <p><strong>Salary After Tax:</strong> £<?php echo htmlspecialchars(number_format($SalaryAfterTax, 2)); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reset Password Card -->
        <div class="card mb-3">
            <div class="card-header">Reset Password</div>
            <div class="card-body">
                <?php if (isset($passwordMessage)): ?>
                    <div class="alert alert-info"><?php echo htmlspecialchars($passwordMessage); ?></div>
                <?php endif; ?>
                <form method="post">
                    <div class="form-group">
                        <label for="new_password">New Password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" required>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm Password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                    </div>
                    <input type="hidden" name="reset_password" value="1">
                    <button type="submit" class="btn btn-primary">Reset Password</button>
                </form>
            </div>
        </div>

        <!-- User Settings Card -->
        <div class="card mb-3">
            <div class="card-header">User Settings</div>
            <div class="card-body">
                <?php if (isset($settingsMessage)): ?>
                    <div class="alert alert-info"><?php echo htmlspecialchars($settingsMessage); ?></div>
                <?php endif; ?>
                <form method="post">
                    <?php foreach ($settingsDefinitions as $settingKey => $settingInfo): ?>
                        <?php
                        $currentValue = isset($userSettings[$settingKey]) ? $userSettings[$settingKey] : '';
                        ?>
                        <div class="form-group">
                            <label for="<?php echo htmlspecialchars($settingKey); ?>"><?php echo htmlspecialchars($settingInfo['label']); ?></label>
                            <?php if ($settingInfo['type'] === 'color'): ?>
                                <input type="color" class="form-control" id="<?php echo htmlspecialchars($settingKey); ?>" name="<?php echo htmlspecialchars($settingKey); ?>" value="<?php echo htmlspecialchars($currentValue); ?>">
                            <?php elseif ($settingInfo['type'] === 'select'): ?>
                                <select class="form-control" id="<?php echo htmlspecialchars($settingKey); ?>" name="<?php echo htmlspecialchars($settingKey); ?>">
                                    <?php foreach ($settingInfo['options'] as $optionValue => $optionLabel): ?>
                                        <option value="<?php echo htmlspecialchars($optionValue); ?>" <?php echo ($currentValue == $optionValue) ? 'selected' : ''; ?>><?php echo htmlspecialchars($optionLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="text" class="form-control" id="<?php echo htmlspecialchars($settingKey); ?>" name="<?php echo htmlspecialchars($settingKey); ?>" value="<?php echo htmlspecialchars($currentValue); ?>">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <input type="hidden" name="update_settings" value="1">
                    <button type="submit" class="btn btn-primary">Update Settings</button>
                </form>
            </div>
        </div>

    </div>

    <?php include 'footer.php'; ?>

</body>
</html>
