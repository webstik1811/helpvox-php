<?php

declare(strict_types=1);

namespace HelpVox;

use HelpVox\Exception\ToolError;

/**
 * Agent tool endpoints (contract section 8).
 *
 *     HelpVox\Tool::handle(getenv('HELPVOX_CLIENT_SECRET'), function (array $input, HelpVox\ToolContext $ctx): string {
 *         $points = find_points($input['phone']);
 *         if ($points === null) {
 *             throw new HelpVox\Exception\ToolError('No loyalty account for this phone number.');
 *         }
 *         return "The customer has {$points} points.";
 *     });
 *
 * The handler returns plain text for the agent (cut to 2000 characters) or throws
 * ToolError with a short reason the agent can tell the customer. Keep it fast:
 * phone tools have 1.5 s unless the manifest marks them `slow`.
 */
final class Tool
{
    public const MAX_RESULT_CHARS = 2000;
    public const MAX_ERROR_CHARS = 300;
    public const GENERIC_ERROR = 'The app could not complete this request right now.';

    /**
     * Full handler for a plain PHP endpoint: reads the request, verifies it, calls
     * $handler and writes the JSON response (200, or 401 on a bad signature).
     *
     * @param callable(array<string,mixed>, ToolContext): (string|\Stringable) $handler
     * @param array<string,string|list<string>>|null                          $headers
     */
    public static function handle(
        string $secret,
        callable $handler,
        ?string $rawBody = null,
        ?array $headers = null,
    ): void {
        [$status, $body] = self::process($secret, $handler, $rawBody ?? Headers::rawBody(), $headers);
        self::respond($status, $body);
    }

    /**
     * Pure variant for frameworks and tests: returns `[status, body]` without
     * touching the output. Encode `body` as JSON yourself (WordPress:
     * `new WP_REST_Response($body, $status)`).
     *
     * @param callable(array<string,mixed>, ToolContext): (string|\Stringable) $handler
     * @param array<string,string|list<string>>|null                          $headers read from the current request when null
     *
     * @return array{0:int, 1:array<string,string>}
     */
    public static function process(
        string $secret,
        callable $handler,
        string $rawBody,
        ?array $headers = null,
        ?int $now = null,
    ): array {
        $h = new Headers($headers);
        if (!Signature::verify($secret, $rawBody, $h->get(Signature::HEADER), $now)) {
            return [401, ['error' => 'invalid_signature']];
        }

        $request = Http::decode($rawBody);
        if ($request === null || !isset($request['tool']) || !\is_string($request['tool'])) {
            return [200, ['error' => 'Malformed tool request.']];
        }
        $input = isset($request['input']) && \is_array($request['input']) ? $request['input'] : [];
        $context = isset($request['context']) && \is_array($request['context']) ? $request['context'] : [];
        $ctx = ToolContext::fromArray($request['tool'], $context);

        try {
            $result = $handler($input, $ctx);
        } catch (ToolError $e) {
            $message = trim($e->getMessage());

            return [200, ['error' => self::truncate($message === '' ? self::GENERIC_ERROR : $message, self::MAX_ERROR_CHARS)]];
        } catch (\Throwable $e) {
            // Never leak internals to the customer; leave a trace for the developer.
            error_log('[helpvox] tool "' . $ctx->tool . '" failed: ' . $e::class . ': ' . $e->getMessage());

            return [200, ['error' => self::GENERIC_ERROR]];
        }

        if (\is_string($result) || $result instanceof \Stringable || \is_int($result) || \is_float($result)) {
            $text = trim((string) $result);
            if ($text !== '') {
                return [200, ['result' => self::truncate($text, self::MAX_RESULT_CHARS)]];
            }
        }
        error_log('[helpvox] tool "' . $ctx->tool . '" returned no text; return a non-empty string or throw ToolError.');

        return [200, ['error' => self::GENERIC_ERROR]];
    }

    /**
     * Writes a JSON response and its status code.
     *
     * @param array<string,mixed> $body
     */
    public static function respond(int $status, array $body): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo Http::encode($body === [] ? new \stdClass() : $body);
    }

    /** Cuts to $max characters (not bytes), never splitting a UTF-8 sequence. */
    public static function truncate(string $text, int $max): string
    {
        if (\function_exists('mb_strlen')) {
            return mb_strlen($text, 'UTF-8') > $max ? mb_substr($text, 0, $max, 'UTF-8') : $text;
        }
        if (preg_match('/^.{0,' . $max . '}/su', $text, $m) === 1) {
            return $m[0];
        }

        return substr($text, 0, $max);
    }
}
