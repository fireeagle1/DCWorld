<?php
session_start();
require 'config.php';
require 'auth.php';

header('Content-Type: text/html; charset=utf-8');
$link->set_charset("utf8mb4");

/* Tailwind CDN */
echo '<script src="https://cdn.tailwindcss.com"></script>';

$errors = [];
$filename = '';
$transactions = [];
$duplicatesFound = 0;

/* ===== Helpers ===== */
function normalize_header($h) {
  if ($h === null) return '';
  $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
  $h = trim($h);
  $h = preg_replace('/\s+/', ' ', $h);
  return mb_strtolower($h, 'UTF-8');
}
function detect_delimiter($filePath) {
  $fh = fopen($filePath, 'r'); if (!$fh) return "\t";
  $line = fgets($fh); fclose($fh);
  return (substr_count($line, "\t") > substr_count($line, ",")) ? "\t" : ",";
}
function parse_amount($v) {
  if ($v === null || $v === '') return 0.00;
  $v = trim((string)$v);
  $v = str_replace(['£', ',', ' '], '', $v);
  if ($v === '' || !is_numeric($v)) return 0.00;
  return (float)$v;
}
function parse_date_dmy($raw) {
  $raw = trim((string)$raw);
  if ($raw === '') return null;
  $formats = ['d/m/Y','d-m-Y','Y-m-d','d.m.Y','j/m/Y','j-m-Y','d M Y'];
  foreach ($formats as $fmt) {
    $dt = DateTime::createFromFormat($fmt, $raw);
    if ($dt && $dt->format($fmt) === $raw) return $dt->format('Y-m-d');
  }
  $ts = strtotime($raw);
  return $ts ? date('Y-m-d', $ts) : null;
}
function normalize_desc_simple($s) {
  $s = (string)$s;
  $s = preg_replace('/\s+/', ' ', $s);
  return trim($s);
}

/* Load budget lines (no user scoping) */
$budgetLines = [];
$resBL = $link->query("SELECT BudgetLineID, Name FROM budget_lines ORDER BY Name ASC");
while ($r = $resBL->fetch_assoc()) $budgetLines[] = $r;

/* ===== Handle upload (preview) ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
  $filename = $_FILES['csv_file']['name'] ?? 'upload.csv';
  $tmp      = $_FILES['csv_file']['tmp_name'] ?? null;

  if (!$tmp || !is_uploaded_file($tmp)) {
    $errors[] = "Upload failed or no file provided.";
  } else {
    $delimiter = detect_delimiter($tmp);
    if (($handle = fopen($tmp, 'r')) === false) {
      $errors[] = "Could not open uploaded file.";
    } else {
      $rawHeader = fgetcsv($handle, 0, $delimiter);
      if ($rawHeader === false) {
        $errors[] = "Header row not found.";
      } else {
        $normHeader = array_map('normalize_header', $rawHeader);
        $want = [
          'transaction date'        => null,
          'transaction type'        => null,
          'sort code'               => null,
          'account number'          => null,
          'transaction description' => null,
          'debit amount'            => null,
          'credit amount'           => null,
          'balance'                 => null,
        ];
        foreach ($normHeader as $i => $h) if (array_key_exists($h, $want)) $want[$h] = $i;
        foreach (['transaction date','transaction description'] as $k)
          if (!is_int($want[$k])) $errors[] = "Missing column: $k";

        if (empty($errors)) {
          // duplicate check (date + TRIM(desc) + balance), across all rows
          $stmtDupBal = $link->prepare("
            SELECT 1 FROM transactions
            WHERE TransactionDate=? AND TRIM(Description)=TRIM(?) AND (Balance <=> ?)
            LIMIT 1
          ");
          $stmtDupNull = $link->prepare("
            SELECT 1 FROM transactions
            WHERE TransactionDate=? AND TRIM(Description)=TRIM(?) AND Balance IS NULL
            LIMIT 1
          ");

          // memory lookup (case-insensitive, trimmed)
          $stmtMem = $link->prepare("
            SELECT BudgetLineID
            FROM category_memory
            WHERE IsRegex=0 AND UPPER(TRIM(MatchPattern)) = UPPER(TRIM(?))
            ORDER BY MemoryID DESC
            LIMIT 1
          ");

          while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count(array_filter($data, fn($v)=>trim((string)$v) !== '')) === 0) continue;

            $get = function($key) use ($want, $data) {
              $idx = $want[$key];
              return is_int($idx) && isset($data[$idx]) ? $data[$idx] : '';
            };

            $dateRaw = (string)$get('transaction date');
            $date    = parse_date_dmy($dateRaw);
            $type    = trim((string)$get('transaction type'));
            $sort    = str_replace("'", '', trim((string)$get('sort code')));
            $acct    = trim((string)$get('account number'));
            $desc    = normalize_desc_simple((string)$get('transaction description'));
            $debit   = parse_amount($get('debit amount'));
            $credit  = parse_amount($get('credit amount'));
            $balanceCsv = $get('balance');
            $balance = ($balanceCsv === '' || $balanceCsv === null) ? null : parse_amount($balanceCsv);

            if (!$date) $date = ''; // keep visible but invalid

            // Duplicate check only if date is valid
            if ($date !== '') {
              if ($balance === null) {
                $stmtDupNull->bind_param('ss', $date, $desc);
                $stmtDupNull->execute();
                if ($stmtDupNull->get_result()->fetch_row()) { $duplicatesFound++; continue; }
              } else {
                $stmtDupBal->bind_param('ssd', $date, $desc, $balance);
                $stmtDupBal->execute();
                if ($stmtDupBal->get_result()->fetch_row()) { $duplicatesFound++; continue; }
              }
            }

            // Pre-suggest only for expenses (debit>0)
            $suggestId = null;
            if ($credit <= 0 && $debit > 0) {
              $stmtMem->bind_param('s', $desc);
              if ($stmtMem->execute()) {
                $r = $stmtMem->get_result()->fetch_assoc();
                if ($r && (int)$r['BudgetLineID'] > 0) $suggestId = (int)$r['BudgetLineID'];
              }
            }

            $transactions[] = [
              'TransactionDate' => $date,
              'TransactionDateRaw' => $dateRaw,
              'TransactionType' => $type,
              'SortCode'        => $sort,
              'AccountNumber'   => $acct,
              'Description'     => $desc,
              'DebitAmount'     => $debit,
              'CreditAmount'    => $credit,
              'Balance'         => $balance,
              'SuggestBudgetLineID' => $suggestId,
            ];
          }
        }
        fclose($handle);
      }
    }
  }
}
?>
<?php include 'header.php'; ?>

<div class="max-w-7xl mx-auto mt-8 p-6 bg-white shadow rounded">
  <h1 class="text-2xl font-semibold mb-6">Import Finance CSV</h1>

  <?php if (!empty($errors)): ?>
    <div class="mb-6 rounded border border-red-300 bg-red-50 text-red-800 p-4">
      <ul class="list-disc pl-5"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <?php if (empty($transactions)): ?>
    <form method="POST" enctype="multipart/form-data" class="space-y-4">
      <div>
        <label class="block text-sm font-medium mb-1">Upload CSV/TSV</label>
        <input type="file" name="csv_file" accept=".csv,.tsv,text/csv,text/tab-separated-values" required class="w-full border rounded p-2">
        <p class="text-xs text-gray-500 mt-1">
          Duplicates removed via <em>Date + Description + Balance</em>.
          Credits (money in) are never categorised.
        </p>
      </div>
      <button type="submit" class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
        Upload &amp; Preview
      </button>
    </form>
  <?php else: ?>
    <?php if ($duplicatesFound>0): ?>
      <div class="mb-3 rounded border border-amber-300 bg-amber-50 text-amber-800 p-3">
        Skipped <strong><?= (int)$duplicatesFound ?></strong> duplicates already in the database.
      </div>
    <?php endif; ?>

    <form method="POST" action="save_finance_import.php" class="space-y-4">
      <input type="hidden" name="filename" value="<?= htmlspecialchars($filename) ?>">

      <!-- Controls: Search, Hide Assigned, Quick Assign to Visible -->
      <div class="flex flex-wrap items-end gap-4">
        <div>
          <label class="block text-sm text-gray-700">Search Description</label>
          <input id="searchBox" type="text" class="border rounded p-2 w-72" placeholder="e.g. tesco">
        </div>

        <label class="inline-flex items-center gap-2 mb-1">
          <input id="hideAssigned" type="checkbox" class="h-4 w-4">
          <span class="text-sm text-gray-700">Hide Assigned &amp; Income</span>
        </label>

        <div class="ml-auto">
          <label class="block text-sm text-gray-700">Quick Assign (to visible, unmatched expenses)</label>
          <div class="flex gap-2">
            <select id="quickAssignSelect" class="border rounded p-2 min-w-[220px]">
              <option value="">-- Choose budget line --</option>
              <?php foreach ($budgetLines as $bl): ?>
                <option value="<?= (int)$bl['BudgetLineID'] ?>"><?= htmlspecialchars($bl['Name']) ?></option>
              <?php endforeach; ?>
            </select>
            <button type="button" id="applyQuickAssign" class="border rounded px-3 py-2 hover:bg-gray-50">
              Apply to Visible
            </button>
          </div>
          <p class="text-xs text-gray-500 mt-1">
            Applies only to rows currently visible after search/toggle, with no category and Debit &gt; 0.
          </p>
        </div>
      </div>

      <div class="overflow-auto max-h-[65vh] border rounded">
        <table id="previewTable" class="min-w-full text-sm text-left border-collapse">
          <thead class="sticky top-0 bg-gray-100 z-10">
            <tr>
              <th class="px-3 py-2 border">Date</th>
              <th class="px-3 py-2 border">Description</th>
              <th class="px-3 py-2 border text-right">Debit</th>
              <th class="px-3 py-2 border text-right">Credit</th>
              <th class="px-3 py-2 border text-right">Balance</th>
              <th class="px-3 py-2 border">Budget Line</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($transactions as $i => $tx):
              $isIncome   = ($tx['CreditAmount'] > 0 && $tx['DebitAmount'] <= 0);
              $suggestId  = $tx['SuggestBudgetLineID'];
              $dateDisp   = $tx['TransactionDate'] ?: ($tx['TransactionDateRaw'] ?: '');
              // Initial status: income / suggested / unmatched
              $status     = $isIncome ? 'income' : ($suggestId ? 'suggested' : 'unmatched');
              $rowBgClass = $status === 'income' ? 'bg-gray-50'
                          : ($status === 'suggested' ? 'bg-amber-50' : 'bg-red-50');
            ?>
            <tr class="hover:bg-yellow-50 <?= $rowBgClass ?>"
                data-desc="<?= htmlspecialchars(mb_strtolower($tx['Description'],'UTF-8')) ?>"
                data-status="<?= $status ?>">
              <td class="px-3 py-2 border whitespace-nowrap">
                <?= htmlspecialchars($dateDisp) ?>
                <?php foreach (['TransactionDate','TransactionDateRaw','TransactionType','SortCode','AccountNumber','Description'] as $k): ?>
                  <input type="hidden" name="transactions[<?= $i ?>][<?= $k ?>]" value="<?= htmlspecialchars((string)$tx[$k]) ?>">
                <?php endforeach; ?>
                <input type="hidden" name="transactions[<?= $i ?>][DebitAmount]"  value="<?= htmlspecialchars($tx['DebitAmount']) ?>">
                <input type="hidden" name="transactions[<?= $i ?>][CreditAmount]" value="<?= htmlspecialchars($tx['CreditAmount']) ?>">
                <input type="hidden" name="transactions[<?= $i ?>][Balance]"      value="<?= htmlspecialchars($tx['Balance'] === null ? '' : (string)$tx['Balance']) ?>">
              </td>
              <td class="px-3 py-2 border"><?= htmlspecialchars($tx['Description']) ?></td>
              <td class="px-3 py-2 border text-right"><?= number_format((float)$tx['DebitAmount'], 2) ?></td>
              <td class="px-3 py-2 border text-right"><?= number_format((float)$tx['CreditAmount'], 2) ?></td>
              <td class="px-3 py-2 border text-right"><?= $tx['Balance'] === null ? '—' : number_format((float)$tx['Balance'], 2) ?></td>
              <td class="px-3 py-2 border">
                <?php if ($isIncome): ?>
                  <span class="inline-block px-2 py-0.5 text-xs rounded bg-blue-100 text-blue-800">Income</span>
                  <select disabled class="w-full border rounded p-1 opacity-50 cursor-not-allowed"><option>—</option></select>
                  <input type="hidden" name="transactions[<?= $i ?>][BudgetLineID]" value="">
                <?php else: ?>
                  <select name="transactions[<?= $i ?>][BudgetLineID]"
                          class="w-full border rounded p-1 catSelect"
                          data-suggested="<?= $suggestId ? '1':'0' ?>">
                    <option value="">-- Select --</option>
                    <?php foreach ($budgetLines as $bl):
                      $sel = ($suggestId && (int)$bl['BudgetLineID']===(int)$suggestId) ? 'selected' : '';
                    ?>
                      <option value="<?= (int)$bl['BudgetLineID'] ?>" <?= $sel ?>><?= htmlspecialchars($bl['Name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="flex items-center justify-between">
        <div class="text-xs text-gray-500 space-x-2">
          <span class="px-2 py-0.5 rounded bg-blue-100 text-blue-800">Income</span>
          <span class="px-2 py-0.5 rounded bg-amber-100 text-amber-800">Suggested</span>
          <span class="px-2 py-0.5 rounded bg-red-100 text-red-800">Unassigned</span>
          <span class="px-2 py-0.5 rounded bg-green-100 text-green-800">Assigned</span>
        </div>
        <button type="submit" class="inline-flex items-center bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded">
          Save Transactions
        </button>
      </div>
    </form>

    <script>
      const tableBody     = document.querySelector('#previewTable tbody');
      const searchBox     = document.getElementById('searchBox');
      const hideAssigned  = document.getElementById('hideAssigned');
      const quickSelect   = document.getElementById('quickAssignSelect');
      const applyQuickBtn = document.getElementById('applyQuickAssign');

      // Helpers to set row visual state
      function setRowState(tr, state) {
        tr.dataset.status = state; // income | suggested | unmatched | assigned
        tr.classList.remove('bg-gray-50','bg-amber-50','bg-red-50','bg-green-50');
        if (state === 'income')     tr.classList.add('bg-gray-50');
        else if (state === 'suggested') tr.classList.add('bg-amber-50');
        else if (state === 'unmatched') tr.classList.add('bg-red-50');
        else if (state === 'assigned')  tr.classList.add('bg-green-50');
      }

      // Initial: rows with a selected value that were suggested should remain amber until confirmed.
      // If user changes any select (even to same value), mark as assigned (green) if non-empty; else unmatched (red).
      tableBody.querySelectorAll('select.catSelect').forEach(sel => {
        sel.addEventListener('change', (e) => {
          const tr = sel.closest('tr');
          const hasVal = !!sel.value;
          if (hasVal) setRowState(tr, 'assigned'); else setRowState(tr, 'unmatched');
          applyVisibilityFilters(); // keep filters consistent
        });
      });

      // Search filter + Hide Assigned/Income toggle
      function rowMatchesSearch(tr, q) {
        if (!q) return true;
        const d = tr.getAttribute('data-desc') || '';
        return d.includes(q);
      }
      function rowIsHiddenByToggle(tr) {
        if (!hideAssigned.checked) return false;
        const st = tr.dataset.status;
        return (st === 'assigned' || st === 'income');
      }
      function applyVisibilityFilters() {
        const q = (searchBox.value || '').trim().toLowerCase();
        Array.from(tableBody.rows).forEach(tr => {
          const visible = rowMatchesSearch(tr, q) && !rowIsHiddenByToggle(tr);
          tr.style.display = visible ? '' : 'none';
        });
      }
      searchBox.addEventListener('input', applyVisibilityFilters);
      hideAssigned.addEventListener('change', applyVisibilityFilters);

      // Quick assign to VISIBLE unmatched expenses only
      applyQuickBtn.addEventListener('click', () => {
        const bl = quickSelect.value;
        if (!bl) return;

        const q = (searchBox.value || '').trim().toLowerCase();
        Array.from(tableBody.rows).forEach(tr => {
          const isVisible = (tr.style.display !== 'none') && rowMatchesSearch(tr, q) && !rowIsHiddenByToggle(tr);
          if (!isVisible) return;

          const state = tr.dataset.status;
          if (state === 'income') return;        // never categorise income
          const sel = tr.querySelector('select.catSelect');
          if (!sel) return;

          if (!sel.value) {
            sel.value = bl;
            setRowState(tr, 'assigned');         // turn green when assigned
          }
        });
      });

      // Run filters once on load
      applyVisibilityFilters();
    </script>
  <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
