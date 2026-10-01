<?php include 'header.php'; ?>
<?php include 'config.php'; ?>
<?php include 'functions.php'; ?>

<?php
// Fetch months with data
$months = getMonths($link);
?>

<div class="container mt-5">
    <h1 class="mb-4">Charging Sessions Report</h1>

    <?php foreach ($months as $month): ?>
        <div class="card mb-3">
            <div class="card-header">
                <strong><?php echo date('F Y', strtotime($month . '-01')); ?></strong>
                <span class="badge bg-<?php echo isMonthPaid($link, $month) ? 'success' : 'danger'; ?>">
                    <?php echo isMonthPaid($link, $month) ? 'Paid' : 'Not Paid'; ?>
                </span>
                <a href="payment.php?month=<?php echo $month; ?>" class="btn btn-primary btn-sm float-end">
                    More Info
                </a>
            </div>
            <div class="card-body">
                <h5>Total Cost: £<?php echo number_format(getTotalCostByMonth($link, $month), 2); ?></h5>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.7/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.min.js"></script>
</body>
</html>
