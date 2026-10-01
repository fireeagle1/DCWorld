<?php
session_start();
require 'config.php';
require 'auth.php';

header('Content-Type: text/html; charset=utf-8');
$link->set_charset("utf8mb4");

$inserted = 0;
$duplicates = 0;
$invalid = 0;

$userID = $_SESSION['userID'] ?? 0; // insert safety; reads are not filtered anywhere

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transactions']) && is_array($_POST['transactions'])) {

  // Duplicate checks (date + TRIM(desc) + balance), across all rows
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

  // Insert (keeps UserID for schema compatibility; we don't filter by it elsewhere)
  $stmtIns = $link->prepare("
    INSERT INTO transactions
      (UserID, TransactionDate, TransactionType, SortCode, AccountNumber, Description,
       DebitAmount, CreditAmount, Balance, BudgetLineID)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");

  // Memory upsert without unique index: UPDATE first (case-insensitive), then INSERT if no row changed.
  $stmtMemUpd = $link->prepare("
    UPDATE category_memory
    SET BudgetLineID=?
    WHERE IsRegex=0 AND UPPER(TRIM(MatchPattern)) = UPPER(TRIM(?))
  ");
  $stmtMemIns = $link->prepare("
    INSERT INTO category_memory (MatchPattern, BudgetLineID, IsRegex)
    VALUES (?, ?, 0)
  ");

  foreach ($_POST['transactions'] as $t) {
    $date   = trim((string)($t['TransactionDate'] ?? ''));
    $desc   = trim((string)($t['Description'] ?? ''));
    $debit  = (float)($t['DebitAmount'] ?? 0);
    $credit = (float)($t['CreditAmount'] ?? 0);
    $balRaw = $t['Balance'] ?? '';
    $bal    = ($balRaw === '' ? null : (float)$balRaw);
    $type   = trim((string)($t['TransactionType'] ?? ''));
    $sort   = trim((string)($t['SortCode'] ?? ''));
    $acct   = trim((string)($t['AccountNumber'] ?? ''));
    $bid    = isset($t['BudgetLineID']) && $t['BudgetLineID'] !== '' ? (int)$t['BudgetLineID'] : null;

    if ($date === '' || $desc === '') { $invalid++; continue; }

    // Credits are income → never assign a budget line
    if ($credit > 0 && $debit <= 0) $bid = null;

    // Duplicate?
    if ($bal === null) {
      $stmtDupNull->bind_param('ss', $date, $desc);
      $stmtDupNull->execute();
      if ($stmtDupNull->get_result()->fetch_row()) { $duplicates++; continue; }
    } else {
      $stmtDupBal->bind_param('ssd', $date, $desc, $bal);
      $stmtDupBal->execute();
      if ($stmtDupBal->get_result()->fetch_row()) { $duplicates++; continue; }
    }

    // Insert row
    $stmtIns->bind_param('isssssdddi', $userID, $date, $type, $sort, $acct, $desc, $debit, $credit, $bal, $bid);
    $stmtIns->execute();
    $inserted++;

    // Save memory for expenses with a chosen budget line
    if (!is_null($bid) && $debit > 0) {
      $stmtMemUpd->bind_param('is', $bid, $desc);
      $stmtMemUpd->execute();
      if ($link->affected_rows === 0) {
        $stmtMemIns->bind_param('si', $desc, $bid);
        $stmtMemIns->execute();
      }
    }
  }
}

header("Location: import_finance.php?inserted={$inserted}&duplicates={$duplicates}&invalid={$invalid}");
exit;
