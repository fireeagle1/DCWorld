<?php
/**
 * dedupe_by_date_desc_balance.php
 *
 * De-duplicates transactions for the shared ledger (UserID=0)
 * Rule: duplicates have the same (TransactionDate, TRIM(Description), Balance [NULL-safe]).
 * Keeps the earliest row (smallest TransactionID), deletes the rest.
 *
 * Usage:
 *   - Dry run (default):  dedupe_by_date_desc_balance.php
 *   - Commit deletions:   dedupe_by_date_desc_balance.php?run=1
 *   - Also export a CSV of rows to be deleted (before deletion): ?run=1&export=1
 */

session_start();
require 'config.php';
require 'auth.php';

header('Content-Type: text/html; charset=utf-8');
$link->set_charset("utf8mb4");

$run    = isset($_GET['run']) && $_GET['run'] == '1';
$export = isset($_GET['export']) && $_GET['export'] == '1';

$totalGroups   = 0;
$totalDupRows  = 0; // rows to delete
$totalKept     = 0; // rows retained (one per group)
$deleted       = 0;
$errors        = [];

// Collect rows to delete if we need to export or summarize
$toDelete = []; // each: [TransactionID, TransactionDate, Description, Balance]

/**
 * Helper: fetch all rows for a duplicate group (date, desc, balance)
 */
function fetch_group_rows($link, $date, $desc, $balanceIsNull, $balanceVal) {
    if ($balanceIsNull) {
        $sql = "SELECT TransactionID, TransactionDate, Description, Balance
                FROM transactions
                WHERE TransactionDate=?
                  AND TRIM(Description)=TRIM(?)
                  AND Balance IS NULL
                ORDER BY TransactionDate ASC, TransactionID ASC";
        $stmt = $link->prepare($sql);
        $stmt->bind_param('ss', $date, $desc);
    } else {
        $sql = "SELECT TransactionID, TransactionDate, Description, Balance
                FROM transactions
                WHERE UserID=0
                  AND TransactionDate=?
                  AND TRIM(Description)=TRIM(?)
                  AND Balance <=> ?
                ORDER BY TransactionDate ASC, TransactionID ASC";
        $stmt = $link->prepare($sql);
        $stmt->bind_param('ssd', $date, $desc, $balanceVal);
    }
    $stmt->execute();
    return $stmt->get_result();
}

/**
 * Step 1: find duplicate groups (count > 1)
 * Note: GROUP BY with NULL Balance groups NULLs together by default.
 */
$sqlGroups = "
    SELECT TransactionDate AS d, TRIM(Description) AS x,
           Balance, COUNT(*) AS c, SUM(1) AS s
    FROM transactions
    WHERE UserID=0
    GROUP BY d, x, Balance
    HAVING c > 1
    ORDER BY d ASC, x ASC
";
$res = $link->query($sqlGroups);
if (!$res) {
    $errors[] = "Query error: " . $link->error;
}

if (empty($errors)) {
    // Optional CSV export header (only if export requested)
    $csvPath = null;
    $csvFH   = null;
    if ($run && $export) {
        $csvPath = __DIR__ . '/duplicates_export_' . date('Ymd_His') . '.csv';
        $csvFH = fopen($csvPath, 'w');
        if ($csvFH) {
            fputcsv($csvFH, ['TransactionID','TransactionDate','Description','Balance']);
        } else {
            $errors[] = "Cannot open CSV for writing: " . $csvPath;
        }
    }

    // We’ll execute deletions in a transaction for safety
    if ($run) $link->begin_transaction();

    while ($g = $res->fetch_assoc()) {
        $totalGroups++;
        $date = $g['d'];
        $desc = $g['x'];
        $balanceIsNull = is_null($g['Balance']);
        $balanceVal = $balanceIsNull ? null : (float)$g['Balance'];

        $groupRows = fetch_group_rows($link, $date, $desc, $balanceIsNull, $balanceVal);

        // Keep the first (earliest by TransactionDate, then lowest TransactionID)
        $keep = $groupRows->fetch_assoc();
        if (!$keep) continue; // defensive; should not happen

        $totalKept++;

        // Everything else is a duplicate to delete
        while ($row = $groupRows->fetch_assoc()) {
            $totalDupRows++;
            $toDelete[] = $row;

            if ($run) {
                // Export before deletion
                if ($csvFH) {
                    fputcsv($csvFH, [
                        $row['TransactionID'],
                        $row['TransactionDate'],
                        $row['Description'],
                        is_null($row['Balance']) ? '' : number_format((float)$row['Balance'], 2, '.', '')
                    ]);
                }
                // Delete
                $stmtDel = $link->prepare("DELETE FROM transactions WHERE TransactionID=? LIMIT 1");
                $tid = (int)$row['TransactionID'];
                $stmtDel->bind_param('i', $tid);
                if ($stmtDel->execute()) {
                    $deleted++;
                } else {
                    $errors[] = "Delete failed for TransactionID {$tid}: " . $stmtDel->error;
                }
            }
        }
    }

    if ($run) {
        if (empty($errors)) {
            $link->commit();
        } else {
            $link->rollback();
        }
    }

    if ($csvFH) fclose($csvFH);
}

$link->close();

/* ===== Output (simple HTML) ===== */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Dedupe Transactions (Date + Description + Balance)</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
  <div class="max-w-4xl mx-auto my-8 p-6 bg-white shadow rounded">
    <h1 class="text-2xl font-semibold mb-4">Dedupe Transactions</h1>
    <p class="text-sm text-gray-600 mb-4">
      Rule: duplicates share the same <strong>TransactionDate</strong>, <strong>TRIM(Description)</strong>, and <strong>Balance</strong> (NULL-safe).<br>
      The earliest record (by date then TransactionID) is kept; later duplicates are removed.
    </p>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
      <div class="rounded border bg-white p-4">
        <div class="text-sm text-gray-500">Mode</div>
        <div class="text-lg font-semibold mt-1"><?= $run ? 'COMMIT (deleting)' : 'DRY RUN (no changes)' ?></div>
      </div>
      <div class="rounded border bg-white p-4">
        <div class="text-sm text-gray-500">Duplicate Groups Found</div>
        <div class="text-lg font-semibold mt-1"><?= number_format($totalGroups) ?></div>
      </div>
      <div class="rounded border bg-white p-4">
        <div class="text-sm text-gray-500">Rows to Delete</div>
        <div class="text-lg font-semibold mt-1"><?= number_format($totalDupRows) ?></div>
      </div>
      <div class="rounded border bg-white p-4">
        <div class="text-sm text-gray-500">Rows Kept</div>
        <div class="text-lg font-semibold mt-1"><?= number_format($totalKept) ?></div>
      </div>
      <?php if ($run): ?>
      <div class="rounded border bg-white p-4">
        <div class="text-sm text-gray-500">Deleted</div>
        <div class="text-lg font-semibold mt-1 text-red-700"><?= number_format($deleted) ?></div>
      </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="mb-6 rounded border border-red-300 bg-red-50 text-red-800 p-4">
        <div class="font-medium mb-1">Errors</div>
        <ul class="list-disc pl-5">
          <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <div class="flex items-center gap-2">
      <?php if (!$run): ?>
        <a href="?run=1" class="inline-flex items-center px-4 py-2 rounded bg-red-600 hover:bg-red-700 text-white">Commit Deletions</a>
        <a href="?run=1&export=1" class="inline-flex items-center px-4 py-2 rounded bg-orange-600 hover:bg-orange-700 text-white">Commit + Export CSV</a>
      <?php else: ?>
        <a href="dedupe_by_date_desc_balance.php" class="inline-flex items-center px-4 py-2 rounded bg-gray-700 hover:bg-black text-white">Run Dry Again</a>
      <?php endif; ?>
      <a href="finance.php" class="inline-flex items-center px-4 py-2 rounded bg-blue-600 hover:bg-blue-700 text-white">Back to Finance</a>
    </div>

    <?php if (!$run && !empty($toDelete)): ?>
      <div class="mt-6">
        <h2 class="text-base font-semibold mb-2">Sample of rows that would be deleted (first 50)</h2>
        <div class="overflow-auto border rounded">
          <table class="min-w-full text-sm text-left border-collapse">
            <thead class="bg-gray-100">
              <tr>
                <th class="px-3 py-2 border">TransactionID</th>
                <th class="px-3 py-2 border">Date</th>
                <th class="px-3 py-2 border">Description</th>
                <th class="px-3 py-2 border text-right">Balance</th>
              </tr>
            </thead>
            <tbody>
              <?php
                $show = array_slice($toDelete, 0, 50);
                foreach ($show as $r):
              ?>
                <tr class="hover:bg-gray-50">
                  <td class="px-3 py-2 border"><?= (int)$r['TransactionID'] ?></td>
                  <td class="px-3 py-2 border"><?= htmlspecialchars($r['TransactionDate']) ?></td>
                  <td class="px-3 py-2 border"><?= htmlspecialchars($r['Description']) ?></td>
                  <td class="px-3 py-2 border text-right">
                    <?= is_null($r['Balance']) ? '—' : number_format((float)$r['Balance'], 2) ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="text-xs text-gray-500 mt-2">Only a preview of the first 50 duplicate rows is shown above.</p>
      </div>
    <?php endif; ?>

    <?php if ($run && $export && isset($csvPath)): ?>
      <p class="mt-4 text-sm">
        Exported the deleted rows to: <code><?= htmlspecialchars($csvPath) ?></code>
      </p>
    <?php endif; ?>
  </div>
</body>
</html>
