<?php
// Include your existing config file to get the database connection details
include 'config.php';

// Fetch the cost per kWh from the settings table
$query = "SELECT value FROM settings WHERE setting = 'cost_per_kWh' LIMIT 1";
$result = $link->query($query);

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $cost_per_kWh = floatval($row['value']);
} else {
    die("Cost per kWh not set in settings table.");
}

// Debugging: Output the fetched cost per kWh
echo "Cost per kWh: " . $cost_per_kWh . "\n";

// Define the directory where the CSV files are stored
$directory = '/tmp/';

// Get all CSV files in the directory
$files = glob($directory . '*.csv');

if (!empty($files)) {
    foreach ($files as $csvFile) {
        // Open the CSV file
        if (($handle = fopen($csvFile, 'r')) !== FALSE) {
            // Skip the first line (headers)
            fgetcsv($handle);

            // Loop through the file and process each row
            while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
                // Skip rows that don't have enough data or aren't marked as 'home'
                if (empty($data[2]) || strtolower(trim($data[13])) != 'home') {
                    continue;
                }

                // Extract and parse data
                $session_date = date('Y-m-d', strtotime(str_replace('/', '-', $data[2]))); // Convert date to 'YYYY-MM-DD'
                $start_time = date('H:i:s', strtotime($data[3])); // Start time
                $end_time = date('H:i:s', strtotime($data[6])); // End time
                $kwh_used = floatval($data[10]); // Total kWh Consumed

                // Calculate the total cost based on the cost per kWh
                $total_cost = $kwh_used * $cost_per_kWh;

                // Debugging: Output the calculation for verification
                echo "kWh used: " . $kwh_used . " * Cost per kWh: " . $cost_per_kWh . " = Total cost: " . $total_cost . "\n";

                // Insert data into the database
                $stmt = $link->prepare("INSERT INTO charging_sessions (session_date, start_time, end_time, kwh_used, cost_per_kwh, total_cost) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('sssddd', $session_date, $start_time, $end_time, $kwh_used, $cost_per_kWh, $total_cost);

                if (!$stmt->execute()) {
                    echo "Error inserting data: " . $stmt->error;
                }

                $stmt->close();
            }

            fclose($handle);

            // Delete the file after processing
            unlink($csvFile);
        } else {
            echo "Error opening the file: $csvFile";
        }
    }

    echo "All files processed and deleted successfully!";
} else {
    echo "No CSV files found in the directory.";
}

?>
