<?php
session_start();
require 'config.php';
require_once __DIR__ . '/lib/login_throttle.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Bound as a parameter below, so no escaping is needed here.
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Brute-force throttle: block this email+IP (or this IP) after too many
    // recent failures.
    $throttle = login_throttle_check($link, $email);
    if ($throttle['blocked']) {
        http_response_code(429);
        header('Retry-After: ' . $throttle['retryAfter']);
        $minutes = (int)ceil($throttle['retryAfter'] / 60);
        echo "Too many failed login attempts. Please try again in about {$minutes} minute(s).";
        $link->close();
        exit();
    }

    // Query to fetch user with matching email
    $sql = "SELECT UserID, Password FROM DC_Users WHERE Email = ?";
    $stmt = $link->prepare($sql);
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->bind_result($userID, $hashedPassword);
        $stmt->fetch();

        // Verify the password
        if (password_verify($password, $hashedPassword)) {
            // Password is correct — clear failure history, harden the session,
            // and start a fresh authenticated session.
            login_throttle_clear($link, $email);
            session_regenerate_id(true);
            $_SESSION['userID'] = $userID;
            $stmt->close();
            $link->close();
            header("Location: dashboard.php");
            exit();
        } else {
            login_throttle_record_failure($link, $email);
            echo "Invalid email or password.";
        }
    } else {
        // Record failures for unknown emails too, so enumeration is throttled.
        login_throttle_record_failure($link, $email);
        echo "Invalid email or password.";
    }

    $stmt->close();
}

$link->close();
?>
