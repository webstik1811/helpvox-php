<?php

declare(strict_types=1);

namespace HelpVox;

use HelpVox\Exception\ApiException;

/**
 * Internal HTTP layer shared by Client and OAuth.
 *
 * A transport is any callable
 *   fn(string $method, string $url, array $headers, ?string $body): array{status:int, body:string}
 * where $headers is a list of "Name: value" strings. The default one uses ext-curl.
 *
 * @internal
 */
final class Http
{
    public const TIMEOUT_SECONDS = 30;

    /**
     * @return callable(string, string, list<string>, ?string): array{status:int, body:string}
     */
    public static function curlTransport(): callable
    {
        return static function (string $method, string $url, array $headers, ?string $body): array {
            if (!\function_exists('curl_init')) {
                throw new ApiException('The HelpVox SDK needs the PHP curl extension (ext-curl).', 0, 'curl_missing');
            }
            $ch = curl_init($url);
            if ($ch === false) {
                throw new ApiException('Could not initialise curl.', 0, 'network_error');
            }
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $response = curl_exec($ch);
            if ($response === false) {
                $error = curl_error($ch);
                unset($ch); // handles are objects since PHP 8; curl_close() is deprecated in 8.5
                throw new ApiException('Request to HelpVox failed: ' . $error, 0, 'network_error');
            }
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            unset($ch); // handles are objects since PHP 8; curl_close() is deprecated in 8.5

            return ['status' => $status, 'body' => (string) $response];
        };
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function decode(string $body): ?array
    {
        if ($body === '') {
            return null;
        }
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($data) ? $data : null;
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Builds an ApiException from either error envelope. */
    public static function error(int $status, string $rawBody): ApiException
    {
        $data = self::decode($rawBody);
        $code = 'http_' . $status;
        $message = 'HelpVox answered HTTP ' . $status . '.';
        $scope = null;

        if (\is_array($data) && isset($data['error'])) {
            $error = $data['error'];
            if (\is_array($error)) {
                // REST: {"error":{"code","message","scope"?}}
                if (isset($error['code']) && \is_string($error['code'])) {
                    $code = $error['code'];
                }
                if (isset($error['message']) && \is_string($error['message'])) {
                    $message = $error['message'];
                }
                if (isset($error['scope']) && \is_string($error['scope'])) {
                    $scope = $error['scope'];
                }
            } elseif (\is_string($error)) {
                // OAuth: {"error":"invalid_grant","error_description":"…"}
                $code = $error;
                $message = isset($data['error_description']) && \is_string($data['error_description'])
                    ? $data['error_description']
                    : $error;
            }
        }

        return new ApiException($message, $status, $code, $scope, $data);
    }
}
