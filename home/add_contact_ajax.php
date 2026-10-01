<?php
// add_contact_ajax.php  (hardened: CSRF + CreatedBy fields + validation + JSON headers)

session_start();
require '../config.php';
require '../auth.php';

header('Content-Type: application/json; charset=utf-8');

$userID = (int)($_SESSION['userID'] ?? 0);

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF validation failed']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$knownAs = trim((string)($_POST['KnownAs'] ?? ''));
$first   = trim((string)($_POST['FirstName'] ?? ''));
$last    = trim((string)($_POST['LastName'] ?? ''));

if ($knownAs === '' || $first === '' || $last === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$stmt = $link->prepare(
    "INSERT INTO Contacts (KnownAs, FirstName, LastName, CreatedBy, LastUpdatedBy, LastUpdatedAt)
     VALUES (?, ?, ?, ?, ?, NOW())"
);
$stmt->bind_param("sssii", $knownAs, $first, $last, $userID, $userID);
$stmt->execute();

$id = (int)$stmt->insert_id;
echo json_encode(['success' => true, 'id' => $id, 'knownas' => $knownAs], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
