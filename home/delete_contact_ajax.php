<?php
// delete_contact_ajax.php
// Deletes contact + preferences; optionally deletes profile photo file.

session_start();
require '../config.php';
require '../auth.php';

header('Content-Type: application/json; charset=utf-8');

$csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
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

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
$contactID = (int)($payload['ContactID'] ?? 0);

if ($contactID <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid ContactID']);
    exit;
}

// Remove preferences first (if not using FK cascade)
$stmt = $link->prepare("DELETE FROM ContactPreferences WHERE ContactID=?");
$stmt->bind_param('i', $contactID);
$stmt->execute();

// Delete contact
$stmt2 = $link->prepare("DELETE FROM Contacts WHERE ContactID=? LIMIT 1");
$stmt2->bind_param('i', $contactID);
$stmt2->execute();

if ($stmt2->affected_rows <= 0) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Not found']);
    exit;
}

// Delete photo file if it exists
$disk = "/home/xohpwhmm/assets.dcworld.uk/images/contacts/{$contactID}.jpg";
if (is_file($disk)) {
    @unlink($disk);
}

echo json_encode(['success' => true], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
