<?php

declare(strict_types=1);

namespace HelpVox;

use HelpVox\Exception\SignatureException;

/**
 * Receiving events.
 *
 *     try {
 *         $event = HelpVox\Webhook::parse(getenv('HELPVOX_CLIENT_SECRET'));
 *     } catch (HelpVox\Exception\SignatureException $e) {
 *         http_response_code(401);
 *         exit;
 *     }
 *     // … handle $event, then answer 2xx within 10 seconds
 */
final class Webhook
{
    /**
     * Verifies the signature and decodes the event.
     *
     * @param string|null                            $rawBody the raw body; read from php://input when null
     * @param array<string,string|list<string>>|null $headers request headers (any case); read from the current request when null
     *
     * @throws SignatureException when the signature is missing, stale or wrong, or the body is not an event
     */
    public static function parse(
        string $secret,
        ?string $rawBody = null,
        ?array $headers = null,
        ?int $now = null,
    ): Event {
        $rawBody ??= Headers::rawBody();
        $h = new Headers($headers);

        if (!Signature::verify($secret, $rawBody, $h->get(Signature::HEADER), $now)) {
            throw new SignatureException('Invalid or stale HelpVox-Signature.');
        }
        $body = Http::decode($rawBody);
        if ($body === null || !isset($body['id'], $body['type']) || !\is_string($body['type'])) {
            throw new SignatureException('Signed body is not a HelpVox event.');
        }

        return Event::fromArray($body);
    }
}
