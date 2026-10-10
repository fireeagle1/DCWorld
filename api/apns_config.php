<?php
/**
 * APNs (Apple Push Notification service) configuration.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * YOU MUST FILL IN THE FOUR VALUES BELOW BEFORE PUSH WILL WORK.
 * See the "What I need you to do" notes for where to find each one.
 * ─────────────────────────────────────────────────────────────────────────
 *
 * SECURITY: the .p8 auth key is a SECRET. Store it OUTSIDE the web root
 * (not under htdocs) and never commit it to git. The path below points
 * one level above htdocs by default — adjust if your server differs.
 */

return [
    // Apple Developer → Keys → your APNs key. 10-character string, e.g. "ABC123DEFG".
    'key_id'    => 'TODO_YOUR_KEY_ID',

    // Apple Developer → Membership → Team ID. 10-character string, e.g. "9XYZ8TEAM1".
    'team_id'   => 'TODO_YOUR_TEAM_ID',

    // The app's bundle identifier. This is the APNs "topic".
    'bundle_id' => 'uk.co.ckenterprises.thedash',

    // Absolute path to the downloaded AuthKey_XXXXXXXXXX.p8 file.
    //
    // SECURITY: keep this OUTSIDE the web-accessible folder (public_html).
    // On cPanel your home dir is typically /home/<cpaneluser>, and the site is
    // served from /home/<cpaneluser>/public_html — so a "secrets" folder in the
    // home dir sits safely above the web root:
    //     /home/<cpaneluser>/secrets/AuthKey_XXXXXXXXXX.p8
    //
    // __DIR__ resolves this relative to api/, climbing up out of public_html
    // regardless of the exact cPanel username. Adjust the filename to match
    // your downloaded key. If this relative path doesn't resolve on your host,
    // replace it with the absolute path shown in cPanel's File Manager.
    'auth_key_path' => dirname(__DIR__, 3) . '/secrets/AuthKey_TODO.p8',

    // APNs host. Use the sandbox host for development builds signed with a
    // development provisioning profile; the production host for App Store /
    // TestFlight builds. The device reports which it registered against, and
    // APNsPusher picks the host per-token based on the stored Environment.
    'host_production' => 'https://api.push.apple.com',
    'host_sandbox'    => 'https://api.sandbox.push.apple.com',
];
