<?php include 'header.php'; ?>
<?php
// Include your existing config file to get the database connection details
include 'config.php';

// Fetch data from the database
$query = "
    SELECT
        DATE_FORMAT(session_date, '%Y-%m') AS month,
        SUM(kwh_used) AS total_kwh,
        SUM(total_cost) AS total_cost,
        AVG(cost_per_kwh) AS avg_cost_per_kwh,
        COUNT(*) AS session_count
    FROM charging_sessions
    GROUP BY month
    ORDER BY month ASC";
$result = $link->query($query);

$months = [];
$total_kwh = [];
$total_cost = [];
$avg_cost_per_kwh = [];
$session_count = [];

while ($row = $result->fetch_assoc()) {
    $months[] = $row['month'];
    $total_kwh[] = $row['total_kwh'];
    $total_cost[] = $row['total_cost'];
    $avg_cost_per_kwh[] = $row['avg_cost_per_kwh'];
    $session_count[] = $row['session_count'];
}
?>

<div class="container mt-5">
    <h1 class="mb-4">Charging Reports</h1>
    
    <!-- Monthly Energy Usage -->
    <h3>Monthly Energy Usage (kWh)</h3>
    <canvas id="energyUsageChart"></canvas>
    
    <!-- Monthly Charging Costs -->
    <h3>Monthly Charging Costs (£)</h3>
    <canvas id="chargingCostChart"></canvas>
    
    <!-- Average Cost per kWh -->
    <h3>Average Cost per kWh (£)</h3>
    <canvas id="costPerKwhChart"></canvas>
    
    <!-- Total Charging Sessions per Month -->
    <h3>Total Charging Sessions per Month</h3>
    <canvas id="sessionCountChart"></canvas>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Original data arrays from PHP
    const rawMonths = <?php echo json_encode($months); ?>;
    const totalKwh = <?php echo json_encode($total_kwh); ?>;
    const totalCost = <?php echo json_encode($total_cost); ?>;
    const avgCostPerKwh = <?php echo json_encode($avg_cost_per_kwh); ?>;
    const sessionCount = <?php echo json_encode($session_count); ?>;

    // Define standard month labels for the x-axis
    const monthLabels = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

    // Transform the raw data into a records array with year and month (number)
    const records = rawMonths.map((m, i) => {
        let parts = m.split('-');
        return {
            year: parts[0],
            month: parseInt(parts[1]),
            totalKwh: totalKwh[i],
            totalCost: totalCost[i],
            sessionCount: sessionCount[i]
        };
    });

    // Get a sorted list of unique years from the records
    const years = [...new Set(records.map(r => r.year))].sort();

    // Define a color palette for different years
    const colorPalette = ["#062f68", "#4682B4", "#FFA500", "#008000", "#800080"];

    // Define the significant date to mark (January 2024 for New Car)
    const significantYear = "2024";
    const significantMonth = 1; // January

    // Helper function to build dataset for a given data field
    // chartType: "line" or "bar"
    function buildDatasetByYear(dataField, chartType) {
        let datasets = [];
        years.forEach((year, idx) => {
            // Create an array with 12 elements (for 12 months) initialized to null
            let dataForYear = new Array(12).fill(null);

            // Populate the array with values from records for this year
            records.filter(r => r.year === year).forEach(r => {
                // r.month is 1-based; adjust index accordingly
                dataForYear[r.month - 1] = r[dataField];
            });

            // Determine the base color for this year
            const baseColor = colorPalette[idx % colorPalette.length];
            // Build an array for point or bar colors: if the month is the significant date, use red.
            let pointColors = dataForYear.map((val, mIndex) => {
                if (year === significantYear && (mIndex + 1) === significantMonth) {
                    return "red";
                }
                return baseColor;
            });

            if (chartType === "line") {
                datasets.push({
                    label: year,
                    data: dataForYear,
                    borderColor: baseColor,
                    fill: false,
                    spanGaps: true,
                    pointBackgroundColor: pointColors
                });
            } else if (chartType === "bar") {
                datasets.push({
                    label: year,
                    data: dataForYear,
                    backgroundColor: pointColors
                });
            }
        });
        return datasets;
    }

    // Build datasets for the charts using the helper function:
    const energyUsageDatasets = buildDatasetByYear("totalKwh", "line");
    const chargingCostDatasets = buildDatasetByYear("totalCost", "bar");
    const sessionCountDatasets = buildDatasetByYear("sessionCount", "bar");

    // Energy Usage Chart (Line Chart: Overlaid Lines with month labels)
    const energyUsageChart = new Chart(document.getElementById('energyUsageChart'), {
        type: 'line',
        data: {
            labels: monthLabels,
            datasets: energyUsageDatasets
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Monthly Energy Usage (kWh)'
                }
            },
            scales: {
                x: {
                    title: { display: true, text: 'Month' }
                }
            }
        }
    });

    // Charging Cost Chart (Bar Chart: Grouped Bars per month)
    const chargingCostChart = new Chart(document.getElementById('chargingCostChart'), {
        type: 'bar',
        data: {
            labels: monthLabels,
            datasets: chargingCostDatasets
        },
        options: {
            responsive: true,
            scales: {
                x: { 
                    stacked: false,
                    title: { display: true, text: 'Month' }
                },
                y: {
                    stacked: false,
                    beginAtZero: true
                }
            },
            plugins: {
                title: {
                    display: true,
                    text: 'Monthly Charging Costs (£)'
                }
            }
        }
    });

    // Session Count Chart (Bar Chart: Grouped Bars per month)
    const sessionCountChart = new Chart(document.getElementById('sessionCountChart'), {
        type: 'bar',
        data: {
            labels: monthLabels,
            datasets: sessionCountDatasets
        },
        options: {
            responsive: true,
            scales: {
                x: { 
                    stacked: false,
                    title: { display: true, text: 'Month' }
                },
                y: {
                    stacked: false,
                    beginAtZero: true
                }
            },
            plugins: {
                title: {
                    display: true,
                    text: 'Total Charging Sessions per Month'
                }
            }
        }
    });

    // The Average Cost per kWh chart remains unchanged.
    const costPerKwhChart = new Chart(document.getElementById('costPerKwhChart'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode($months); ?>, // using original labels for context
            datasets: [{
                label: 'Average Cost per kWh (£)',
                data: avgCostPerKwh,
                borderColor: '#062f68',
                fill: false
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Average Cost per kWh (£)'
                }
            }
        }
    });
</script>
<?php include '../footer.php'; ?>
