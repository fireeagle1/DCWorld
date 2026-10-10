<?php
/**
 * /api/device_token.php
 *
 * POST { token, platform?, environment? } — register/refresh this device's
 *        APNs token for the authenticated user.
 * DELETE ?token=... (or body { token }) — unregister a device token
 *        (e.g. on logout).
 *
 * The device token is globally unique, so re-registering the same device
 * simply re-points the row at the current user.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth_middleware.php';

$userID = require_auth();
$method = get_method();

switch ($method) {
    case 'POST':
        handle_register($userID);
        break;
    case 'DELETE':
        handle_unregister($userID);
        break;
    default:
        json_error('Method not allowed', 405);
}

function handle_register(int $userID): void {
    global $link;

    $body = get_json_body();
    $token = trim(require_field($body, 'token'));
    $platform = trim($body['platform'] ?? 'ios');
    $environment = trim($body['environment'] ?? 'production');
    if ($environment !== 'sandbox' && $environment !== 'production') {
        $environment = 'production';
    }

    $stmt = $link->prepare(
        "INSERT INTO DeviceTokens (UserID, Token, Platform, Environment)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            UserID = VALUES(UserID),
            Platform = VALUES(Platform),
            Environment = VALUES(Environment),
            UpdatedAt = CURRENT_TIMESTAMP"
    );
    $stmt->bind_param('isss', $userID, $token, $platform, $environment);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        json_error('Failed to register device token: ' . $err, 500);
    }
    $stmt->close();

    json_response(['registered' => true]);
}

function handle_unregister(int $userID): void {
    global $link;

    $token = $_GET['token'] ?? (get_json_body()['token'] ?? '');
    $token = trim($token);
    if ($token === '') {
        json_error('token is required');
    }

    // Only let a user remove a token currently attached to their own account.
    $stmt = $link->prepare("DELETE FROM DeviceTokens WHERE Token = ? AND UserID = ?");
    $stmt->bind_param('si', $token, $userID);
    $stmt->execute();
    $stmt->close();

    json_response(['unregistered' => true]);
}
