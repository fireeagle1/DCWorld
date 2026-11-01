<?php
/**
 * Shared login brute-force throttle.
 *
 * Backed by the LoginAttempts table (see api/migrations/002_login_attempts.sql).
 * Used by both the web login (authenticate.php) and the mobile API
 * (api/login.php).
 *
 * Strategy — a sliding window over FAILED attempts:
 *   - Count failures for the (email + IP) pair within LOGIN_THROTTLE_WINDOW.
 *   - Once that count reaches LOGIN_THROTTLE_MAX_PER_PAIR, further attempts for
 *     that pair are blocked for LOGIN_THROTTLE_LOCKOUT.
 *   - A separate, higher cap on failures from a single IP across ALL accounts
 *     (LOGIN_THROTTLE_MAX_PER_IP) catches someone spraying many usernames from
 *     one host without letting them lock every account.
 *
 * Design notes:
 *   - We key on email+IP (not email alone) so an attacker cannot deny service
 *     to a real user by hammering their address from elsewhere.
 *   - A successful login clears the pair's failures so a user who finally gets
 *     their password right is not left locked out by earlier typos.
 *   - FAIL OPEN: if the table is missing or a query errors, we allow the login
 *     to proceed rather than locking everyone out of the product. The throttle
 *     is a mitigation, not an authentication control.
 */

// Max failed attempts for one (email, IP) pair before it is locked.
if (!defined('LOGIN_THROTTLE_MAX_PER_PAIR')) define('LOGIN_THROTTLE_MAX_PER_PAIR', 5);
// Max failed attempts from a single IP across all accounts before it is locked.
if (!defined('LOGIN_THROTTLE_MAX_PER_IP'))   define('LOGIN_THROTTLE_MAX_PER_IP', 20);
// Sliding window (seconds) over which failures are counted.
if (!defined('LOGIN_THROTTLE_WINDOW'))       define('LOGIN_THROTTLE_WINDOW', 900);      // 15 min
// How long a locked key stays locked (seconds). Implemented as the window —
// once failures age out of the window the key unlocks automatically.
if (!defined('LOGIN_THROTTLE_LOCKOUT'))      define('LOGIN_THROTTLE_LOCKOUT', 900);     // 15 min

/**
 * Best-effort client IP. We deliberately use REMOTE_ADDR only: forwarded
 * headers (X-Forwarded-For) are client-controlled and would let an attacker
 * rotate the throttle key trivially. If you run behind a trusted proxy, resolve
 * the real IP in config and pass it in instead.
 */
function login_throttle_client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    // Normalise/validate; fall back to a constant bucket if unparseable so the
    // per-IP cap still applies to malformed sources.
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

/**
 * Is this login attempt currently blocked?
 *
 * @return array{blocked:bool, retryAfter:int}
 *   retryAfter is an approximate number of seconds until the caller may try
 *   again (0 when not blocked).
 */
function login_throttle_check(mysqli $link, string $email, ?string $ip = null): array {
    $email = strtolower(trim($email));
    $ip = $ip ?? login_throttle_client_ip();

    $notBlocked = ['blocked' => false, 'retryAfter' => 0];

    // Per-pair check.
    $pair = login_throttle_window_stats($link, $email, $ip, false);
    if ($pair === null) {
        return $notBlocked; // fail open on error
    }
    if ($pair['count'] >= LOGIN_THROTTLE_MAX_PER_PAIR) {
        return ['blocked' => true, 'retryAfter' => login_throttle_retry_after($pair['oldest'])];
    }

    // Per-IP check (across all accounts).
    $perIp = login_throttle_window_stats($link, null, $ip, true);
    if ($perIp === null) {
        return $notBlocked;
    }
    if ($perIp['count'] >= LOGIN_THROTTLE_MAX_PER_IP) {
        return ['blocked' => true, 'retryAfter' => login_throttle_retry_after($perIp['oldest'])];
    }

    return $notBlocked;
}

/**
 * Record a failed login attempt. Call this on every password mismatch and on
 * attempts against unknown emails (so username enumeration is also throttled).
 */
function login_throttle_record_failure(mysqli $link, string $email, ?string $ip = null): void {
    $email = strtolower(trim($email));
    $ip = $ip ?? login_throttle_client_ip();

    try {
        $stmt = $link->prepare("INSERT INTO LoginAttempts (Email, IP) VALUES (?, ?)");
        if ($stmt === false) return; // fail open
        $stmt->bind_param('ss', $email, $ip);
        @$stmt->execute();
        $stmt->close();
    } catch (\Throwable $e) {
        // Swallow — the throttle must never block a login from working because
        // its own bookkeeping failed.
    }

    // Opportunistic cleanup of rows older than the window (cheap, keeps the
    // table bounded without needing a cron job). Runs ~1 in 20 calls.
    if (random_int(1, 20) === 1) {
        try {
            $link->query(
                "DELETE FROM LoginAttempts WHERE CreatedAt < (NOW() - INTERVAL " . (int)LOGIN_THROTTLE_WINDOW . " SECOND)"
            );
        } catch (\Throwable $e) {
            // ignore
        }
    }
}

/**
 * Clear failures for an (email, IP) pair after a successful login.
 */
function login_throttle_clear(mysqli $link, string $email, ?string $ip = null): void {
    $email = strtolower(trim($email));
    $ip = $ip ?? login_throttle_client_ip();

    try {
        $stmt = $link->prepare("DELETE FROM LoginAttempts WHERE Email = ? AND IP = ?");
        if ($stmt === false) return;
        $stmt->bind_param('ss', $email, $ip);
        @$stmt->execute();
        $stmt->close();
    } catch (\Throwable $e) {
        // ignore
    }
}

/**
 * Count failures inside the window and find the oldest one, so we can compute a
 * retry-after. Returns null on any error (caller then fails open).
 *
 * @return array{count:int, oldest:?int}|null  oldest is a unix timestamp.
 */
function login_throttle_window_stats(mysqli $link, ?string $email, string $ip, bool $ipOnly): ?array {
    try {
        if ($ipOnly) {
            $sql = "SELECT COUNT(*) AS c, UNIX_TIMESTAMP(MIN(CreatedAt)) AS oldest
                    FROM LoginAttempts
                    WHERE IP = ? AND CreatedAt >= (NOW() - INTERVAL ? SECOND)";
            $stmt = $link->prepare($sql);
            if ($stmt === false) return null;
            $window = (int)LOGIN_THROTTLE_WINDOW;
            $stmt->bind_param('si', $ip, $window);
        } else {
            $sql = "SELECT COUNT(*) AS c, UNIX_TIMESTAMP(MIN(CreatedAt)) AS oldest
                    FROM LoginAttempts
                    WHERE Email = ? AND IP = ? AND CreatedAt >= (NOW() - INTERVAL ? SECOND)";
            $stmt = $link->prepare($sql);
            if ($stmt === false) return null;
            $window = (int)LOGIN_THROTTLE_WINDOW;
            $stmt->bind_param('ssi', $email, $ip, $window);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row === null) return null;
        return [
            'count' => (int)$row['c'],
            'oldest' => $row['oldest'] !== null ? (int)$row['oldest'] : null,
        ];
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Seconds until the window frees up, based on when the oldest counted failure
 * will age out. Clamped to [1, LOGIN_THROTTLE_LOCKOUT].
 */
function login_throttle_retry_after(?int $oldest): int {
    if ($oldest === null) return (int)LOGIN_THROTTLE_LOCKOUT;
    $freesAt = $oldest + (int)LOGIN_THROTTLE_WINDOW;
    $remaining = $freesAt - time();
    if ($remaining < 1) return 1;
    if ($remaining > (int)LOGIN_THROTTLE_LOCKOUT) return (int)LOGIN_THROTTLE_LOCKOUT;
    return $remaining;
}
