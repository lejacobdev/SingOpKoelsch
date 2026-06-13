<?php
/**
 * APNs HTTP/2 push notification sender.
 *
 * Setup: in config.php add:
 *   define('APNS_KEY_PATH',  '/path/to/AuthKey_XXXXXXXXXX.p8');
 *   define('APNS_KEY_ID',    'XXXXXXXXXX');
 *   define('APNS_TEAM_ID',   'YYYYYYYYYY');
 *   define('APNS_BUNDLE_ID', 'de.singopkoelsch.app');
 */
class APNs {
    private static ?string $cachedJwt = null;
    private static int $jwtIssuedAt = 0;

    public static function send(
        string $deviceToken,
        string $title,
        string $body,
        array  $extra = [],
        bool   $sandbox = false
    ): bool {
        if (!defined('APNS_KEY_PATH') || !file_exists(APNS_KEY_PATH)) {
            error_log("APNs: APNS_KEY_PATH not configured or file missing");
            return false;
        }

        $host = $sandbox
            ? 'https://api.sandbox.push.apple.com'
            : 'https://api.push.apple.com';

        $payload = json_encode([
            'aps' => [
                'alert' => ['title' => $title, 'body' => $body],
                'sound' => 'default',
                'badge' => 1,
            ],
            'extra' => $extra,
        ]);

        $jwt    = self::getJwt();
        $url    = "$host/3/device/$deviceToken";
        $headers = [
            "authorization: bearer $jwt",
            "apns-topic: " . APNS_BUNDLE_ID,
            "apns-push-type: alert",
            "apns-priority: 10",
            "content-type: application/json",
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2TLS,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 10,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("APNs: HTTP $httpCode for token $deviceToken — $response");
            return false;
        }
        return true;
    }

    private static function getJwt(): string {
        // Reuse token if issued less than 50 minutes ago
        if (self::$cachedJwt && (time() - self::$jwtIssuedAt) < 3000) {
            return self::$cachedJwt;
        }

        $header  = self::base64url(json_encode(['alg' => 'ES256', 'kid' => APNS_KEY_ID]));
        $issuedAt = time();
        $claims  = self::base64url(json_encode(['iss' => APNS_TEAM_ID, 'iat' => $issuedAt]));
        $message = "$header.$claims";

        $key = openssl_pkey_get_private(file_get_contents(APNS_KEY_PATH));
        openssl_sign($message, $sig, $key, OPENSSL_ALGO_SHA256);
        // Convert DER → raw r||s for JWT ES256
        $sig = self::derToRaw($sig);

        self::$cachedJwt   = "$message." . self::base64url($sig);
        self::$jwtIssuedAt = $issuedAt;
        return self::$cachedJwt;
    }

    private static function base64url(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function derToRaw(string $der): string {
        // ASN.1 DER SEQUENCE → 64-byte r||s
        $offset = 0;
        if (ord($der[$offset++]) !== 0x30) return $der; // not SEQUENCE
        $offset++;                                        // skip length
        if (ord($der[$offset++]) !== 0x02) return $der; // not INTEGER r
        $rLen = ord($der[$offset++]);
        $r = substr($der, $offset, $rLen); $offset += $rLen;
        if (ord($der[$offset++]) !== 0x02) return $der; // not INTEGER s
        $sLen = ord($der[$offset++]);
        $s = substr($der, $offset, $sLen);
        // Trim or pad to 32 bytes each
        $r = ltrim($r, "\x00"); $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
        $s = ltrim($s, "\x00"); $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);
        return $r . $s;
    }
}
