<?php
/**
 * finance.php  (shared, non user-dependent + enhanced 6-month reporting)
 *
 * Improvements in this version:
 * - Existing monthly overview retained.
 * - Rolling 6-month reporting window based on selected month.
 * - 6-month summary cards: income, expenses, net, average monthly net, budget variance.
 * - 6-month monthly trend chart: income, expenses, net.
 * - 6-month budget-line report table: spend, average monthly spend, 6-month budget, variance, status.
 * - Top 10 budget-line spend chart now supports 6-month reporting.
 * - Top movers now compares current 3-month average against previous 3-month average.
 * - CSV export for 6-month budget-line report.
 * - Drill-through reassignment retained.
 * - CSRF protection added for reassignment POST.
 * - Reassignment now validates submitted BudgetLineID values.
 * - Reduced repeated drill trend queries by using one grouped query.
 *
 * Assumes tables:
 *   budget_lines(BudgetLineID, Name, IsEssential, MonthlyBudget)
 *   transactions(TransactionID, TransactionDate, Description, DebitAmount, CreditAmount, Balance, BudgetLineID)
 */

session_start();
require 'config.php';
require 'auth.php';

header('Content-Type: text/html; charset=utf-8');
$link->set_charset('utf8mb4');

/* CDNs */
echo '<script src="https://cdn.tailwindcss.com"></script>';
echo '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';

$errors = [];
$notices = [];

/* ========= Helpers ========= */
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money($value) {
    return '£' . number_format((float)$value, 2);
}

function ym_param($defaultYm = null) {
    $ym = isset($_GET['month']) ? trim($_GET['month']) : '';
    if ($ym && preg_match('/^\d{4}-\d{2}$/', $ym)) return $ym;
    return $defaultYm ?: date('Y-m');
}

function month_bounds($ym) {
    $start = $ym . '-01';
    $end   = date('Y-m-d', strtotime($start . ' +1 month'));
    return [$start, $end];
}

function clean_bool($v) {
    return isset($v) && (string)$v === '1';
}

function month_label($ym, $format = 'F Y') {
    return date($format, strtotime($ym . '-01'));
}

function build_month_series($endYm, $months = 6) {
    $series = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $series[] = date('Y-m', strtotime($endYm . '-01 -' . $i . ' months'));
    }
    return $series;
}

function csrf_token() {
    if (empty($_SESSION['csrf_finance'])) {
        $_SESSION['csrf_finance'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_finance'];
}

function csrf_valid($token) {
    return isset($_SESSION['csrf_finance']) && is_string($token) && hash_equals($_SESSION['csrf_finance'], $token);
}

function status_badge($text, $classes) {
    return '<span class="inline-block px-2 py-0.5 text-xs rounded ' . h($classes) . '">' . h($text) . '</span>';
}

/* ========= Inputs ========= */
$currentYm = ym_param();
[$startDate, $endDate] = month_bounds($currentYm);

$prevYm = date('Y-m', strtotime($currentYm . '-01 -1 month'));
[$prevStart, $prevEnd] = month_bounds($prevYm);

$showAdhoc = clean_bool($_GET['adhoc'] ?? '1');
$csrf = csrf_token();

/* Rolling 6-month report window, inclusive of selected month. */
$reportMonths = 6;
$reportYmList = build_month_series($currentYm, $reportMonths);
$reportStartYm = $reportYmList[0];
$reportEndYm = $reportYmList[$reportMonths - 1];
[$reportStartDate, ] = month_bounds($reportStartYm);
[, $reportEndDate] = month_bounds($reportEndYm);

/* Prior 3 months and current 3 months for momentum/movers. */
$current3StartYm = date('Y-m', strtotime($currentYm . '-01 -2 months'));
[$current3StartDate, ] = month_bounds($current3StartYm);
$current3EndDate = $endDate;

$previous3StartYm = date('Y-m', strtotime($current3StartYm . '-01 -3 months'));
$previous3EndYm = date('Y-m', strtotime($current3StartYm . '-01 -1 month'));
[$previous3StartDate, ] = month_bounds($previous3StartYm);
[, $previous3EndDate] = month_bounds($previous3EndYm);

/* ========= Budget lines ========= */
$budgetLines = [];
$validBudgetLineIDs = [];

$resBL = $link->query('SELECT BudgetLineID, Name, IsEssential, MonthlyBudget FROM budget_lines ORDER BY Name ASC');
if ($resBL) {
    while ($b = $resBL->fetch_assoc()) {
        $bid = (int)$b['BudgetLineID'];
        $budgetLines[] = $b;
        $validBudgetLineIDs[$bid] = true;
    }
} else {
    $errors[] = 'Could not load budget lines.';
}

/* ========= Handle POST: reassignment from drill-down ========= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reassign_tx') {
    if (!csrf_valid($_POST['csrf'] ?? '')) {
        $errors[] = 'Security check failed. Please reload the page and try again.';
    } else {
        $reassign = $_POST['reassign'] ?? [];
        $changed = 0;

        if (is_array($reassign) && !empty($reassign)) {
            $stmt = $link->prepare('UPDATE transactions SET BudgetLineID = ? WHERE TransactionID = ?');
            $stmtNull = $link->prepare('UPDATE transactions SET BudgetLineID = NULL WHERE TransactionID = ?');

            if (!$stmt || !$stmtNull) {
                $errors[] = 'Could not prepare reassignment update.';
            } else {
                foreach ($reassign as $tid => $bid) {
                    $tid = (int)$tid;
                    if ($tid <= 0) continue;

                    if ($bid === '' || $bid === null) {
                        $stmtNull->bind_param('i', $tid);
                        if ($stmtNull->execute()) $changed++;
                    } else {
                        $bid = (int)$bid;
                        if (!isset($validBudgetLineIDs[$bid])) continue;
                        $stmt->bind_param('ii', $bid, $tid);
                        if ($stmt->execute()) $changed++;
                    }
                }
                $stmt->close();
                $stmtNull->close();
            }
        }

        if (empty($errors)) {
            $_SESSION['finance_notice'] = $changed . ' transaction(s) updated.';
            $redir = 'finance.php?month=' . urlencode($currentYm) . '&adhoc=' . ($showAdhoc ? '1' : '0');
            if (!empty($_POST['drill_bl'])) {
                $redir .= '&bl=' . (int)$_POST['drill_bl'] . '#drill';
            }
            header('Location: ' . $redir);
            exit;
        }
    }
}

if (!empty($_SESSION['finance_notice'])) {
    $notices[] = $_SESSION['finance_notice'];
    unset($_SESSION['finance_notice']);
}

/* ========= Month-by-month totals across all available data ========= */
$monthly = [];
$res = $link->query("\n  SELECT DATE_FORMAT(TransactionDate,'%Y-%m') ym, YEAR(TransactionDate) y, MONTH(TransactionDate) m,\n         COALESCE(SUM(CreditAmount),0) cin, COALESCE(SUM(DebitAmount),0) dout\n  FROM transactions\n  GROUP BY y,m\n  ORDER BY y DESC, m DESC\n");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $in  = (float)($r['cin'] ?? 0);
        $out = (float)($r['dout'] ?? 0);
        $monthly[] = [
            'ym'  => $r['ym'],
            'in'  => $in,
            'out' => $out,
            'net' => $in - $out,
            'ok'  => ($in - $out) >= 0,
        ];
    }
}

/* ========= Selected-month totals ========= */
$incomeMonthTotal  = 0.0;
$expenseMonthTotal = 0.0;

$stmtIn = $link->prepare("\n  SELECT COALESCE(SUM(CreditAmount),0) AS in_amt\n  FROM transactions\n  WHERE TransactionDate >= ? AND TransactionDate < ? AND CreditAmount > 0\n");
$stmtIn->bind_param('ss', $startDate, $endDate);
$stmtIn->execute();
$rIn = $stmtIn->get_result()->fetch_assoc();
$incomeMonthTotal = (float)($rIn['in_amt'] ?? 0);
$stmtIn->close();

$stmtOut = $link->prepare("\n  SELECT COALESCE(SUM(DebitAmount),0) AS out_amt\n  FROM transactions\n  WHERE TransactionDate >= ? AND TransactionDate < ? AND DebitAmount > 0\n");
$stmtOut->bind_param('ss', $startDate, $endDate);
$stmtOut->execute();
$rOut = $stmtOut->get_result()->fetch_assoc();
$expenseMonthTotal = (float)($rOut['out_amt'] ?? 0);
$stmtOut->close();

$netMonth = $incomeMonthTotal - $expenseMonthTotal;

/* ========= Spend by Budget Line: current and previous selected month ========= */
$spendThis = [];
$spendPrev = [];

$stmtSpend = $link->prepare("\n  SELECT BudgetLineID, COALESCE(SUM(DebitAmount),0) deb, COALESCE(SUM(CreditAmount),0) cred\n  FROM transactions\n  WHERE BudgetLineID IS NOT NULL\n    AND TransactionDate >= ? AND TransactionDate < ?\n  GROUP BY BudgetLineID\n");
$stmtSpend->bind_param('ss', $startDate, $endDate);
$stmtSpend->execute();
$resS = $stmtSpend->get_result();
while ($r = $resS->fetch_assoc()) {
    $bid = (int)$r['BudgetLineID'];
    $spendThis[$bid] = max(0, (float)$r['deb'] - (float)$r['cred']);
}

$stmtSpend->bind_param('ss', $prevStart, $prevEnd);
$stmtSpend->execute();
$resP = $stmtSpend->get_result();
while ($r = $resP->fetch_assoc()) {
    $bid = (int)$r['BudgetLineID'];
    $spendPrev[$bid] = max(0, (float)$r['deb'] - (float)$r['cred']);
}
$stmtSpend->close();

/* ========= 6-month totals by calendar month ========= */
$reportByMonth = [];
foreach ($reportYmList as $ym) {
    $reportByMonth[$ym] = ['ym' => $ym, 'in' => 0.0, 'out' => 0.0, 'net' => 0.0];
}

$stmtReportMonth = $link->prepare("\n  SELECT DATE_FORMAT(TransactionDate,'%Y-%m') ym,\n         COALESCE(SUM(CreditAmount),0) cin,\n         COALESCE(SUM(DebitAmount),0) dout\n  FROM transactions\n  WHERE TransactionDate >= ? AND TransactionDate < ?\n  GROUP BY ym\n  ORDER BY ym ASC\n");
$stmtReportMonth->bind_param('ss', $reportStartDate, $reportEndDate);
$stmtReportMonth->execute();
$resReportMonth = $stmtReportMonth->get_result();
while ($r = $resReportMonth->fetch_assoc()) {
    $ym = $r['ym'];
    if (!isset($reportByMonth[$ym])) continue;
    $in = (float)$r['cin'];
    $out = (float)$r['dout'];
    $reportByMonth[$ym] = ['ym' => $ym, 'in' => $in, 'out' => $out, 'net' => $in - $out];
}
$stmtReportMonth->close();

$reportIncomeTotal = array_sum(array_column($reportByMonth, 'in'));
$reportExpenseTotal = array_sum(array_column($reportByMonth, 'out'));
$reportNetTotal = $reportIncomeTotal - $reportExpenseTotal;
$reportAverageNet = $reportNetTotal / max(1, $reportMonths);

/* ========= 6-month spend by budget line ========= */
$spend6m = [];
$stmt6mSpend = $link->prepare("\n  SELECT BudgetLineID, COALESCE(SUM(DebitAmount),0) deb, COALESCE(SUM(CreditAmount),0) cred\n  FROM transactions\n  WHERE BudgetLineID IS NOT NULL\n    AND TransactionDate >= ? AND TransactionDate < ?\n  GROUP BY BudgetLineID\n");
$stmt6mSpend->bind_param('ss', $reportStartDate, $reportEndDate);
$stmt6mSpend->execute();
$res6mSpend = $stmt6mSpend->get_result();
while ($r = $res6mSpend->fetch_assoc()) {
    $bid = (int)$r['BudgetLineID'];
    $spend6m[$bid] = max(0, (float)$r['deb'] - (float)$r['cred']);
}
$stmt6mSpend->close();

/* ========= Current 3-month vs previous 3-month movers ========= */
$spendCurrent3 = [];
$spendPrevious3 = [];

$stmt3m = $link->prepare("\n  SELECT BudgetLineID, COALESCE(SUM(DebitAmount),0) deb, COALESCE(SUM(CreditAmount),0) cred\n  FROM transactions\n  WHERE BudgetLineID IS NOT NULL\n    AND TransactionDate >= ? AND TransactionDate < ?\n  GROUP BY BudgetLineID\n");
$stmt3m->bind_param('ss', $current3StartDate, $current3EndDate);
$stmt3m->execute();
$resCurrent3 = $stmt3m->get_result();
while ($r = $resCurrent3->fetch_assoc()) {
    $bid = (int)$r['BudgetLineID'];
    $spendCurrent3[$bid] = max(0, (float)$r['deb'] - (float)$r['cred']);
}

$stmt3m->bind_param('ss', $previous3StartDate, $previous3EndDate);
$stmt3m->execute();
$resPrevious3 = $stmt3m->get_result();
while ($r = $resPrevious3->fetch_assoc()) {
    $bid = (int)$r['BudgetLineID'];
    $spendPrevious3[$bid] = max(0, (float)$r['deb'] - (float)$r['cred']);
}
$stmt3m->close();

/* ========= Build Budget Line rows ========= */
$byLine = [];
$reportByLine = [];
$total6mBudget = 0.0;
$total6mSpendBudgeted = 0.0;

foreach ($budgetLines as $bl) {
    $bid = (int)$bl['BudgetLineID'];
    $budget = is_null($bl['MonthlyBudget']) ? null : (float)$bl['MonthlyBudget'];
    $isAdHoc = (is_null($budget) || $budget <= 0);

    $spend = $spendThis[$bid] ?? 0.0;
    $prev = $spendPrev[$bid] ?? 0.0;
    $delta = $spend - $prev;
    $variance = is_null($budget) ? null : ($budget - $spend);

    $sixSpend = $spend6m[$bid] ?? 0.0;
    $sixBudget = $isAdHoc ? null : ($budget * $reportMonths);
    $sixVariance = is_null($sixBudget) ? null : ($sixBudget - $sixSpend);
    $avgMonthlySpend = $sixSpend / max(1, $reportMonths);

    if (!$isAdHoc) {
        $total6mBudget += $sixBudget;
        $total6mSpendBudgeted += $sixSpend;
    }

    $current3Avg = ($spendCurrent3[$bid] ?? 0.0) / 3;
    $previous3Avg = ($spendPrevious3[$bid] ?? 0.0) / 3;
    $momentum = $current3Avg - $previous3Avg;

    $row = [
        'BudgetLineID'     => $bid,
        'Name'             => $bl['Name'],
        'IsEssential'      => (int)$bl['IsEssential'],
        'Budget'           => $budget,
        'Spend'            => $spend,
        'PrevSpend'        => $prev,
        'Delta'            => $delta,
        'Variance'         => $variance,
        'Adhoc'            => $isAdHoc ? 1 : 0,
        'Spend6m'          => $sixSpend,
        'Budget6m'         => $sixBudget,
        'Variance6m'       => $sixVariance,
        'AvgMonthlySpend'  => $avgMonthlySpend,
        'Current3Avg'      => $current3Avg,
        'Previous3Avg'     => $previous3Avg,
        'Momentum'         => $momentum,
    ];

    $byLine[] = $row;
    $reportByLine[] = $row;
}

if (!$showAdhoc) {
    $byLine = array_values(array_filter($byLine, fn($r) => (int)$r['Adhoc'] === 0));
    $reportByLine = array_values(array_filter($reportByLine, fn($r) => (int)$r['Adhoc'] === 0));
}

$total6mVariance = $total6mBudget - $total6mSpendBudgeted;

/* Monthly budget-line table default order. */
usort($byLine, fn($a, $b) => $b['Spend'] <=> $a['Spend']);

/* 6-month budget-line report default order. */
usort($reportByLine, fn($a, $b) => $b['Spend6m'] <=> $a['Spend6m']);

/* ========= Drill-down data ========= */
$drillLineID = isset($_GET['bl']) ? (int)$_GET['bl'] : 0;
$drill = ['enabled' => false, 'line' => null, 'tx' => [], 'trend' => []];

if ($drillLineID > 0) {
    $stmtLine = $link->prepare('SELECT BudgetLineID, Name, IsEssential, MonthlyBudget FROM budget_lines WHERE BudgetLineID = ? LIMIT 1');
    $stmtLine->bind_param('i', $drillLineID);
    $stmtLine->execute();
    $lineMeta = $stmtLine->get_result()->fetch_assoc();
    $stmtLine->close();

    if ($lineMeta) {
        $drill['enabled'] = true;
        $drill['line'] = [
            'BudgetLineID' => (int)$lineMeta['BudgetLineID'],
            'Name'         => $lineMeta['Name'],
            'IsEssential'  => (int)$lineMeta['IsEssential'],
            'Budget'       => is_null($lineMeta['MonthlyBudget']) ? null : (float)$lineMeta['MonthlyBudget'],
        ];

        $stmtTx = $link->prepare("\n            SELECT TransactionID, TransactionDate, Description, DebitAmount, CreditAmount, BudgetLineID\n            FROM transactions\n            WHERE BudgetLineID = ?\n              AND TransactionDate >= ? AND TransactionDate < ?\n            ORDER BY TransactionDate DESC, TransactionID DESC\n        ");
        $stmtTx->bind_param('iss', $drillLineID, $startDate, $endDate);
        $stmtTx->execute();
        $resTx = $stmtTx->get_result();
        while ($t = $resTx->fetch_assoc()) {
            $drill['tx'][] = [
                'id'     => (int)$t['TransactionID'],
                'date'   => $t['TransactionDate'],
                'desc'   => $t['Description'],
                'debit'  => (float)$t['DebitAmount'],
                'credit' => (float)$t['CreditAmount'],
                'bid'    => is_null($t['BudgetLineID']) ? null : (int)$t['BudgetLineID'],
            ];
        }
        $stmtTx->close();

        $trendMonths = build_month_series($currentYm, 12);
        $trendStartYm = $trendMonths[0];
        $trendEndYm = $trendMonths[count($trendMonths) - 1];
        [$trendStartDate, ] = month_bounds($trendStartYm);
        [, $trendEndDate] = month_bounds($trendEndYm);

        $trendByYm = [];
        foreach ($trendMonths as $ym) {
            $trendByYm[$ym] = 0.0;
        }

        $stmtTrend = $link->prepare("\n            SELECT DATE_FORMAT(TransactionDate,'%Y-%m') ym,\n                   COALESCE(SUM(DebitAmount),0) deb,\n                   COALESCE(SUM(CreditAmount),0) cred\n            FROM transactions\n            WHERE BudgetLineID = ?\n              AND TransactionDate >= ? AND TransactionDate < ?\n            GROUP BY ym\n            ORDER BY ym ASC\n        ");
        $stmtTrend->bind_param('iss', $drillLineID, $trendStartDate, $trendEndDate);
        $stmtTrend->execute();
        $resTrend = $stmtTrend->get_result();
        while ($rT = $resTrend->fetch_assoc()) {
            $ym = $rT['ym'];
            if (!isset($trendByYm[$ym])) continue;
            $trendByYm[$ym] = max(0, (float)$rT['deb'] - (float)$rT['cred']);
        }
        $stmtTrend->close();

        foreach ($trendByYm as $ym => $spend) {
            $drill['trend'][] = [
                'ym'     => $ym,
                'label'  => month_label($ym, 'M y'),
                'spend'  => $spend,
                'budget' => is_null($drill['line']['Budget']) ? null : (float)$drill['line']['Budget'],
            ];
        }
    }
}

/* ========= Reports: Top 10 and movers ========= */
$top10Six = array_slice($reportByLine, 0, 10);
$reportLabels = array_map(fn($r) => $r['Name'], $top10Six);
$reportSixSpend = array_map(fn($r) => round($r['Spend6m'], 2), $top10Six);
$reportSixBudget = array_map(fn($r) => is_null($r['Budget6m']) ? null : round($r['Budget6m'], 2), $top10Six);

$movers = $reportByLine;
usort($movers, fn($a, $b) => abs($b['Momentum']) <=> abs($a['Momentum']));
$topIncreases = array_slice(array_values(array_filter($movers, fn($r) => $r['Momentum'] > 0.005)), 0, 5);
$topDecreases = array_slice(array_values(array_filter($movers, fn($r) => $r['Momentum'] < -0.005)), 0, 5);

/* Chart data */
$reportMonthLabels = array_map(fn($ym) => month_label($ym, 'M y'), array_keys($reportByMonth));
$reportMonthIncome = array_map(fn($r) => round($r['in'], 2), array_values($reportByMonth));
$reportMonthExpense = array_map(fn($r) => round($r['out'], 2), array_values($reportByMonth));
$reportMonthNet = array_map(fn($r) => round($r['net'], 2), array_values($reportByMonth));

$link->close();
?>
<?php include 'header.php'; ?>

<div class="max-w-7xl mx-auto p-4 space-y-6">
  <div class="flex flex-wrap items-center justify-between gap-2">
    <div>
      <h1 class="text-2xl font-semibold">Finance Overview</h1>
      <p class="text-sm text-gray-500 mt-1">Monthly control with rolling 6-month reporting.</p>
    </div>
    <div class="flex gap-2">
      <a href="import_finance.php" class="inline-flex items-center px-4 py-2 rounded bg-blue-600 hover:bg-blue-700 text-white">Import CSV</a>
      <a href="budget_manager.php" class="inline-flex items-center px-4 py-2 rounded bg-indigo-600 hover:bg-indigo-700 text-white">Budget Manager</a>
    </div>
  </div>

  <?php if (!empty($errors)): ?>
    <div class="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-800">
      <ul class="list-disc pl-5 space-y-1">
        <?php foreach ($errors as $error): ?>
          <li><?= h($error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if (!empty($notices)): ?>
    <div class="rounded border border-green-200 bg-green-50 p-3 text-sm text-green-800">
      <ul class="list-disc pl-5 space-y-1">
        <?php foreach ($notices as $notice): ?>
          <li><?= h($notice) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="flex flex-wrap items-center justify-between gap-2">
    <form method="GET" class="flex flex-wrap items-center gap-2">
      <label class="text-sm text-gray-600">Month</label>
      <select name="month" class="border rounded p-1 text-sm">
        <?php
          $have = array_column($monthly, 'ym');
          if (!in_array($currentYm, $have, true)) $have[] = $currentYm;
          $have = array_unique($have);
          sort($have);
          foreach ($have as $ym) {
            $sel = ($ym === $currentYm) ? 'selected' : '';
            echo '<option value="' . h($ym) . '" ' . $sel . '>' . h(month_label($ym)) . '</option>';
          }
        ?>
      </select>

      <label class="text-sm text-gray-600 ml-2">Show Ad-hoc</label>
      <select name="adhoc" class="border rounded p-1 text-sm">
        <option value="1" <?= $showAdhoc ? 'selected' : ''; ?>>Yes</option>
        <option value="0" <?= !$showAdhoc ? 'selected' : ''; ?>>No</option>
      </select>

      <button class="inline-flex items-center px-3 py-1.5 rounded bg-gray-800 text-white text-sm hover:bg-black">Apply</button>
    </form>

    <div class="grid grid-cols-3 gap-3 w-full md:w-auto">
      <div class="rounded border bg-white p-3 shadow-sm">
        <div class="text-xs text-gray-500">Money In</div>
        <div class="text-xl font-semibold mt-1 text-blue-700"><?= money($incomeMonthTotal) ?></div>
      </div>
      <div class="rounded border bg-white p-3 shadow-sm">
        <div class="text-xs text-gray-500">Money Out</div>
        <div class="text-xl font-semibold mt-1 text-red-700"><?= money($expenseMonthTotal) ?></div>
      </div>
      <div class="rounded border bg-white p-3 shadow-sm">
        <div class="text-xs text-gray-500">Net</div>
        <div class="text-xl font-semibold mt-1 <?= $netMonth >= 0 ? 'text-green-700' : 'text-red-700' ?>"><?= money($netMonth) ?></div>
      </div>
    </div>
  </div>

  <!-- 6-month reporting summary -->
  <div class="rounded border bg-white p-4 shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
      <div>
        <h3 class="text-base font-semibold">6-Month Report</h3>
        <div class="text-xs text-gray-500"><?= h(month_label($reportStartYm)) ?> to <?= h(month_label($reportEndYm)) ?></div>
      </div>
      <button id="exportSixMonthReport" class="text-sm border px-3 py-1.5 rounded hover:bg-gray-50">Export 6-Month CSV</button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-5 gap-3 mb-4">
      <div class="rounded border bg-gray-50 p-3">
        <div class="text-xs text-gray-500">6M Income</div>
        <div class="text-lg font-semibold text-blue-700"><?= money($reportIncomeTotal) ?></div>
      </div>
      <div class="rounded border bg-gray-50 p-3">
        <div class="text-xs text-gray-500">6M Expenses</div>
        <div class="text-lg font-semibold text-red-700"><?= money($reportExpenseTotal) ?></div>
      </div>
      <div class="rounded border bg-gray-50 p-3">
        <div class="text-xs text-gray-500">6M Net</div>
        <div class="text-lg font-semibold <?= $reportNetTotal >= 0 ? 'text-green-700' : 'text-red-700' ?>"><?= money($reportNetTotal) ?></div>
      </div>
      <div class="rounded border bg-gray-50 p-3">
        <div class="text-xs text-gray-500">Avg Monthly Net</div>
        <div class="text-lg font-semibold <?= $reportAverageNet >= 0 ? 'text-green-700' : 'text-red-700' ?>"><?= money($reportAverageNet) ?></div>
      </div>
      <div class="rounded border bg-gray-50 p-3">
        <div class="text-xs text-gray-500">Budget Variance</div>
        <div class="text-lg font-semibold <?= $total6mVariance >= 0 ? 'text-green-700' : 'text-red-700' ?>"><?= money($total6mVariance) ?></div>
      </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <div class="lg:col-span-2 rounded border bg-white p-3">
        <h4 class="text-sm font-semibold mb-2">Monthly Trend</h4>
        <canvas id="sixMonthTrendChart" height="170"></canvas>
      </div>
      <div class="rounded border bg-white p-3">
        <h4 class="text-sm font-semibold mb-2">Momentum</h4>
        <p class="text-xs text-gray-500 mb-3">Current 3-month average compared with previous 3-month average.</p>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-1 gap-4">
          <div>
            <div class="text-xs text-gray-500 mb-1">Largest Increases</div>
            <ul class="text-sm space-y-1">
              <?php if (empty($topIncreases)): ?>
                <li class="text-gray-500">No increases.</li>
              <?php else: foreach ($topIncreases as $r): ?>
                <li><span class="font-medium"><?= h($r['Name']) ?></span> <span class="text-red-600">+<?= money($r['Momentum']) ?>/mo</span></li>
              <?php endforeach; endif; ?>
            </ul>
          </div>
          <div>
            <div class="text-xs text-gray-500 mb-1">Largest Decreases</div>
            <ul class="text-sm space-y-1">
              <?php if (empty($topDecreases)): ?>
                <li class="text-gray-500">No decreases.</li>
              <?php else: foreach ($topDecreases as $r): ?>
                <li><span class="font-medium"><?= h($r['Name']) ?></span> <span class="text-green-700">-<?= money(abs($r['Momentum'])) ?>/mo</span></li>
              <?php endforeach; endif; ?>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- 6-month budget-line report -->
  <div class="rounded border bg-white p-4 shadow-sm">
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-base font-semibold">6-Month Budget-Line Report</h3>
      <div class="text-xs text-gray-500">Spend, average monthly spend, budget and variance.</div>
    </div>
    <div class="overflow-auto">
      <table id="sixMonthReportTable" class="min-w-full text-sm text-left border-collapse">
        <thead class="bg-gray-100">
          <tr>
            <th class="px-3 py-2 border cursor-pointer" data-sort="text">Budget Line</th>
            <th class="px-3 py-2 border">Type</th>
            <th class="px-3 py-2 border text-right cursor-pointer" data-sort="num">6M Spend</th>
            <th class="px-3 py-2 border text-right cursor-pointer" data-sort="num">Avg / Month</th>
            <th class="px-3 py-2 border text-right cursor-pointer" data-sort="num">6M Budget</th>
            <th class="px-3 py-2 border text-right cursor-pointer" data-sort="num">6M Variance</th>
            <th class="px-3 py-2 border">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($reportByLine)): ?>
            <tr><td colspan="7" class="px-3 py-4 text-center text-gray-500">No budget-line data for the 6-month window.</td></tr>
          <?php else: foreach ($reportByLine as $r):
            $isAdHoc = (int)$r['Adhoc'] === 1;
            $ok6 = (!$isAdHoc && $r['Variance6m'] >= 0);
            $href = '?month=' . urlencode($currentYm) . '&adhoc=' . ($showAdhoc ? 1 : 0) . '&bl=' . $r['BudgetLineID'] . '#drill';
          ?>
            <tr class="hover:bg-gray-50 cursor-pointer" onclick="location.href='<?= h($href) ?>'"
                data-name="<?= h(mb_strtolower($r['Name'], 'UTF-8')) ?>"
                data-spend6="<?= h(number_format($r['Spend6m'], 2, '.', '')) ?>"
                data-avg="<?= h(number_format($r['AvgMonthlySpend'], 2, '.', '')) ?>"
                data-budget6="<?= is_null($r['Budget6m']) ? '' : h(number_format($r['Budget6m'], 2, '.', '')) ?>"
                data-variance6="<?= is_null($r['Variance6m']) ? '' : h(number_format($r['Variance6m'], 2, '.', '')) ?>">
              <td class="px-3 py-2 border"><?= h($r['Name']) ?></td>
              <td class="px-3 py-2 border"><?= $r['IsEssential'] ? status_badge('Essential', 'bg-blue-100 text-blue-800') : status_badge('Discretionary', 'bg-gray-100 text-gray-800') ?></td>
              <td class="px-3 py-2 border text-right"><?= money($r['Spend6m']) ?></td>
              <td class="px-3 py-2 border text-right"><?= money($r['AvgMonthlySpend']) ?></td>
              <td class="px-3 py-2 border text-right"><?= $isAdHoc ? '—' : money($r['Budget6m']) ?></td>
              <td class="px-3 py-2 border text-right <?= !$isAdHoc && $r['Variance6m'] < 0 ? 'text-red-700 font-medium' : 'text-gray-900' ?>"><?= $isAdHoc ? '—' : money($r['Variance6m']) ?></td>
              <td class="px-3 py-2 border">
                <?php if ($isAdHoc): ?>
                  <?= status_badge('Ad hoc', 'bg-amber-100 text-amber-800') ?>
                <?php elseif ($ok6): ?>
                  <?= status_badge('Within 6M budget', 'bg-green-100 text-green-800') ?>
                <?php else: ?>
                  <?= status_badge('Over 6M budget', 'bg-red-100 text-red-800') ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Month-by-month table -->
  <div class="rounded border bg-white p-4 shadow-sm">
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-base font-semibold">Month-by-Month</h3>
      <div class="text-xs text-gray-500">Income, expenses and net by calendar month.</div>
    </div>
    <div class="overflow-auto">
      <table class="min-w-full text-sm text-left border-collapse">
        <thead class="bg-gray-100">
          <tr>
            <th class="px-3 py-2 border">Month</th>
            <th class="px-3 py-2 border text-right">In</th>
            <th class="px-3 py-2 border text-right">Out</th>
            <th class="px-3 py-2 border text-right">Net</th>
            <th class="px-3 py-2 border">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($monthly)): ?>
            <tr><td colspan="5" class="px-3 py-4 text-center text-gray-500">No transactions yet.</td></tr>
          <?php else: foreach (array_reverse($monthly) as $m):
              $ok = $m['ok']; ?>
              <tr class="hover:bg-gray-50">
                <td class="px-3 py-2 border whitespace-nowrap"><?= h(month_label($m['ym'])) ?></td>
                <td class="px-3 py-2 border text-right"><?= money($m['in']) ?></td>
                <td class="px-3 py-2 border text-right"><?= money($m['out']) ?></td>
                <td class="px-3 py-2 border text-right font-medium <?= $ok ? 'text-green-700' : 'text-red-700' ?>"><?= money($m['net']) ?></td>
                <td class="px-3 py-2 border"><?= $ok ? status_badge('In credit', 'bg-green-100 text-green-800') : status_badge('Deficit', 'bg-red-100 text-red-800') ?></td>
              </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Budget Lines monthly table -->
  <div class="rounded border bg-white p-4 shadow-sm">
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-base font-semibold">Budget Lines (<?= h(month_label($currentYm)) ?>)</h3>
      <div class="text-xs text-gray-500">Click headers to sort. Trend compares against <?= h(month_label($prevYm, 'M Y')) ?>.</div>
    </div>
    <div class="overflow-auto">
      <table id="blTable" class="min-w-full text-sm text-left border-collapse">
        <thead class="bg-gray-100">
          <tr>
            <th class="px-3 py-2 border cursor-pointer" data-sort="text">Budget Line</th>
            <th class="px-3 py-2 border">Essential</th>
            <th class="px-3 py-2 border text-right cursor-pointer" data-sort="num">Budget (mo)</th>
            <th class="px-3 py-2 border text-right cursor-pointer" data-sort="num" data-default="desc">Spend</th>
            <th class="px-3 py-2 border text-right cursor-pointer" data-sort="num">Variance</th>
            <th class="px-3 py-2 border">Status</th>
            <th class="px-3 py-2 border text-right cursor-pointer" data-sort="num">Trend vs Last</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($byLine)): ?>
            <tr><td colspan="7" class="px-3 py-4 text-center text-gray-500">No budget lines to show.</td></tr>
          <?php else: foreach ($byLine as $r):
              $isAdHoc = (int)$r['Adhoc'] === 1;
              $ok = (!$isAdHoc && $r['Variance'] >= 0);
              $href = '?month=' . urlencode($currentYm) . '&adhoc=' . ($showAdhoc ? 1 : 0) . '&bl=' . $r['BudgetLineID'] . '#drill';
              $arrow = '▬';
              $arrowClass = 'text-gray-500';
              if ($r['Delta'] > 0.005) { $arrow = '▲'; $arrowClass = 'text-red-600'; }
              elseif ($r['Delta'] < -0.005) { $arrow = '▼'; $arrowClass = 'text-green-700'; }
              $trendText = ($r['Delta'] >= 0 ? '+' : '') . number_format($r['Delta'], 2);
          ?>
            <tr class="hover:bg-gray-50 cursor-pointer" onclick="location.href='<?= h($href) ?>'"
                data-name="<?= h(mb_strtolower($r['Name'], 'UTF-8')) ?>"
                data-budget="<?= is_null($r['Budget']) ? '' : h(number_format($r['Budget'], 2, '.', '')) ?>"
                data-spend="<?= h(number_format($r['Spend'], 2, '.', '')) ?>"
                data-variance="<?= is_null($r['Variance']) ? '' : h(number_format($r['Variance'], 2, '.', '')) ?>"
                data-trend="<?= h(number_format($r['Delta'], 2, '.', '')) ?>">
              <td class="px-3 py-2 border"><?= h($r['Name']) ?></td>
              <td class="px-3 py-2 border"><?= $r['IsEssential'] ? status_badge('Essential', 'bg-blue-100 text-blue-800') : status_badge('Discretionary', 'bg-gray-100 text-gray-800') ?></td>
              <td class="px-3 py-2 border text-right"><?= $isAdHoc ? '—' : money($r['Budget']) ?></td>
              <td class="px-3 py-2 border text-right"><?= money($r['Spend']) ?></td>
              <td class="px-3 py-2 border text-right"><?= $isAdHoc ? '—' : money($r['Variance']) ?></td>
              <td class="px-3 py-2 border">
                <?php if ($isAdHoc): ?>
                  <?= status_badge('Ad hoc', 'bg-amber-100 text-amber-800') ?>
                <?php elseif ($ok): ?>
                  <?= status_badge('Within budget', 'bg-green-100 text-green-800') ?>
                <?php else: ?>
                  <?= status_badge('Over budget', 'bg-red-100 text-red-800') ?>
                <?php endif; ?>
              </td>
              <td class="px-3 py-2 border text-right">
                <span class="<?= h($arrowClass) ?> font-semibold mr-1"><?= h($arrow) ?></span>
                <span class="<?= $r['Delta'] > 0 ? 'text-red-600' : ($r['Delta'] < 0 ? 'text-green-700' : 'text-gray-600') ?>">
                  £<?= h($trendText) ?>
                </span>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Reports: Top 10 -->
  <div class="rounded border bg-white p-4 shadow-sm">
    <div class="flex items-center justify-between mb-3">
      <h3 class="text-base font-semibold">Top 10 Budget Lines by 6-Month Spend</h3>
      <div class="text-xs text-gray-500">Spend compared with 6-month budget.</div>
    </div>

    <div class="rounded border bg-white p-3">
      <canvas id="top10Chart" height="200"></canvas>
    </div>
  </div>
</div>

<!-- Drill-down drawer with reassignment -->
<?php if ($drill['enabled']):
  $line = $drill['line'];
  $trend = $drill['trend'];
  $tx = $drill['tx'];
  $returnHref = '?month=' . urlencode($currentYm) . '&adhoc=' . ($showAdhoc ? 1 : 0);
?>
<a id="drill"></a>
<div class="fixed inset-0 z-40">
  <div class="absolute inset-0 bg-black/30" onclick="location.href='<?= h($returnHref) ?>';"></div>
  <div class="absolute right-0 top-0 h-full w-full sm:w-[680px] bg-white shadow-xl overflow-y-auto">
    <div class="p-4 border-b flex items-center justify-between">
      <div>
        <h3 class="text-lg font-semibold"><?= h($line['Name']) ?></h3>
        <div class="text-xs text-gray-600"><?= h(month_label($currentYm)) ?></div>
      </div>
      <a class="text-gray-600 hover:text-gray-800" href="<?= h($returnHref) ?>">✕</a>
    </div>

    <div class="p-4 space-y-6">
      <div class="rounded border bg-white p-3">
        <div class="flex items-center justify-between mb-2">
          <h4 class="text-sm font-semibold">12-Month Trend (Spend vs Budget)</h4>
          <?php if (is_null($line['Budget']) || $line['Budget'] <= 0): ?>
            <?= status_badge('Ad hoc - no fixed budget', 'bg-amber-100 text-amber-800') ?>
          <?php else: ?>
            <span class="text-xs text-gray-600">Budget: <?= money($line['Budget']) ?>/mo</span>
          <?php endif; ?>
        </div>
        <canvas id="trendChart" height="160"></canvas>
      </div>

      <div class="rounded border bg-white p-3">
        <div class="flex items-center justify-between mb-3">
          <h4 class="text-sm font-semibold">Transactions (<?= h(month_label($currentYm)) ?>)</h4>
          <button id="exportCsv" class="text-sm border px-3 py-1 rounded hover:bg-gray-50" type="button">Export CSV</button>
        </div>

        <form method="POST" class="space-y-3">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
          <input type="hidden" name="action" value="reassign_tx">
          <input type="hidden" name="drill_bl" value="<?= (int)$line['BudgetLineID'] ?>">
          <div class="overflow-auto">
            <table id="txDrillTable" class="min-w-full text-sm text-left border-collapse">
              <thead class="bg-gray-100">
                <tr>
                  <th class="px-3 py-2 border">Date</th>
                  <th class="px-3 py-2 border">Description</th>
                  <th class="px-3 py-2 border text-right">Debit</th>
                  <th class="px-3 py-2 border text-right">Credit</th>
                  <th class="px-3 py-2 border">Budget Line</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($tx)): ?>
                  <tr><td colspan="5" class="px-3 py-4 text-center text-gray-500">No transactions for this line in this month.</td></tr>
                <?php else: foreach ($tx as $t): ?>
                  <tr class="hover:bg-gray-50">
                    <td class="px-3 py-2 border whitespace-nowrap"><?= h($t['date']) ?></td>
                    <td class="px-3 py-2 border"><?= h($t['desc']) ?></td>
                    <td class="px-3 py-2 border text-right"><?= $t['debit'] > 0 ? money($t['debit']) : '' ?></td>
                    <td class="px-3 py-2 border text-right"><?= $t['credit'] > 0 ? money($t['credit']) : '' ?></td>
                    <td class="px-3 py-2 border">
                      <select name="reassign[<?= (int)$t['id'] ?>]" class="w-full border rounded p-1">
                        <option value="">— None —</option>
                        <?php foreach ($budgetLines as $bl): ?>
                          <option value="<?= (int)$bl['BudgetLineID'] ?>" <?= ($t['bid'] === (int)$bl['BudgetLineID'] ? 'selected' : '') ?>>
                            <?= h($bl['Name']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                  </tr>
                <?php endforeach; endif; ?>
              </tbody>
            </table>
          </div>
          <div class="flex items-center justify-end">
            <button type="submit" class="inline-flex items-center px-4 py-2 rounded bg-green-600 hover:bg-green-700 text-white">
              Save Reassignments
            </button>
          </div>
        </form>
      </div>
    </div>

    <script>
      (function(){
        const labels = <?= json_encode(array_column($trend, 'label')) ?>;
        const spend  = <?= json_encode(array_map(fn($x) => round($x['spend'], 2), $trend)) ?>;
        const budget = <?= json_encode(array_map(fn($x) => is_null($x['budget']) ? null : round($x['budget'], 2), $trend)) ?>;
        const canvas = document.getElementById('trendChart');
        if (!canvas) return;

        new Chart(canvas.getContext('2d'), {
          type: 'line',
          data: {
            labels,
            datasets: [
              { label: 'Spend', data: spend, borderWidth: 2, tension: 0.25 },
              { label: 'Budget', data: budget, borderDash: [6, 6], borderWidth: 2, tension: 0 }
            ]
          },
          options: {
            responsive: true,
            plugins: { legend: { position: 'bottom' }, tooltip: { mode: 'index', intersect: false } },
            scales: { y: { ticks: { callback: v => '£' + Number(v).toFixed(0) } } }
          }
        });
      })();

      document.getElementById('exportCsv')?.addEventListener('click', () => {
        const rows = Array.from(document.querySelectorAll('#txDrillTable tr'));
        const csv = rows.map(row =>
          Array.from(row.querySelectorAll('th,td'))
            .map(cell => `"${cell.innerText.replace(/"/g, '""')}"`)
            .join(',')
        ).join('\n');
        const blob = new Blob([csv], {type: 'text/csv;charset=utf-8;'});
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = '<?= h(preg_replace('/[^A-Za-z0-9_\-]/', '_', $line['Name'])) ?>_<?= h($currentYm) ?>.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
      });
    </script>
  </div>
</div>
<?php endif; ?>

<script>
  function sortTable(tableId, numericMap = {}) {
    const table = document.getElementById(tableId);
    if (!table) return;

    const tbody = table.querySelector('tbody');
    const headers = table.querySelectorAll('thead th[data-sort]');
    let currentSort = { idx: -1, dir: 'asc' };

    function getCellValue(tr, idx, type) {
      if (type === 'text') {
        if (idx === 0) return (tr.getAttribute('data-name') || '').toString();
        return (tr.cells[idx]?.innerText || '').toLowerCase();
      }

      const attr = numericMap[idx];
      const v = attr ? tr.getAttribute(attr) : (tr.cells[idx]?.innerText || '');
      const n = parseFloat((v || '').toString().replace(/[^\d.\-]/g, ''));
      if (Number.isNaN(n)) return -Infinity;
      return n;
    }

    function sortBy(idx, type, dir) {
      const rows = Array.from(tbody.querySelectorAll('tr')).filter(r => r.cells.length > 1);
      rows.sort((a, b) => {
        const va = getCellValue(a, idx, type);
        const vb = getCellValue(b, idx, type);
        if (va < vb) return dir === 'asc' ? -1 : 1;
        if (va > vb) return dir === 'asc' ? 1 : -1;
        return 0;
      });
      rows.forEach(r => tbody.appendChild(r));
      headers.forEach(h => h.classList.remove('bg-gray-200'));
      headers[idx]?.classList.add('bg-gray-200');
    }

    headers.forEach((h, idx) => {
      h.addEventListener('click', () => {
        const type = h.getAttribute('data-sort');
        let dir = 'asc';
        if (currentSort.idx === idx) dir = (currentSort.dir === 'asc') ? 'desc' : 'asc';
        currentSort = { idx, dir };
        sortBy(idx, type, dir);
      });

      if (h.getAttribute('data-default') === 'desc') {
        currentSort = { idx, dir: 'desc' };
        sortBy(idx, h.getAttribute('data-sort'), 'desc');
      }
    });
  }

  sortTable('blTable', {2: 'data-budget', 3: 'data-spend', 4: 'data-variance', 6: 'data-trend'});
  sortTable('sixMonthReportTable', {2: 'data-spend6', 3: 'data-avg', 4: 'data-budget6', 5: 'data-variance6'});

  (function(){
    const canvas = document.getElementById('sixMonthTrendChart');
    if (!canvas) return;

    new Chart(canvas.getContext('2d'), {
      type: 'line',
      data: {
        labels: <?= json_encode($reportMonthLabels) ?>,
        datasets: [
          { label: 'Income', data: <?= json_encode($reportMonthIncome) ?>, borderWidth: 2, tension: 0.25 },
          { label: 'Expenses', data: <?= json_encode($reportMonthExpense) ?>, borderWidth: 2, tension: 0.25 },
          { label: 'Net', data: <?= json_encode($reportMonthNet) ?>, borderWidth: 2, tension: 0.25 }
        ]
      },
      options: {
        responsive: true,
        plugins: { legend: { position: 'bottom' }, tooltip: { mode: 'index', intersect: false } },
        scales: { y: { ticks: { callback: v => '£' + Number(v).toFixed(0) } } }
      }
    });
  })();

  (function(){
    const canvas = document.getElementById('top10Chart');
    if (!canvas) return;

    new Chart(canvas.getContext('2d'), {
      type: 'bar',
      data: {
        labels: <?= json_encode($reportLabels) ?>,
        datasets: [
          { label: '6M Spend', data: <?= json_encode($reportSixSpend) ?> },
          { label: '6M Budget', data: <?= json_encode($reportSixBudget) ?> }
        ]
      },
      options: {
        responsive: true,
        plugins: { legend: { position: 'bottom' } },
        scales: { y: { ticks: { callback: v => '£' + Number(v).toFixed(0) } } }
      }
    });
  })();

  document.getElementById('exportSixMonthReport')?.addEventListener('click', () => {
    const table = document.getElementById('sixMonthReportTable');
    if (!table) return;

    const rows = Array.from(table.querySelectorAll('tr'));
    const csv = rows.map(row =>
      Array.from(row.querySelectorAll('th,td'))
        .map(cell => `"${cell.innerText.replace(/"/g, '""')}"`)
        .join(',')
    ).join('\n');

    const blob = new Blob([csv], {type: 'text/csv;charset=utf-8;'});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'finance_6_month_report_<?= h($reportStartYm) ?>_to_<?= h($reportEndYm) ?>.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  });
</script>

<?php include 'footer.php'; ?>
