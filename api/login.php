<?php
/**
 * POST /api/login.php
 * 
 * Accepts email + password, returns a JWT bearer token.
 * 
 * Request body (JSON):
 *   { "email": "user@example.com", "password": "secret" }
 * 
 * Response (200):
 *   { "token": "...", "user": { "id": 1, "name": "Chris" } }
 * 
 * Response (401):
 *   { "error": "Invalid email or password" }
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth_middleware.php';
require_once dirname(__DIR__) . '/lib/login_throttle.php';

// Only accept POST
if (get_method() !== 'POST') {
    json_error('Method not allowed', 405);
}

$body = get_json_body();

$email = trim($body['email'] ?? '');
$password = $body['password'] ?? '';

if ($email === '' || $password === '') {
    json_error('Email and password are required');
}

// Brute-force throttle: reject early if this email+IP (or this IP) has made too
// many recent failed attempts.
$throttle = login_throttle_check($link, $email);
if ($throttle['blocked']) {
    header('Retry-After: ' . $throttle['retryAfter']);
    json_error('Too many failed login attempts. Try again later.', 429);
}

// Look up the user
$stmt = $link->prepare("SELECT UserID, Name, Email, IMGURL, Password FROM DC_Users WHERE Email = ?");
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
    $stmt->close();
    // Record the failure even for unknown emails so username enumeration is
    // throttled too. Keep the response identical to a wrong password.
    login_throttle_record_failure($link, $email);
    json_error('Invalid email or password', 401);
}

$stmt->bind_result($userID, $name, $userEmail, $imgURL, $hashedPassword);
$stmt->fetch();
$stmt->close();

// Verify password
if (!password_verify($password, $hashedPassword)) {
    login_throttle_record_failure($link, $email);
    json_error('Invalid email or password', 401);
}

// Success — clear this pair's failure history.
login_throttle_clear($link, $email);

// Generate JWT
$token = generate_jwt($userID, $name);

json_response([
    'token' => $token,
    'user' => [
        'id' => $userID,
        'name' => $name,
        'email' => $userEmail,
        'photoURL' => $imgURL ?? null,
    ],
]);
