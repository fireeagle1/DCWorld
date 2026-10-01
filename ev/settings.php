<?php include 'header.php'; ?>
<?php
// Include your existing config file to get the database connection details
include 'config.php';

// Handle form submission for updating the default cost per kWh
if (isset($_POST['update_default_cost'])) {
    $new_default_cost = floatval($_POST['default_cost']);
    $query = "UPDATE settings SET value = ? WHERE setting = 'cost_per_kwh'";
    $stmt = $link->prepare($query);
    $stmt->bind_param('d', $new_default_cost);
    if ($stmt->execute()) {
        $success_message = "Default cost per kWh updated successfully!";
    } else {
        $error_message = "Failed to update the default cost per kWh.";
    }
    $stmt->close();
}

// Handle form submission for bulk updating the cost per kWh for a specific time period
if (isset($_POST['bulk_update'])) {
    $bulk_cost = floatval($_POST['bulk_cost']);
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];

    $query = "UPDATE charging_sessions SET cost_per_kwh = ?, total_cost = kwh_used * ? WHERE session_date BETWEEN ? AND ?";
    $stmt = $link->prepare($query);
    $stmt->bind_param('ddss', $bulk_cost, $bulk_cost, $start_date, $end_date);
    if ($stmt->execute()) {
        $success_message = "Bulk update of cost per kWh for the selected period was successful!";
        $ask_update_default = true;
    } else {
        $error_message = "Failed to bulk update the cost per kWh.";
    }
    $stmt->close();
}

// Fetch the current default cost per kWh
$query = "SELECT value FROM settings WHERE setting = 'cost_per_kwh'";
$result = $link->query($query);
$current_cost = $result->fetch_assoc()['value'];
$result->close();
?>

<div class="container mt-5">
    <h1 class="mb-4">Settings</h1>

    <?php if (isset($success_message)): ?>
        <div class="alert alert-success"><?php echo $success_message; ?></div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger"><?php echo $error_message; ?></div>
    <?php endif; ?>

    <?php if (isset($ask_update_default)): ?>
        <div class="alert alert-info">
            The cost per kWh for the selected period has been updated. Do you want to update the default cost per kWh to this value?
            <form method="post">
                <input type="hidden" name="default_cost" value="<?php echo $bulk_cost; ?>">
                <button type="submit" name="update_default_cost" class="btn btn-primary">Yes, Update Default</button>
            </form>
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-body">
            <h3>Update Default Cost per kWh</h3>
            <p>The default cost per kWh is used to calculate the total cost for all charging sessions. You can update this value below.</p>
            <form method="post">
                <div class="mb-3">
                    <label for="default_cost" class="form-label">Current Default Cost per kWh</label>
                    <input type="text" class="form-control" id="default_cost" name="default_cost" value="<?php echo number_format($current_cost, 2); ?>">
                </div>
                <button type="submit" name="update_default_cost" class="btn btn-primary">Update Default Cost</button>
            </form>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <h3>Bulk Update Cost per kWh for a Time Period</h3>
            <p>You can update the cost per kWh for a specific time period. This will recalculate the total cost for all sessions in that period. After the update, you can choose to set this value as the new default cost per kWh.</p>
            <form method="post">
                <div class="mb-3">
                    <label for="bulk_cost" class="form-label">New Cost per kWh</label>
                    <input type="text" class="form-control" id="bulk_cost" name="bulk_cost" required>
                </div>
                <div class="mb-3">
                    <label for="start_date" class="form-label">Start Date</label>
                    <input type="date" class="form-control" id="start_date" name="start_date" required>
                </div>
                <div class="mb-3">
                    <label for="end_date" class="form-label">End Date</label>
                    <input type="date" class="form-control" id="end_date" name="end_date" required>
                </div>
                <button type="submit" name="bulk_update" class="btn btn-primary">Bulk Update Cost</button>
            </form>
        </div>
    </div>
</div>

<?php include '../footer.php'; ?>

</body>
</html>
