-- Brute-force protection for the login endpoints.
--
-- One row per FAILED login attempt. We throttle on a combined
-- (email + IP) key so a single attacker cannot lock a legitimate user out
-- globally, while still capping both per-account and per-IP guessing.
--
-- Rows are transient: successful logins clear the matching rows, and the
-- throttle logic only ever looks at rows inside its sliding window. A periodic
-- cleanup (see below) keeps the table small, but is optional.

CREATE TABLE IF NOT EXISTS LoginAttempts (
    AttemptID   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    -- Lower-cased email the attempt was made against (may be an unknown user).
    Email       VARCHAR(255) NOT NULL,
    -- Client IP as seen by PHP (REMOTE_ADDR). IPv6-capable length.
    IP          VARCHAR(45)  NOT NULL,
    CreatedAt   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (AttemptID),
    -- Composite lookup: "failures for this email+ip since <time>".
    KEY idx_email_ip_time (Email, IP, CreatedAt),
    -- Supports IP-only throttling and time-based cleanup.
    KEY idx_ip_time (IP, CreatedAt),
    KEY idx_created (CreatedAt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optional housekeeping (run from cron, or rely on the app's inline cleanup):
-- DELETE FROM LoginAttempts WHERE CreatedAt < (NOW() - INTERVAL 1 DAY);
