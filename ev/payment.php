<?php
ob_start();
include 'header.php';

// Include your existing config file to get the database connection details
include 'config.php';

// Include functions
include 'functions.php';

// Debug: Verify database connection
if (!$link) {
    error_log("Database connection failed: " . mysqli_connect_error());
    die("Database connection failed.");
} else {
    error_log("Database connection established successfully.");
}

// Get the selected month
$month = isset($_GET['month']) ? $_GET['month'] : null;

if (!$month) {
    error_log("Month not specified in GET parameter.");
    die("Month not specified.");
}

// Debug: Log selected month
error_log("Selected month: $month");

// Get the total cost for the selected month
$total_cost = getTotalCostByMonth($link, $month);

// Debug: Log total cost
if ($total_cost === null) {
    error_log("Failed to fetch total cost for month: $month");
} else {
    error_log("Total cost for $month: £" . number_format($total_cost, 2));
}

// Check if the month is already marked as paid
$is_paid = isMonthPaid($link, $month);

// Debug: Log payment status
error_log("Payment status for $month: " . ($is_paid ? "Paid" : "Unpaid"));

// Function to handle manual payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_payment'])) {
    error_log("Manual payment request received for month: $month");
    
    if (!$is_paid) {
        $query = "INSERT INTO payments (month, is_paid) VALUES (?, TRUE) ON DUPLICATE KEY UPDATE is_paid = TRUE";
        $stmt = $link->prepare($query);
        if (!$stmt) {
            error_log("Query preparation failed: " . $link->error);
            die("Database query failed.");
        }

        $stmt->bind_param('s', $month);
        $stmt->execute();

        if ($stmt->errno) {
            error_log("Query execution failed: " . $stmt->error);
            die("Error marking payment as paid.");
        } else {
            error_log("Payment successfully marked as paid for $month. Rows affected: " . $stmt->affected_rows);
        }

        $stmt->close();

        // Fetch sessions for the month
        $sessions = getSessionsByMonth($link, $month);
        if (!$sessions) {
            error_log("Failed to fetch charging sessions for $month.");
        }

        // Build the table for the email content
        $table = '<table border="1" cellspacing="0" cellpadding="5" style="border-collapse: collapse; width: 100%;">';
        $table .= '<thead>';
        $table .= '<tr>';
        $table .= '<th>Date</th>';
        $table .= '<th>Start Time</th>';
        $table .= '<th>End Time</th>';
        $table .= '<th>kWh Used</th>';
        $table .= '<th>Cost per kWh</th>';
        $table .= '<th>Total Cost</th>';
        $table .= '</tr>';
        $table .= '</thead>';
        $table .= '<tbody>';

        while ($session = $sessions->fetch_assoc()) {
            $table .= '<tr>';
            $table .= '<td>' . $session['session_date'] . '</td>';
            $table .= '<td>' . $session['start_time'] . '</td>';
            $table .= '<td>' . $session['end_time'] . '</td>';
            $table .= '<td>' . $session['kwh_used'] . '</td>';
            $table .= '<td>£' . number_format($session['cost_per_kwh'], 2) . '</td>';
            $table .= '<td>£' . number_format($session['total_cost'], 2) . '</td>';
            $table .= '</tr>';
        }

        $table .= '</tbody>';
        $table .= '</table>';

        // Email content
        $subject = "Electric Car Bill Payment for " . date('F Y', strtotime($month . '-01'));
        $content = "<p>The electric bill for the car for <strong>" . date('F Y', strtotime($month . '-01')) . "</strong> has been paid via BACS at <strong>" . date('H:i:s') . "</strong>.</p>";
        $content .= "<p>Total cost: <strong>£" . number_format($total_cost, 2) . "</strong></p>";
        $content .= "<h3>Charging Sessions</h3>";
        $content .= $table;

        // Debug: Log email content
        error_log("Email content prepared: " . substr($content, 0, 200) . "...");

        // Insert email into DCEmailsLog table using the existing connection
        $query = "INSERT INTO DCEmailsLog (`to`, Subject, Content, DateTimeSent, Sent) VALUES (?, ?, ?, NOW(), 'No')";
        $stmt = $link->prepare($query);

        foreach ($toEmails as $to) {
            $stmt->bind_param('sss', $to, $subject, $content);
            $stmt->execute();

            if ($stmt->errno) {
                error_log("Failed to insert email log for $to: " . $stmt->error);
            } else {
                error_log("Email log inserted for $to.");
            }
        }

        $stmt->close();
    }

    // Refresh the page after processing
    error_log("Redirecting to payment.php for month: $month");
    header("Location: payment.php?month=" . $month);
    exit();
}

// Fetch sessions for the month
$sessions = getSessionsByMonth($link, $month);
if (!$sessions) {
    error_log("Failed to fetch charging sessions for $month.");
} else {
    error_log("Charging sessions fetched successfully for $month.");
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Summary for <?php echo date('F Y', strtotime($month . '-01')); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <h1 class="mb-4">Payment Summary for <?php echo date('F Y', strtotime($month . '-01')); ?></h1>
    <div class="card mb-4">
        <div class="card-header">
            Total Cost: £<?php echo number_format($total_cost, 2); ?>
        </div>
        <div class="card-body">
            <?php if (!$is_paid): ?>
                <form method="POST">
                    <button type="submit" name="manual_payment" class="btn btn-success">Mark as Paid</button>
                </form>
            <?php else: ?>
                <p>This month has already been marked as paid.</p>
            <?php endif; ?>
        </div>
    </div>



    <h2 class="mb-3">Charging Sessions</h2>
    <table class="table table-striped">
        <thead>
            <tr>
                <th>Date</th>
                <th>Start Time</th>
                <th>End Time</th>
                <th>kWh Used</th>
                <th>Cost per kWh</th>
                <th>Total Cost</th>
            </tr>
        </thead>
        <tbody>
        <?php while ($session = $sessions->fetch_assoc()): ?>
            <tr>
                <td><?php echo $session['session_date']; ?></td>
                <td><?php echo $session['start_time']; ?></td>
                <td><?php echo $session['end_time']; ?></td>
                <td><?php echo $session['kwh_used']; ?></td>
                <td>£<?php echo number_format($session['cost_per_kwh'], 2); ?></td>
                <td>£<?php echo number_format($session['total_cost'], 2); ?></td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>

<?php include '../footer.php'; ?>

</body>
</html>
