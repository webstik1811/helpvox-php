<?php

declare(strict_types=1);

namespace HelpVox;

/**
 * The dashboard session token (contract section 9).
 *
 * HelpVox embeds `ui.settings_page` as `<settings_page>?installation_id=<uuid>&session=<JWT>`.
 * The JWT is HS256, signed with the client secret, lives 60 seconds, and carries
 * `iss, aud (= client_id), sub (dashboard user), installation_id, vendor_id, lang, iat, exp`.
 * Verify it, then start your own session (cookie) — do not keep using the JWT.
 */
final class SessionToken
{
    public const TTL_SECONDS = 60;
    public const LEEWAY_SECONDS = 5;

    /**
     * @return array<string,mixed>|null the claims, or null when the token is invalid,
     *                                  for another app, or expired
     */
    public static function verify(string $secret, string $jwt, string $clientId, ?int $now = null): ?array
    {
        $parts = explode('.', $jwt);
        if (\count($parts) < 3) {
            return null;
        }
        [$header, $payload, $sig] = $parts;
        if ($header === '' || $payload === '' || $sig === '') {
            return null;
        }
        $expected = self::b64url(hash_hmac('sha256', $header . '.' . $payload, $secret, true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $h = self::decodePart($header);
        if ($h === null || ($h['alg'] ?? null) !== 'HS256') {
            return null;
        }
        $claims = self::decodePart($payload);
        if ($claims === null) {
            return null;
        }
        if (($claims['aud'] ?? null) !== $clientId) {
            return null;
        }
        $exp = $claims['exp'] ?? null;
        if (!\is_int($exp) && !\is_float($exp)) {
            return null;
        }
        if ($exp < ($now ?? time()) - self::LEEWAY_SECONDS) {
            return null;
        }

        return $claims;
    }

    /**
     * Issues a token the way HelpVox does — for tests and local development.
     *
     * @param array<string,mixed> $claims iss, aud, sub, installation_id, vendor_id, lang
     */
    public static function sign(string $secret, array $claims, ?int $now = null, int $ttl = self::TTL_SECONDS): string
    {
        $now ??= time();
        $header = self::b64url(Http::encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = self::b64url(Http::encode(array_merge($claims, ['iat' => $now, 'exp' => $now + $ttl])));
        $sig = self::b64url(hash_hmac('sha256', $header . '.' . $payload, $secret, true));

        return $header . '.' . $payload . '.' . $sig;
    }

    private static function b64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** @return array<string,mixed>|null */
    private static function decodePart(string $part): ?array
    {
        $b64 = strtr($part, '-_', '+/');
        $b64 .= str_repeat('=', (4 - \strlen($b64) % 4) % 4);
        $json = base64_decode($b64, true);
        if ($json === false) {
            return null;
        }

        return Http::decode($json);
    }
}
