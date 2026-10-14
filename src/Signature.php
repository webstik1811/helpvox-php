<?php

declare(strict_types=1);

namespace HelpVox;

/**
 * `HelpVox-Signature: t=<unix seconds>,v1=<hex HMAC-SHA256(client_secret, "<t>.<raw body>")>`
 *
 * Every request HelpVox sends to an app (events, tool calls) carries it. The key is
 * the app's client secret (`hvs_…`).
 */
final class Signature
{
    public const HEADER = 'HelpVox-Signature';
    public const TOLERANCE_SECONDS = 300;

    /** Produces a header value for $body — useful for tests and local replays. */
    public static function sign(string $secret, string $body, ?int $now = null): string
    {
        $t = $now ?? time();

        return 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $body, $secret);
    }

    /**
     * Checks a header against the **raw** request body (before any JSON decoding).
     * Rejects a missing header, a malformed one, a timestamp more than $tolerance
     * seconds away from now, and a wrong HMAC (constant-time compare).
     */
    public static function verify(
        string $secret,
        string $rawBody,
        ?string $header,
        ?int $now = null,
        int $tolerance = self::TOLERANCE_SECONDS,
    ): bool {
        if ($header === null || $header === '') {
            return false;
        }
        $parts = [];
        foreach (explode(',', $header) as $part) {
            $i = strpos($part, '=');
            if ($i === false) {
                continue;
            }
            $parts[trim(substr($part, 0, $i))] = trim(substr($part, $i + 1));
        }
        $t = $parts['t'] ?? '';
        if (!preg_match('/^-?\d{1,12}$/', $t)) {
            return false;
        }
        $t = (int) $t;
        $now ??= time();
        if (abs($now - $t) > $tolerance) {
            return false;
        }
        $expected = hash_hmac('sha256', $t . '.' . $rawBody, $secret);
        $given = $parts['v1'] ?? '';

        return hash_equals($expected, $given);
    }
}
