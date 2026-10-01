<?php
session_start();
require 'config.php';

require 'auth.php';


// Fetch all users
$sqlUsers = "SELECT Email FROM DC_Users";
$resultUsers = $link->query($sqlUsers);
$users = [];
while ($row = $resultUsers->fetch_assoc()) {
    $users[] = $row['Email'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject = $_POST['subject'];
    $content = $_POST['content'];
    $recipients = isset($_POST['recipients']) ? $_POST['recipients'] : [];
    $manualRecipients = isset($_POST['manual_recipients']) ? explode(',', $_POST['manual_recipients']) : [];

    // Merge manual recipients with selected users and filter out blanks
    $allRecipients = array_filter(array_merge($recipients, array_map('trim', $manualRecipients)), function($email) {
        return !empty($email);
    });

    // Insert email into log table
    foreach ($allRecipients as $email) {
        $sql = "INSERT INTO DCEmailsLog (`to`, Subject, Content, Sent) VALUES (?, ?, ?, 'No')";
        $stmt = $link->prepare($sql);
        $stmt->bind_param("sss", $email, $subject, $content);
        $stmt->execute();
    }

    echo "<div class='alert alert-success'>Emails queued successfully!</div>";
}
?>

<?php include 'header.php'; ?>

<div class="container mt-4">
    <h1>Send Email</h1>
    <form method="POST" action="">
        <div class="mb-3">
            <label for="subject" class="form-label">Email Subject</label>
            <input type="text" class="form-control" id="subject" name="subject" required>
        </div>
        <div class="mb-3">
            <label for="content" class="form-label">Email Content</label>
            <textarea class="form-control" id="content" name="content" rows="5" required></textarea>
        </div>
        <div class="mb-3">
            <label for="recipients" class="form-label">Select Recipients</label>
            <div class="form-check">
                <?php foreach ($users as $email): ?>
                    <div>
                        <input class="form-check-input" type="checkbox" name="recipients[]" value="<?php echo htmlspecialchars($email); ?>">
                        <label class="form-check-label"> <?php echo htmlspecialchars($email); ?> </label>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="mb-3">
            <label for="manual_recipients" class="form-label">Manually Add Recipients (comma-separated)</label>
            <input type="text" class="form-control" id="manual_recipients" name="manual_recipients">
        </div>
        <button type="submit" class="btn btn-primary">Queue Emails</button>
    </form>
</div>

<?php include 'footer.php'; ?>