-- Device tokens for APNs push notifications.
-- Each row maps a logged-in user to one device's APNs token.
-- A device token is globally unique, so the token column is the natural key:
-- if the same device is re-registered (e.g. a different user logs in on it),
-- the row is updated to point at the new user via ON DUPLICATE KEY UPDATE.

CREATE TABLE IF NOT EXISTS DeviceTokens (
    TokenID      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    UserID       INT NOT NULL,
    Token        VARCHAR(255) NOT NULL,
    Platform     VARCHAR(16)  NOT NULL DEFAULT 'ios',
    -- 'sandbox' for development/TestFlight-debug builds, 'production' for App Store/TestFlight.
    Environment  VARCHAR(16)  NOT NULL DEFAULT 'production',
    CreatedAt    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UpdatedAt    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Which events this device wants push notifications for:
    --   'all'   = every event
    --   'mine'  = joint events + this user's own events
    --   'joint' = joint (shared) events only
    NotificationScope VARCHAR(16) NOT NULL DEFAULT 'mine',
    PRIMARY KEY (TokenID),
    UNIQUE KEY uq_token (Token),
    KEY idx_user (UserID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- If upgrading an existing install where DeviceTokens already exists without
-- the NotificationScope column, run this once (ignore "duplicate column" if
-- it's already present):
-- ALTER TABLE DeviceTokens ADD COLUMN NotificationScope VARCHAR(16) NOT NULL DEFAULT 'mine';
