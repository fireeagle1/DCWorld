<?php
declare(strict_types=1);

/**
 * JWT-authenticated wardrobe image delivery for the mobile app.
 * Mirrors the session-based /wardrobe_image.php but accepts bearer tokens.
 *
 * NOTE: api/config.php sets Content-Type: application/json and outputs CORS headers.
 * We must clean the output buffer and override headers before streaming image data.
 */

// Buffer all output so we can clean it before sending the image
ob_start();

// Include config for DB connection and JWT constants
require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/auth_middleware.php';
require_once __DIR__ . '/wardrobe_common.php';

// Authenticate via JWT (will exit with JSON error if fails)
$userID = require_auth();

$itemId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$view = isset($_GET['view']) ? (string)$_GET['view'] : 'front';
$allowedViews = ['front', 'back', 'processed_front', 'processed_back', 'model_front', 'model_back'];

if (!$itemId || $itemId < 1 || !in_array($view, $allowedViews, true)) {
    while (ob_get_level()) ob_end_clean();
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
ORDER BY wi.ImageID DESC
LIMIT 1
SQL;

$stmt = $link->prepare($sql);
if (!$stmt) {
    while (ob_get_level()) ob_end_clean();
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Unable to prepare image lookup');
}

$stmt->bind_param('is', $itemId, $view);
if (!$stmt->execute()) {
    $stmt->close();
    while (ob_get_level()) ob_end_clean();
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
    while (ob_get_level()) ob_end_clean();
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Image not found');
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
    while (ob_get_level()) ob_end_clean();
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Image file not found on disk');
}

// Determine MIME type
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

// Clean ALL output buffers — this removes any whitespace/newlines from included files
while (ob_get_level()) {
    ob_end_clean();
}

// Remove all headers set by config.php and send correct image headers
header_remove();
header('Content-Type: ' . $mimeType);
if ($fileSize !== false) {
    header('Content-Length: ' . (string)$fileSize);
}
header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
header('Cache-Control: private, max-age=3600');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle conditional requests (304 Not Modified)
if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
    http_response_code(304);
    exit;
}

// Stream the image — use readfile for clean binary output
readfile($filePath);
exit;
