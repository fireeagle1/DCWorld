<?php
session_start();
require 'config.php';

require 'auth.php';


// Fetch spending data
$sqlSpending = "SELECT Vendor, SUM(Value) AS TotalSpent FROM DCSpending GROUP BY Vendor";
$resultSpending = $link->query($sqlSpending);
$spendingData = [];
while ($row = $resultSpending->fetch_assoc()) {
    $spendingData[] = $row;
}

// Fetch savings data
$sqlSavings = "SELECT ShortDesc, Balance FROM DCBankAccounts";
$resultSavings = $link->query($sqlSavings);
$savingsData = [];
$totalSavings = 0;
while ($row = $resultSavings->fetch_assoc()) {
    if ($row['ShortDesc'] === 'Help to Buy') {
        $row['Balance'] += $row['Balance'] * 0.25;
    }
    $totalSavings += $row['Balance'];
}

$link->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Reports</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@1.0.0"></script>
    <style>
        .card-header {
            background-color: #007ea7;
            color: #fff;
        }
        .chart-container {
            position: relative;
            margin: auto;
            height: 400px;
            width: 400px;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="container mt-4">
        <h1>Finance Reports</h1>
        <div class="row">
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header">Combined Savings</div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="savingsChart"></canvas>
                        </div>
                        <p class="mt-3"><strong>Total Savings:£</strong> <?php echo number_format($totalSavings, 2); ?></p>
                        <p><strong>Target:</strong> £30,000</p>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card mb-3">
                    <div class="card-header">Spend by Vendors</div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="vendorsChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Data for Savings Chart
            var totalSavings = <?php echo $totalSavings; ?>;
            var target = 30000;

            var ctxSavings = document.getElementById('savingsChart').getContext('2d');
            var savingsChart = new Chart(ctxSavings, {
                type: 'bar',
                data: {
                    labels: ['Combined Savings'],
                    datasets: [{
                        label: 'Balance',
                        data: [totalSavings],
                        backgroundColor: 'rgba(54, 162, 235, 0.6)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    scales: {
                        y: {
                            beginAtZero: true,
                            suggestedMax: 35000
                        }
                    },
                    plugins: {
                        annotation: {
                            annotations: {
                                line1: {
                                    type: 'line',
                                    yMin: target,
                                    yMax: target,
                                    borderColor: 'rgb(255, 99, 132)',
                                    borderWidth: 2,
                                    label: {
                                        content: 'Target (30k)',
                                        enabled: true,
                                        position: 'start'
                                    }
                                }
                            }
                        }
                    }
                }
            });

            // Data for Vendors Chart
            var vendorsLabels = <?php echo json_encode(array_column($spendingData, 'Vendor')); ?>;
            var vendorsData = <?php echo json_encode(array_column($spendingData, 'TotalSpent')); ?>;

            var ctxVendors = document.getElementById('vendorsChart').getContext('2d');
            var vendorsChart = new Chart(ctxVendors, {
                type: 'pie',
                data: {
                    labels: vendorsLabels,
                    datasets: [{
                        label: 'Total Spent',
                        data: vendorsData,
                        backgroundColor: [
                            'rgba(255, 99, 132, 0.6)',
                            'rgba(54, 162, 235, 0.6)',
                            'rgba(255, 206, 86, 0.6)',
                            'rgba(75, 192, 192, 0.6)',
                            'rgba(153, 102, 255, 0.6)',
                            'rgba(255, 159, 64, 0.6)'
                        ],
                        borderColor: [
                            'rgba(255, 99, 132, 1)',
                            'rgba(54, 162, 235, 1)',
                            'rgba(255, 206, 86, 1)',
                            'rgba(75, 192, 192, 1)',
                            'rgba(153, 102, 255, 1)',
                            'rgba(255, 159, 64, 1)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'top'
                        }
                    }
                }
            });
        });
    </script>

   <?php include 'footer.php'; ?>

</body>
</html>
