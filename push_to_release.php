<?php
session_start();
require 'config.php';

require 'auth.php';


$changeID = isset($_GET['change_id']) ? (int)$_GET['change_id'] : 0;
$majorOrMinor = isset($_GET['major_or_minor']) ? $_GET['major_or_minor'] : '';

if ($changeID > 0 && in_array($majorOrMinor, ['Major', 'Minor'])) {
    $userID = $_SESSION['userID'];
    
    // Fetch the change details
    $sqlChange = "SELECT Description, Comments FROM DCChanges WHERE ChangeID = ?";
    $stmtChange = $link->prepare($sqlChange);
    $stmtChange->bind_param("i", $changeID);
    $stmtChange->execute();
    $stmtChange->bind_result($description, $comments);
    $stmtChange->fetch();
    $stmtChange->close();

    // Fetch latest release Ref
    $sqlLatestRef = "SELECT Ref FROM DCReleases ORDER BY Ref DESC LIMIT 1";
    $resultLatestRef = $link->query($sqlLatestRef);
    $latestRef = 1.0;
    if ($resultLatestRef->num_rows > 0) {
        $latestRef = $resultLatestRef->fetch_assoc()['Ref'];
    }

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

    // Increment version
    $newRef = incrementVersion($latestRef, $majorOrMinor === 'Major');

    // Insert into DCReleases
    $sqlInsertRelease = "INSERT INTO DCReleases (Ref, UserID, Date, MinorOrMajor, Description) VALUES (?, ?, NOW(), ?, ?)";
    $stmtInsertRelease = $link->prepare($sqlInsertRelease);
    $stmtInsertRelease->bind_param("diss", $newRef, $userID, $majorOrMinor, $description);
    $stmtInsertRelease->execute();
    $stmtInsertRelease->close();

    // Update DCChanges to complete
    $sqlUpdateChange = "UPDATE DCChanges SET ChangeComplete = 'Yes' WHERE ChangeID = ?";
    $stmtUpdateChange = $link->prepare($sqlUpdateChange);
    $stmtUpdateChange->bind_param("i", $changeID);
    $stmtUpdateChange->execute();
    $stmtUpdateChange->close();

    // Prepare email content
    $subject = "Change Implemented Successfully";
    $content = "<p>The following change has been implemented successfully:</p>";
    $content .= "<p><strong>Description:</strong> $description</p>";
    $content .= "<p><strong>Comments:</strong> $comments</p>";
    $content .= "<p><strong>New Version:</strong> $newRef</p>";
    
    // Insert email into DCEmailsLog for Charlie
    $sqlEmailCharlie = "INSERT INTO DCEmailsLog (`to`, Subject, Content, Sent) VALUES ('charlie@dcworld.uk', ?, ?, 'No')";
    $stmtEmailCharlie = $link->prepare($sqlEmailCharlie);
    $stmtEmailCharlie->bind_param("ss", $subject, $content);
    $stmtEmailCharlie->execute();
    $stmtEmailCharlie->close();
    
    // Insert email into DCEmailsLog for Daniel
    $sqlEmailDaniel = "INSERT INTO DCEmailsLog (`to`, Subject, Content, Sent) VALUES ('cromptondaniel234@gmail.com', ?, ?, 'No')";
    $stmtEmailDaniel = $link->prepare($sqlEmailDaniel);
    $stmtEmailDaniel->bind_param("ss", $subject, $content);
    $stmtEmailDaniel->execute();
    $stmtEmailDaniel->close();

    header("Location: admin.php");
    exit();
}

$link->close();
header("Location: admin.php?error=invalid_change");
exit();
?>
