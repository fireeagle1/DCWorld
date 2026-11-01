<?php
/**
 * JWT Authentication Middleware
 * Lightweight JWT implementation without external dependencies.
 * Include this file in any endpoint that requires authentication.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

// The JWT secret MUST come from config.php (which is git-ignored and holds the
// real secret). We deliberately do NOT define a fallback here: a hard-coded
// default in this tracked file would re-expose the signing key publicly. If the
// secret is missing, fail closed rather than silently signing with a known value.
if (!defined('JWT_SECRET') || JWT_SECRET === '') {
    json_error('Server auth misconfiguration', 500);
}
if (!defined('JWT_EXPIRY')) {
    define('JWT_EXPIRY', 86400 * 30); // 30 days
}

/**
 * Generate a JWT token for a user.
 */
function generate_jwt(int $userID, string $name): string {
    $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));

    $payload = base64url_encode(json_encode([
        'sub' => $userID,
        'name' => $name,
        'iat' => time(),
        'exp' => time() + JWT_EXPIRY,
    ]));

    $signature = base64url_encode(
        hash_hmac('sha256', "{$header}.{$payload}", JWT_SECRET, true)
    );

    return "{$header}.{$payload}.{$signature}";
}

/**
 * Verify and decode a JWT token. Returns the payload or null on failure.
 */
function verify_jwt(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    [$header, $payload, $signature] = $parts;

    // Verify signature
    $expectedSig = base64url_encode(
        hash_hmac('sha256', "{$header}.{$payload}", JWT_SECRET, true)
    );

    if (!hash_equals($expectedSig, $signature)) return null;

    // Decode payload
    $data = json_decode(base64url_decode($payload), true);
    if (!$data) return null;

    // Check expiry
    if (isset($data['exp']) && $data['exp'] < time()) return null;

    return $data;
}

/**
 * Extract the bearer token from the Authorization header.
 */
function get_bearer_token(): ?string {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
        return trim($matches[1]);
    }

    return null;
}

/**
 * Require authentication. Returns the authenticated user ID.
 * Halts execution with 401 if not authenticated.
 */
function require_auth(): int {
    $token = get_bearer_token();

    if (!$token) {
        json_error('Authentication required', 401);
    }

    $payload = verify_jwt($token);

    if (!$payload || !isset($payload['sub'])) {
        json_error('Invalid or expired token', 401);
    }

    return (int)$payload['sub'];
}

/**
 * Fine-grained authorization helpers.
 *
 * The dashboard is a shared household: every authenticated member may READ and
 * CREATE records. For EDIT/DELETE we enforce ownership so a member cannot modify
 * another member's personally-owned record. Records with no owner (joint/shared,
 * owner id 0 or NULL) remain editable by any member.
 */

/**
 * Decide whether $userID may mutate (edit/delete) a record owned by $ownerID.
 * An owner id of 0 or null means the record is joint/shared and mutable by all.
 */
function can_mutate_owned(int $userID, ?int $ownerID): bool {
    if ($ownerID === null || $ownerID === 0) {
        return true; // joint / unowned
    }
    return $ownerID === $userID;
}

/**
 * Require that $userID may mutate a record owned by $ownerID, or halt with 403.
 * $notFound signals the caller passed an owner lookup for a row that doesn't
 * exist, so we surface a 404 instead of leaking existence via a 403.
 */
function require_can_mutate(int $userID, ?int $ownerID, bool $rowExists = true): void {
    if (!$rowExists) {
        json_error('Not found', 404);
    }
    if (!can_mutate_owned($userID, $ownerID)) {
        json_error('You do not have permission to modify this record', 403);
    }
}

/**
 * Base64 URL-safe encode.
 */
function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Base64 URL-safe decode.
 */
function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}
