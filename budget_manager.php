<?php
/**
 * budget_manager.php
 *
 * Manage budget lines using data-driven suggestions.
 * - Tailwind CDN styling
 * - Chart.js for per-line 12-month trend
 * - Suggest budgets by: Last Month, Avg(3m), Median(12m), P80(6m)
 * - Apply suggestions in bulk or per-line
 * - Create, edit (name, essential), delete, and merge lines
 * - All spend calculations treat refunds as reductions: max(0, SUM(Debit) - SUM(Credit))
 */

session_start();
require 'config.php';
require 'auth.php';

header('Content-Type: text/html; charset=utf-8');
$link->set_charset("utf8mb4");

$userID = $_SESSION['userID'] ?? 0;
$errors = [];
$notices = [];

/* ===== Utilities ===== */
function ym_bounds($ym) {
    $start = $ym . '-01';
    $end   = date('Y-m-d', strtotime("$start +1 month"));
    return [$start, $end];
}
function months_back_list($anchorYm, $n = 12) {
    $out = [];
    for ($i = $n-1; $i >= 0; $i--) {
        $out[] = date('Y-m', strtotime($anchorYm.'-01 -'.$i.' months'));
    }
    return $out;
}
function percentile($arr, $p) {
    // $p in [0,1]. Returns 0 for empty arrays.
    $n = count($arr);
    if ($n === 0) return 0.0;
    sort($arr, SORT_NUMERIC);
    $idx = ($n - 1) * $p;
    $lo = (int)floor($idx);
    $hi = (int)ceil($idx);
    if ($lo === $hi) return (float)$arr[$lo];
    $w = $idx - $lo;
    return (float)($arr[$lo] * (1 - $w) + $arr[$hi] * $w);
}

/* ===== Handle POST actions ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_lines') {
        // Update existing
        $ids        = $_POST['bl_id'] ?? [];
        $names      = $_POST['bl_name'] ?? [];
        $essentials = $_POST['bl_essential'] ?? [];
        $budgets    = $_POST['bl_budget'] ?? [];
        foreach ($ids as $i => $id) {
            $id   = (int)$id;
            $name = trim((string)($names[$i] ?? ''));
            $ess  = isset($essentials[$i]) ? 1 : 0;
            $budgStr = trim((string)($budgets[$i] ?? ''));
            if ($id <= 0 || $name === '') continue;
            if ($budgStr === '' || (float)$budgStr <= 0) {
                $stmt = $link->prepare("UPDATE budget_lines SET Name=?, IsEssential=?, MonthlyBudget=NULL WHERE UserID=? AND BudgetLineID=?");
                $stmt->bind_param('siii', $name, $ess, $userID, $id);
            } else {
                $budg = (float)$budgStr;
                $stmt = $link->prepare("UPDATE budget_lines SET Name=?, IsEssential=?, MonthlyBudget=? WHERE UserID=? AND BudgetLineID=?");
                $stmt->bind_param('sidii', $name, $ess, $budg, $userID, $id);
            }
            $stmt->execute();
        }
        // Add new lines
        $new_names      = $_POST['new_name'] ?? [];
        $new_essentials = $_POST['new_essential'] ?? [];
        $new_budgets    = $_POST['new_budget'] ?? [];
        foreach ($new_names as $i => $n) {
            $name = trim((string)$n);
            if ($name === '') continue;
            $ess  = isset($new_essentials[$i]) ? 1 : 0;
            $budgStr = trim((string)($new_budgets[$i] ?? ''));
            if ($budgStr === '' || (float)$budgStr <= 0) {
                $stmt = $link->prepare("INSERT INTO budget_lines (UserID, Name, IsEssential, MonthlyBudget) VALUES (?, ?, ?, NULL)");
                $stmt->bind_param('isi', $userID, $name, $ess);
            } else {
                $budg = (float)$budgStr;
                $stmt = $link->prepare("INSERT INTO budget_lines (UserID, Name, IsEssential, MonthlyBudget) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('isid', $userID, $name, $ess, $budg);
            }
            $stmt->execute();
        }
        $notices[] = "Budget lines saved.";
    }

    if ($action === 'delete_line') {
        $delID = (int)($_POST['delete_id'] ?? 0);
        if ($delID > 0) {
            $stmt = $link->prepare("UPDATE transactions SET BudgetLineID=NULL WHERE UserID=? AND BudgetLineID=?");
            $stmt->bind_param('ii', $userID, $delID);
            $stmt->execute();
            $stmt = $link->prepare("DELETE FROM budget_lines WHERE UserID=? AND BudgetLineID=?");
            $stmt->bind_param('ii', $userID, $delID);
            $stmt->execute();
            $notices[] = "Budget line deleted.";
        }
    }

    if ($action === 'merge_lines') {
        $from = (int)($_POST['merge_from'] ?? 0);
        $to   = (int)($_POST['merge_to'] ?? 0);
        if ($from > 0 && $to > 0 && $from !== $to) {
            $stmt = $link->prepare("UPDATE transactions SET BudgetLineID=? WHERE UserID=? AND BudgetLineID=?");
            $stmt->bind_param('iii', $to, $userID, $from);
            $stmt->execute();
            $stmt = $link->prepare("DELETE FROM budget_lines WHERE UserID=? AND BudgetLineID=?");
            $stmt->bind_param('ii', $userID, $from);
            $stmt->execute();
            $notices[] = "Merged line #{$from} into #{$to}.";
        } else {
            $errors[] = "Please choose two different lines to merge.";
        }
    }

    if ($action === 'apply_suggestions') {
        // Apply suggested budgets as posted
        $applyIDs   = $_POST['apply_id'] ?? [];
        $applyAmts  = $_POST['apply_amt'] ?? [];
        foreach ($applyIDs as $i => $id) {
            $id = (int)$id;
            $amtStr = trim((string)($applyAmts[$i] ?? ''));
            if ($id <= 0) continue;
            if ($amtStr === '' || (float)$amtStr <= 0) {
                // Set to ad-hoc (no fixed budget)
                $stmt = $link->prepare("UPDATE budget_lines SET MonthlyBudget=NULL WHERE UserID=? AND BudgetLineID=?");
                $stmt->bind_param('ii', $userID, $id);
            } else {
                $amt = (float)$amtStr;
                $stmt = $link->prepare("UPDATE budget_lines SET MonthlyBudget=? WHERE UserID=? AND BudgetLineID=?");
                $stmt->bind_param('dii', $amt, $userID, $id);
            }
            $stmt->execute();
        }
        $notices[] = "Suggestions applied.";
    }
}

/* ===== Inputs & timeframe ===== */
$currentYm = isset($_GET['month']) && preg_match('/^\d{4}-\d{2}$/', $_GET['month'])
    ? $_GET['month']
    : date('Y-m');
$months12 = months_back_list($currentYm, 12); // chronologically ascending
$months6  = array_slice($months12, -6);
$months3  = array_slice($months12, -3);
$prevYm   = date('Y-m', strtotime($currentYm.'-01 -1 month'));

/* ===== Load budget lines ===== */
$budgetLines = [];
$stmtBL = $link->prepare("SELECT BudgetLineID, Name, IsEssential, MonthlyBudget FROM budget_lines WHERE UserID=? ORDER BY Name ASC");
$stmtBL->bind_param('i', $userID);
$stmtBL->execute();
$resBL = $stmtBL->get_result();
while ($b = $resBL->fetch_assoc()) {
    $budgetLines[] = [
        'BudgetLineID' => (int)$b['BudgetLineID'],
        'Name'         => $b['Name'],
        'IsEssential'  => (int)$b['IsEssential'],
        'MonthlyBudget'=> is_null($b['MonthlyBudget']) ? null : (float)$b['MonthlyBudget'],
    ];
}

/* ===== Fetch monthly spend per line for last 12 months =====
   We’ll compute per-line, per-month spend = max(0, SUM(deb) - SUM(cred)).
*/
$spendMap = []; // [BudgetLineID][ym] = spend
if (!empty($budgetLines)) {
    // Build IN clause for months
    // We will query by date bounds iteratively (simpler and portable).
    foreach ($months12 as $ym) {
        [$start, $end] = ym_bounds($ym);
        $stmt = $link->prepare("
            SELECT BudgetLineID,
                   GREATEST(0, SUM(DebitAmount) - SUM(CreditAmount)) AS spend
            FROM transactions
            WHERE UserID=? AND BudgetLineID IS NOT NULL
              AND TransactionDate>=? AND TransactionDate<?
            GROUP BY BudgetLineID
        ");
        $stmt->bind_param('iss', $userID, $start, $end);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $bid = (int)$row['BudgetLineID'];
            $spend = (float)$row['spend'];
            if (!isset($spendMap[$bid])) $spendMap[$bid] = [];
            $spendMap[$bid][$ym] = $spend;
        }
    }
}

/* ===== Build suggestion set for each line ===== */
$suggestions = []; // keyed by BudgetLineID
foreach ($budgetLines as $bl) {
    $bid   = $bl['BudgetLineID'];
    $line12 = [];
    foreach ($months12 as $ym) $line12[] = (float)($spendMap[$bid][$ym] ?? 0);
    $line6  = array_slice($line12, -6);
    $line3  = array_slice($line12, -3);
    $last   = end($line12) ?: 0;

    $avg3   = (count($line3) ? array_sum($line3)/count($line3) : 0.0);
    $med12  = percentile($line12, 0.5);
    $p80_6  = percentile($line6, 0.80);

    // Choose a default suggestion strategy:
    // If variance high, prefer P80(6); else Avg(3). Very simple heuristic:
    $stdLike = (count($line6) ? (max($line6) - min($line6)) : 0);
    $defaultVal = ($stdLike > 50) ? $p80_6 : $avg3; // tweakable threshold

    $suggestions[$bid] = [
        'last'   => round($last, 2),
        'avg3'   => round($avg3, 2),
        'med12'  => round($med12, 2),
        'p80_6'  => round($p80_6, 2),
        'chosen' => round($defaultVal, 2),
        'series' => $line12, // for quick charting if needed
    ];
}

/* ===== Current month net status (quick context) ===== */
[$curStart, $curEnd] = ym_bounds($currentYm);
$stmtIn = $link->prepare("SELECT SUM(CreditAmount) AS s FROM transactions WHERE UserID=? AND TransactionDate>=? AND TransactionDate<? AND CreditAmount>0");
$stmtIn->bind_param('iss', $userID, $curStart, $curEnd);
$stmtIn->execute(); $incomeMonth = (float)($stmtIn->get_result()->fetch_assoc()['s'] ?? 0);
$stmtOut = $link->prepare("SELECT SUM(DebitAmount) AS s FROM transactions WHERE UserID=? AND TransactionDate>=? AND TransactionDate<? AND DebitAmount>0");
$stmtOut->bind_param('iss', $userID, $curStart, $curEnd);
$stmtOut->execute(); $outMonth = (float)($stmtOut->get_result()->fetch_assoc()['s'] ?? 0);
$netMonth = $incomeMonth - $outMonth;

$link->close();
?>
<?php include 'header.php'; ?>

<!-- Tailwind & Chart.js -->
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="max-w-7xl mx-auto p-4 space-y-6">
  <div class="flex flex-wrap items-center justify-between gap-2">
    <h1 class="text-2xl font-semibold">Budget Manager</h1>
    <div class="flex gap-2">
      <a href="finance.php" class="inline-flex items-center px-4 py-2 rounded border border-gray-300 hover:bg-gray-50">Back to Overview</a>
      <a href="import_finance.php" class="inline-flex items-center px-4 py-2 rounded bg-blue-600 hover:bg-blue-700 text-white">Import CSV</a>
    </div>
  </div>

  <?php if (!empty($notices)): ?>
    <div class="rounded border border-green-300 bg-green-50 text-green-800 p-3">
      <?php foreach ($notices as $n): ?><div><?= htmlspecialchars($n) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="rounded border border-red-300 bg-red-50 text-red-800 p-3">
      <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Context cards -->
  <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
    <div class="rounded border bg-white p-3 shadow-sm">
      <div class="text-xs text-gray-500">Selected Month</div>
      <div class="text-lg font-semibold mt-1"><?= htmlspecialchars(date('F Y', strtotime($currentYm.'-01'))) ?></div>
    </div>
    <div class="rounded border bg-white p-3 shadow-sm">
      <div class="text-xs text-gray-500">Money In</div>
      <div class="text-xl font-semibold mt-1 text-blue-700">£<?= number_format($incomeMonth,2) ?></div>
    </div>
    <div class="rounded border bg-white p-3 shadow-sm">
      <div class="text-xs text-gray-500">Net</div>
      <div class="text-xl font-semibold mt-1 <?= $netMonth>=0?'text-green-700':'text-red-700' ?>">£<?= number_format($netMonth,2) ?></div>
    </div>
  </div>

  <!-- Controls -->
  <div class="flex flex-wrap items-center justify-between gap-2">
    <form method="GET" class="flex items-center gap-2">
      <label class="text-sm text-gray-600">Anchor Month</label>
      <select name="month" class="border rounded p-1 text-sm">
        <?php
          // Offer last 24 months for anchor choice
          $opts = [];
          for ($i=0; $i<24; $i++) $opts[] = date('Y-m', strtotime(date('Y-m').'-01 -'.$i.' months'));
          foreach ($opts as $ym) {
            $sel = ($ym === $currentYm) ? 'selected' : '';
            $lbl = date('F Y', strtotime($ym.'-01'));
            echo '<option value="'.htmlspecialchars($ym).'" '.$sel.'>'.htmlspecialchars($lbl).'</option>';
          }
        ?>
      </select>
      <button class="inline-flex items-center px-3 py-1.5 rounded bg-gray-800 text-white text-sm hover:bg-black">Apply</button>
    </form>

    <form method="POST" class="flex items-center gap-2" onsubmit="return confirm('Apply these suggestions to Monthly Budget?');">
      <input type="hidden" name="action" value="apply_suggestions">
      <input type="hidden" id="applyPayload" name="apply_payload" value="">
      <button type="button" id="btnSelectAll" class="text-sm border px-3 py-1.5 rounded hover:bg-gray-50">Select All</button>
      <button type="button" id="btnClearAll" class="text-sm border px-3 py-1.5 rounded hover:bg-gray-50">Clear</button>
      <!-- Hidden fields for dynamic payload -->
      <div id="applyHidden"></div>
      <button type="submit" id="btnApply" class="inline-flex items-center px-4 py-2 rounded bg-green-600 hover:bg-green-700 text-white">Apply Suggestions</button>
    </form>
  </div>

  <!-- Budget table + details panel -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
    <!-- Table -->
    <div class="lg:col-span-2 rounded border bg-white p-4 shadow-sm">
      <div class="flex items-center justify-between mb-3">
        <h2 class="text-base font-semibold">Data-driven suggestions</h2>
        <div class="text-xs text-gray-500">Based on last 12 months of spend per line.</div>
      </div>

      <div class="overflow-auto">
        <table id="bmTable" class="min-w-full text-sm text-left border-collapse">
          <thead class="bg-gray-100">
            <tr>
              <th class="px-3 py-2 border">Line</th>
              <th class="px-3 py-2 border text-right">Current Budget</th>
              <th class="px-3 py-2 border text-right">Last</th>
              <th class="px-3 py-2 border text-right">Avg(3m)</th>
              <th class="px-3 py-2 border text-right">Median(12m)</th>
              <th class="px-3 py-2 border text-right">P80(6m)</th>
              <th class="px-3 py-2 border">Choose</th>
              <th class="px-3 py-2 border text-right">Apply</th>
              <th class="px-3 py-2 border text-center">Pick</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($budgetLines)): ?>
              <tr><td colspan="9" class="px-3 py-4 text-center text-gray-500">No budget lines defined.</td></tr>
            <?php else: foreach ($budgetLines as $bl):
              $bid = $bl['BudgetLineID'];
              $sug = $suggestions[$bid] ?? ['last'=>0,'avg3'=>0,'med12'=>0,'p80_6'=>0,'chosen'=>0];
              $cur = $bl['MonthlyBudget'];
            ?>
              <tr class="hover:bg-gray-50" data-bid="<?= (int)$bid ?>" data-name="<?= htmlspecialchars(mb_strtolower($bl['Name'], 'UTF-8')) ?>">
                <td class="px-3 py-2 border">
                  <div class="font-medium"><?= htmlspecialchars($bl['Name']) ?></div>
                  <div class="text-xs text-gray-500"><?= $bl['IsEssential'] ? 'Essential' : 'Discretionary' ?></div>
                </td>
                <td class="px-3 py-2 border text-right"><?= is_null($cur) ? '—' : '£'.number_format((float)$cur,2) ?></td>
                <td class="px-3 py-2 border text-right">£<?= number_format($sug['last'],2) ?></td>
                <td class="px-3 py-2 border text-right">£<?= number_format($sug['avg3'],2) ?></td>
                <td class="px-3 py-2 border text-right">£<?= number_format($sug['med12'],2) ?></td>
                <td class="px-3 py-2 border text-right">£<?= number_format($sug['p80_6'],2) ?></td>
                <td class="px-3 py-2 border">
                  <select class="border rounded p-1 text-sm choose-method">
                    <option value="<?= htmlspecialchars($sug['last']) ?>">Last</option>
                    <option value="<?= htmlspecialchars($sug['avg3']) ?>" selected>Avg(3m)</option>
                    <option value="<?= htmlspecialchars($sug['med12']) ?>">Median(12m)</option>
                    <option value="<?= htmlspecialchars($sug['p80_6']) ?>">P80(6m)</option>
                  </select>
                </td>
                <td class="px-3 py-2 border text-right">
                  <input type="number" step="0.01" class="border rounded p-1 w-28 text-right apply-amt" value="<?= htmlspecialchars(number_format($sug['chosen'],2,'.','')) ?>">
                </td>
                <td class="px-3 py-2 border text-center">
                  <input type="checkbox" class="pick-line h-4 w-4">
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <p class="text-xs text-gray-500 mt-2">Tip: Leave Apply value blank (or 0) to set a line as Ad-hoc (no fixed budget).</p>
    </div>

    <!-- Detail / Edit panel -->
    <div class="rounded border bg-white p-4 shadow-sm">
      <h3 class="text-base font-semibold mb-3">Line editor</h3>

      <form method="POST" class="space-y-4">
        <input type="hidden" name="action" value="save_lines">
        <div id="editLines">
          <?php if (empty($budgetLines)): ?>
            <div class="text-sm text-gray-500">No lines yet. Add below.</div>
          <?php else: foreach ($budgetLines as $i => $bl): ?>
            <div class="border rounded p-3">
              <input type="hidden" name="bl_id[<?= $i ?>]" value="<?= (int)$bl['BudgetLineID'] ?>">
              <div class="grid grid-cols-1 gap-2">
                <div>
                  <label class="block text-xs text-gray-500 mb-1">Name</label>
                  <input type="text" name="bl_name[<?= $i ?>]" value="<?= htmlspecialchars($bl['Name']) ?>" class="w-full border rounded p-2" required>
                </div>
                <div class="flex items-center justify-between gap-2">
                  <label class="inline-flex items-center text-sm">
                    <input type="checkbox" name="bl_essential[<?= $i ?>]" value="1" <?= $bl['IsEssential'] ? 'checked':'' ?> class="mr-2">
                    Essential
                  </label>
                  <div class="text-xs text-gray-500">Current budget: <?= is_null($bl['MonthlyBudget']) ? 'Ad-hoc' : '£'.number_format($bl['MonthlyBudget'],2) ?></div>
                </div>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>

        <div>
          <h4 class="text-sm font-medium text-gray-600 mb-2">Add new</h4>
          <div id="newWrap" class="space-y-3"></div>
          <button type="button" id="addNew" class="text-sm border px-3 py-1 rounded hover:bg-gray-50">+ Add line</button>
        </div>

        <div class="pt-1">
          <button type="submit" class="inline-flex items-center px-4 py-2 rounded bg-indigo-600 hover:bg-indigo-700 text-white">Save Lines</button>
        </div>
      </form>

      <hr class="my-4">

      <h3 class="text-base font-semibold mb-2">Merge lines</h3>
      <form method="POST" class="flex items-end gap-2">
        <input type="hidden" name="action" value="merge_lines">
        <div class="flex-1">
          <label class="block text-xs text-gray-500 mb-1">Merge FROM</label>
          <select name="merge_from" class="w-full border rounded p-2">
            <option value="">—</option>
            <?php foreach ($budgetLines as $bl): ?>
              <option value="<?= (int)$bl['BudgetLineID'] ?>"><?= htmlspecialchars($bl['Name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="flex-1">
          <label class="block text-xs text-gray-500 mb-1">Merge INTO</label>
          <select name="merge_to" class="w-full border rounded p-2">
            <option value="">—</option>
            <?php foreach ($budgetLines as $bl): ?>
              <option value="<?= (int)$bl['BudgetLineID'] ?>"><?= htmlspecialchars($bl['Name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="inline-flex items-center px-3 py-2 rounded bg-red-600 hover:bg-red-700 text-white">Merge</button>
      </form>

      <hr class="my-4">

      <h3 class="text-base font-semibold mb-2">12-month trend</h3>
      <p class="text-xs text-gray-500 mb-2">Click any row in the table to preview here.</p>
      <canvas id="trendCanvas" height="180"></canvas>
    </div>
  </div>

  <!-- Danger zone: delete -->
  <div class="rounded border bg-white p-4 shadow-sm">
    <h3 class="text-base font-semibold mb-2">Delete a line</h3>
    <form method="POST" onsubmit="return confirm('Delete this line? Transactions will become uncategorised.');" class="flex items-end gap-2">
      <input type="hidden" name="action" value="delete_line">
      <div class="flex-1">
        <label class="block text-xs text-gray-500 mb-1">Line</label>
        <select name="delete_id" class="w-full border rounded p-2">
          <option value="">—</option>
          <?php foreach ($budgetLines as $bl): ?>
            <option value="<?= (int)$bl['BudgetLineID'] ?>"><?= htmlspecialchars($bl['Name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="inline-flex items-center px-3 py-2 rounded bg-gray-700 hover:bg-black text-white">Delete</button>
    </form>
  </div>
</div>

<script>
  // ===== New line rows =====
  const addNewBtn = document.getElementById('addNew');
  const newWrap = document.getElementById('newWrap');
  let newIdx = 0;
  addNewBtn?.addEventListener('click', () => {
    const idx = newIdx++;
    const div = document.createElement('div');
    div.className = 'border rounded p-3';
    div.innerHTML = `
      <div class="grid grid-cols-1 gap-2">
        <div>
          <label class="block text-xs text-gray-500 mb-1">Name</label>
          <input type="text" name="new_name[${idx}]" class="w-full border rounded p-2" required>
        </div>
        <div class="flex items-center justify-between gap-2">
          <label class="inline-flex items-center text-sm">
            <input type="checkbox" name="new_essential[${idx}]" value="1" class="mr-2"> Essential
          </label>
          <div class="flex items-center gap-2">
            <label class="block text-xs text-gray-500">Initial budget</label>
            <input type="number" step="0.01" name="new_budget[${idx}]" class="border rounded p-1 w-28 text-right" placeholder="(optional)">
          </div>
        </div>
      </div>
    `;
    newWrap.appendChild(div);
  });

  // ===== Suggestions table: choose -> apply value, pick handling =====
  const table = document.getElementById('bmTable');
  const applyHidden = document.getElementById('applyHidden');
  const btnSelectAll = document.getElementById('btnSelectAll');
  const btnClearAll  = document.getElementById('btnClearAll');

  function rebuildApplyPayload() {
    // Clear
    applyHidden.innerHTML = '';
    // Collect all picked rows
    table.querySelectorAll('tbody tr').forEach((tr) => {
      const cb = tr.querySelector('.pick-line');
      if (!cb || !cb.checked) return;
      const bid = tr.getAttribute('data-bid');
      const amt = tr.querySelector('.apply-amt')?.value || '';
      if (!bid) return;
      // Build fields as arrays
      const hid1 = document.createElement('input');
      hid1.type = 'hidden';
      hid1.name = 'apply_id[]';
      hid1.value = bid;
      const hid2 = document.createElement('input');
      hid2.type = 'hidden';
      hid2.name = 'apply_amt[]';
      hid2.value = amt;
      applyHidden.appendChild(hid1);
      applyHidden.appendChild(hid2);
    });
  }

  table?.addEventListener('change', (e) => {
    const target = e.target;
    const tr = target.closest('tr');
    if (!tr) return;
    if (target.classList.contains('choose-method')) {
      // Copy chosen method value into Apply input
      const val = target.value || '';
      const input = tr.querySelector('.apply-amt');
      if (input) input.value = val;
    }
    if (target.classList.contains('pick-line') || target.classList.contains('apply-amt')) {
      rebuildApplyPayload();
    }
  });

  btnSelectAll?.addEventListener('click', () => {
    table.querySelectorAll('.pick-line').forEach(cb => cb.checked = true);
    rebuildApplyPayload();
  });
  btnClearAll?.addEventListener('click', () => {
    table.querySelectorAll('.pick-line').forEach(cb => cb.checked = false);
    rebuildApplyPayload();
  });

  // ===== Trend preview chart (right panel) =====
  const trendCtx = document.getElementById('trendCanvas').getContext('2d');
  let trendChart = new Chart(trendCtx, {
    type: 'line',
    data: { labels: <?= json_encode(array_map(fn($ym)=>date('M y', strtotime($ym.'-01')), $months12)) ?>,
            datasets: [{ label: 'Spend', data: [], borderWidth: 2, tension: 0.25 }] },
    options: {
      responsive: true,
      plugins: { legend: { position: 'bottom' }},
      scales: { y: { ticks: { callback: v => '£'+Number(v).toFixed(0) } } }
    }
  });

  const suggestions = <?= json_encode($suggestions) ?>; // bid => {series: [...]}
  table?.querySelectorAll('tbody tr').forEach(tr => {
    tr.addEventListener('click', (e) => {
      // avoid clicking inputs toggling chart when interacting with controls
      if (e.target.closest('input,select,button,label')) return;
      const bid = tr.getAttribute('data-bid');
      if (!bid || !suggestions[bid]) return;
      const series = suggestions[bid].series || [];
      trendChart.data.datasets[0].data = series;
      trendChart.update();
    });
  });

  // Initial: show first series if available
  (function initFirstSeries(){
    const firstTr = table?.querySelector('tbody tr');
    if (!firstTr) return;
    const bid = firstTr.getAttribute('data-bid');
    if (bid && suggestions[bid]) {
      trendChart.data.datasets[0].data = suggestions[bid].series || [];
      trendChart.update();
    }
  })();
</script>

<?php include 'footer.php'; ?>
