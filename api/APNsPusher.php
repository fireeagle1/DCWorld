<?php
/**
 * APNsPusher
 *
 * Dependency-free APNs sender using token-based (.p8 / ES256 JWT) auth and
 * HTTP/2 via cURL. No Composer packages required — consistent with the rest
 * of this API, which hand-rolls its JWT handling.
 *
 * Requires:
 *   - PHP cURL built with HTTP/2 support (XAMPP's bundled curl has this).
 *   - OpenSSL (for ES256 signing of the provider JWT).
 *
 * Usage:
 *   $pusher = new APNsPusher();                 // reads apns_config.php
 *   $pusher->sendAlert($tokens, 'Title', 'Body', ['eventID' => 123]);
 *   $pusher->sendSilent($tokens, ['sync' => 'events']);
 *
 * $tokens is an array of ['token' => '...', 'environment' => 'production'|'sandbox'].
 */
final class APNsPusher
{
    private array $config;
    private ?string $cachedJwt = null;
    private int $cachedJwtTime = 0;

    /** Tokens APNs reported as permanently invalid (HTTP 410 / BadDeviceToken). */
    public array $invalidTokens = [];

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? require __DIR__ . '/apns_config.php';
    }

    /**
     * True only when every required credential is present and the key file
     * exists. Callers should use this to no-op gracefully before the user has
     * supplied their APNs credentials, so event creation never fails just
     * because push isn't configured yet.
     */
    public function isConfigured(): bool
    {
        foreach (['key_id', 'team_id', 'bundle_id', 'auth_key_path'] as $k) {
            $v = $this->config[$k] ?? '';
            if ($v === '' || str_starts_with((string)$v, 'TODO_')) {
                return false;
            }
        }
        return is_readable($this->config['auth_key_path']);
    }

    /**
     * Send a visible alert push to each token.
     *
     * @param array $tokens  list of ['token'=>..., 'environment'=>...]
     * @param array $extra   additional top-level keys merged into the payload
     *                       (e.g. ['eventID' => 123] for deep-linking on tap)
     */
    public function sendAlert(array $tokens, string $title, string $body, array $extra = []): void
    {
        $payload = array_merge([
            'aps' => [
                'alert' => ['title' => $title, 'body' => $body],
                'sound' => 'default',
            ],
        ], $extra);

        $this->sendAll($tokens, $payload, 'alert');
    }

    /**
     * Send a silent (content-available) push to each token. No alert is shown;
     * iOS wakes the app in the background so it can fetch/sync.
     */
    public function sendSilent(array $tokens, array $extra = []): void
    {
        $payload = array_merge([
            'aps' => ['content-available' => 1],
        ], $extra);

        $this->sendAll($tokens, $payload, 'background');
    }

    /* ─────────────────────────────────────────────────────────── */

    private function sendAll(array $tokens, array $payload, string $pushType): void
    {
        if (!$this->isConfigured() || empty($tokens)) {
            return;
        }

        $jwt  = $this->providerToken();
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        foreach ($tokens as $entry) {
            $deviceToken = $entry['token'] ?? '';
            if ($deviceToken === '') continue;

            $env  = ($entry['environment'] ?? 'production') === 'sandbox' ? 'sandbox' : 'production';
            $host = $env === 'sandbox' ? $this->config['host_sandbox'] : $this->config['host_production'];

            $this->send($host, $deviceToken, $jwt, $body, $pushType);
        }
    }

    private function send(string $host, string $deviceToken, string $jwt, string $body, string $pushType): void
    {
        $url = rtrim($host, '/') . '/3/device/' . $deviceToken;

        $headers = [
            'authorization: bearer ' . $jwt,
            'apns-topic: ' . $this->config['bundle_id'],
            'apns-push-type: ' . $pushType,
            // Alerts are user-facing (priority 10). Silent pushes must be
            // priority 5 or APNs rejects them.
            'apns-priority: ' . ($pushType === 'background' ? '5' : '10'),
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($status === 200) {
            return; // delivered to APNs
        }

        // 410 = device token no longer valid; 400 BadDeviceToken similar.
        // Record it so the caller can prune it from the DB.
        $reason = '';
        if ($response) {
            $decoded = json_decode($response, true);
            $reason  = $decoded['reason'] ?? '';
        }

        if ($status === 410 || $reason === 'BadDeviceToken' || $reason === 'Unregistered') {
            $this->invalidTokens[] = $deviceToken;
        }

        // Log but never throw — a push failure must not break event creation.
        error_log("APNs send failed (status={$status}, reason={$reason}, curl={$curlErr}) for token "
            . substr($deviceToken, 0, 8) . '…');
    }

    /**
     * Build (and cache for ~50 min) the ES256 provider JWT APNs requires.
     * APNs rejects provider tokens older than 60 minutes, so we refresh well
     * inside that window.
     */
    private function providerToken(): string
    {
        if ($this->cachedJwt !== null && (time() - $this->cachedJwtTime) < 3000) {
            return $this->cachedJwt;
        }

        $header = $this->base64url(json_encode([
            'alg' => 'ES256',
            'kid' => $this->config['key_id'],
        ]));

        $claims = $this->base64url(json_encode([
            'iss' => $this->config['team_id'],
            'iat' => time(),
        ]));

        $signingInput = $header . '.' . $claims;

        $pkey = openssl_pkey_get_private(file_get_contents($this->config['auth_key_path']));
        if ($pkey === false) {
            throw new RuntimeException('APNs: failed to load .p8 private key');
        }

        $der = '';
        if (!openssl_sign($signingInput, $der, $pkey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('APNs: failed to sign provider token');
        }

        // openssl emits an ASN.1/DER ECDSA signature; APNs (JWS ES256) expects
        // the raw 64-byte R||S concatenation. Convert it.
        $signature = $this->derToRaw($der);

        $jwt = $signingInput . '.' . $this->base64url($signature);

        $this->cachedJwt = $jwt;
        $this->cachedJwtTime = time();
        return $jwt;
    }

    /** Convert an ASN.1/DER ECDSA signature to a raw 64-byte R||S value. */
    private function derToRaw(string $der): string
    {
        $offset = 0;
        if (ord($der[$offset++]) !== 0x30) {
            throw new RuntimeException('APNs: invalid DER signature (no sequence)');
        }
        // Skip sequence length (handles the short form used by P-256 sigs).
        $seqLen = ord($der[$offset++]);
        if ($seqLen & 0x80) {
            $offset += ($seqLen & 0x7f);
        }

        $readInt = function () use ($der, &$offset): string {
            if (ord($der[$offset++]) !== 0x02) {
                throw new RuntimeException('APNs: invalid DER signature (no integer)');
            }
            $len = ord($der[$offset++]);
            $val = substr($der, $offset, $len);
            $offset += $len;
            // Strip a leading zero byte added to keep the integer positive.
            $val = ltrim($val, "\x00");
            // Left-pad to 32 bytes.
            return str_pad($val, 32, "\x00", STR_PAD_LEFT);
        };

        $r = $readInt();
        $s = $readInt();
        return $r . $s;
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
