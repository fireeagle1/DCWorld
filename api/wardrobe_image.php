<?php
declare(strict_types=1);

/**
 * JWT-authenticated wardrobe image delivery for the mobile app.
 * Mirrors the session-based /wardrobe_image.php but accepts bearer tokens.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_middleware.php';
require_once dirname(__DIR__) . '/wardrobe_common.php';

// Authenticate via JWT
$userID = require_auth();

$itemId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$view = isset($_GET['view']) ? (string)$_GET['view'] : 'front';
$allowedViews = ['front', 'back', 'processed_front', 'processed_back', 'model_front', 'model_back'];

if (!$itemId || $itemId < 1 || !in_array($view, $allowedViews, true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Invalid image request');
}

// Look up the image record
$sql = <<<'SQL'
SELECT wi.RelativePath, wi.MimeType
FROM WardrobeImages wi
INNER JOIN WardrobeItems w ON w.WardrobeItemID = wi.WardrobeItemID
WHERE wi.WardrobeItemID = ?
  AND wi.ViewType = ?
  AND w.DeletedAt IS NULL
LIMIT 1
SQL;

$stmt = $link->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Unable to prepare image lookup');
}

$stmt->bind_param('is', $itemId, $view);
if (!$stmt->execute()) {
    $stmt->close();
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Unable to query image');
}

$relativePath = null;
$storedMimeType = null;
$stmt->bind_result($relativePath, $storedMimeType);
$found = $stmt->fetch();
$stmt->close();

if (!$found || !$relativePath) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Image record not found');
}

$uploadRoot = wardrobe_upload_dir();
$basePath = realpath($uploadRoot);

// Stored paths always use forward slashes; normalize for the host OS.
$normalizedRelative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)$relativePath);
$candidate = $uploadRoot . DIRECTORY_SEPARATOR . ltrim($normalizedRelative, DIRECTORY_SEPARATOR);
$filePath = realpath($candidate);

if (
    $basePath === false ||
    $filePath === false ||
    !is_file($filePath) ||
    strncmp($filePath, $basePath . DIRECTORY_SEPARATOR, strlen($basePath . DIRECTORY_SEPARATOR)) !== 0
) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Image file not found');
}

$mimeType = (string)$storedMimeType;
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo !== false) {
        $detected = finfo_file($finfo, $filePath);
        finfo_close($finfo);
        if (is_string($detected) && str_starts_with($detected, 'image/')) {
            $mimeType = $detected;
        }
    }
}

if (!str_starts_with($mimeType, 'image/')) {
    $mimeType = 'application/octet-stream';
}

$fileSize = filesize($filePath);
$lastModified = filemtime($filePath) ?: time();
$etag = '"' . sha1($filePath . '|' . $fileSize . '|' . $lastModified) . '"';

// Override the JSON content-type header set by api/config.php
header('Content-Type: ' . $mimeType);
if ($fileSize !== false) {
    header('Content-Length: ' . (string)$fileSize);
}
header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
header('Cache-Control: private, max-age=3600');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');

if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

$handle = fopen($filePath, 'rb');
if ($handle === false) {
    http_response_code(500);
    exit('Unable to read image');
}

fpassthru($handle);
fclose($handle);
