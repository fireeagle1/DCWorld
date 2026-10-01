<?php
declare(strict_types=1);

/**
 * JWT-authenticated wardrobe image delivery for the mobile app.
 * Mirrors the session-based /wardrobe_image.php but accepts bearer tokens.
 *
 * Self-contained: includes only root-level config.php and wardrobe_common.php,
 * and verifies the JWT inline so it has no dependency on the api/ directory.
 * We buffer output, then clean it and override headers before streaming the image.
 */

// Buffer all output so we can clean it before sending the image
ob_start();

// Start/resume the web session BEFORE any output so browser (cookie-based)
// requests can authenticate. Must happen before config.php emits anything.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include config (DB connection via $link) and wardrobe helpers.
// This endpoint lives at the document root and must NOT depend on the api/
// directory, which is not deployed on all environments.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/wardrobe_common.php';

// JWT secret must match the one used to sign tokens at login.
// Guard so we don't collide with a secret defined elsewhere.
if (!defined('JWT_SECRET')) {
    define('JWT_SECRET', 'vJV6VNQxjGQ8AaDQyVHHT76BdPh_mobile_api_2025');
}

// --- Minimal, dependency-free JWT verification (bearer token) -------------
if (!function_exists('wardrobe_base64url_decode')) {
    function wardrobe_base64url_decode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}

if (!function_exists('wardrobe_base64url_encode')) {
    function wardrobe_base64url_encode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('wardrobe_get_bearer_token')) {
    function wardrobe_get_bearer_token(): ?string {
        $authHeader = '';
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        if ($authHeader === '') {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION']
                ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                ?? '';
        }
        if (preg_match('/Bearer\s+(.+)/i', (string)$authHeader, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}

if (!function_exists('wardrobe_verify_jwt')) {
    function wardrobe_verify_jwt(string $token): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;
        [$header, $payload, $signature] = $parts;

        $expectedSig = wardrobe_base64url_encode(
            hash_hmac('sha256', "{$header}.{$payload}", JWT_SECRET, true)
        );
        if (!hash_equals($expectedSig, $signature)) return null;

        $data = json_decode(wardrobe_base64url_decode($payload), true);
        if (!is_array($data)) return null;
        if (isset($data['exp']) && $data['exp'] < time()) return null;

        return $data;
    }
}

/**
 * Authenticate the request. Accepts EITHER a logged-in web session
 * (cookie-based, used by the website) OR a JWT bearer token (used by
 * the mobile app). Returns the user ID, or sends a 401 and exits.
 */
function wardrobe_require_auth(): int {
    // 1. Web session (browser)
    if (isset($_SESSION['userID']) && (int)$_SESSION['userID'] > 0) {
        return (int)$_SESSION['userID'];
    }

    // 2. JWT bearer token (mobile app)
    $token = wardrobe_get_bearer_token();
    $payload = $token ? wardrobe_verify_jwt($token) : null;
    if ($payload && isset($payload['sub'])) {
        return (int)$payload['sub'];
    }

    while (ob_get_level()) ob_end_clean();
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Authentication required');
}

// Authenticate via session or JWT (will exit with 401 if neither is valid)
$userID = wardrobe_require_auth();

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

// Remove any headers set by included files and send correct image headers
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
