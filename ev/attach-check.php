<?php include 'header.php'; ?>

<?php
// Include your existing config file to get the database connection details
include 'config.php';

// Email server details
$mailbox = '{ev.dcworld.uk:993/imap/ssl}INBOX'; // Replace with your IMAP server
$username = 'exports@ev.dcworld.uk';
$password = 'WVW9,%#F+u5b';

// Connect to the mailbox
$inbox = imap_open($mailbox, $username, $password) or die('Cannot connect to mailbox: ' . imap_last_error());
echo "Connected to mailbox.\n";

// Search for emails from the specific sender
$emails = imap_search($inbox, 'FROM "Charlie@ckenterprises.co.uk"');

if ($emails) {
    echo count($emails) . " email(s) found from Charlie@ckenterprises.co.uk.\n";

    foreach ($emails as $email_number) {
        $structure = imap_fetchstructure($inbox, $email_number);
        
        if ($structure) {
            echo "Email structure found.\n";
            echo "Email has " . count($structure->parts) . " part(s).\n";
            
            for ($i = 0; $i < count($structure->parts); $i++) {
                $part = $structure->parts[$i];
                
                echo "Part " . ($i + 1) . ": subtype = " . strtolower($part->subtype) . ", encoding = " . $part->encoding . "\n";
                
                if ($part->ifdparameters) {
                    foreach ($part->dparameters as $object) {
                        if (strtolower($object->attribute) == 'filename') {
                            $filename = $object->value;
                            echo "Attachment filename: " . $filename . "\n";

                            if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) == 'csv') {
                                echo "CSV attachment found.\n";
                                $message = imap_fetchbody($inbox, $email_number, $i + 1);

                                if ($part->encoding == 3) { // Base64
                                    echo "Decoding Base64 encoded content.\n";
                                    $message = base64_decode($message);
                                } elseif ($part->encoding == 4) { // Quoted-Printable
                                    echo "Decoding Quoted-Printable encoded content.\n";
                                    $message = quoted_printable_decode($message);
                                }

                                $file_path = '/tmp/' . $filename; // Or any other desired path
                                if (file_put_contents($file_path, $message)) {
                                    echo "File saved to: " . $file_path . "\n";

                                    // Process the CSV file and detect new months
                                    $new_month_detected = false;
                                    include 'import.php';

                                    // Assuming import.php processes the file and returns a list of months
                                    // If the import script sets a variable $new_months_detected to true, we proceed
                                    if ($new_month_detected) {
                                        // Send email notifications
                                        $subject = "New Month Detected: " . date('F Y', strtotime($filename));
                                        $content = "A new month has been detected and processed in the charging data: " . date('F Y', strtotime($filename)) . ".\n\n" .
                                                   "Please review the data at your earliest convenience.";

                                        $recipients = ["charlie@ckenterprises.co.uk", "r.gahan72@gmail.com"];
                                        $emailLink = new mysqli('localhost', 'xohpwhmm_DCWorldUser', '&bU4$r^S%#f78%9rP', 'xohpwhmm_DCWorld');

                                        if ($emailLink->connect_error) {
                                            die("Connection failed: " . $emailLink->connect_error);
                                        }

                                        $query = "INSERT INTO DCEmailsLog (`to`, Subject, Content, DateTimeSent, Sent) VALUES (?, ?, ?, NOW(), 'No')";
                                        $stmt = $emailLink->prepare($query);

                                        foreach ($recipients as $recipient) {
                                            $stmt->bind_param('sss', $recipient, $subject, $content);
                                            $stmt->execute();
                                        }

                                        $stmt->close();
                                        $emailLink->close();
                                        echo "Notification email sent to recipients.\n";
                                    }

                                    if (file_exists($file_path)) {
                                        unlink($file_path);
                                        echo "File deleted after processing.\n";
                                    } else {
                                        echo "File not found for deletion: " . $file_path . "\n";
                                    }
                                } else {
                                    echo "Failed to save the file.\n";
                                }
                            } else {
                                echo "Attachment is not a CSV file.\n";
                            }
                        }
                    }
                } else {
                    echo "No attachment found in this part.\n";
                }
            }
        } else {
            echo "No structure found for this email.\n";
        }

        imap_delete($inbox, $email_number);
        echo "Email marked for deletion.\n";
    }
    imap_expunge($inbox);
    echo "Deleted emails have been expunged.\n";
} else {
    echo "No emails found from Charlie@ckenterprises.co.uk.\n";
}

imap_close($inbox);
echo "Connection to mailbox closed.\n";
?>
