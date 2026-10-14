<?php

declare(strict_types=1);

namespace HelpVox;

use HelpVox\Exception\ApiException;

/**
 * The installation flow (contract section 4).
 *
 * 1. Send the merchant to installUrl().
 * 2. HelpVox redirects to your redirect URL with `?code=hvic_…&installation_id=…&state=…`.
 * 3. Your server calls exchangeCode() and stores `access_token` against `installation_id`.
 * 4. When your own setup is done, call (new Client($token))->ready().
 */
final class OAuth
{
    /**
     * URL of the consent screen for an app.
     *
     * @param string      $slug        the app's manifest slug
     * @param string|null $redirectUrl must be one of the manifest's redirect_urls (default: the first one)
     * @param string|null $state       opaque value echoed back to the redirect URL — use it against CSRF
     */
    public static function installUrl(
        string $slug,
        ?string $redirectUrl = null,
        ?string $state = null,
        string $baseUrl = Client::DEFAULT_BASE_URL,
    ): string {
        $query = [];
        if ($redirectUrl !== null && $redirectUrl !== '') {
            $query['redirect_url'] = $redirectUrl;
        }
        if ($state !== null && $state !== '') {
            $query['state'] = $state;
        }
        $url = rtrim($baseUrl, '/') . '/apps/install/' . rawurlencode($slug);

        return $query === [] ? $url : $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchanges the one-time `hvic_…` code for an installation access token.
     *
     * The code lives 10 minutes and works once; using it twice revokes the installation,
     * so never retry this call blindly after a timeout.
     *
     * @param callable|null $transport same shape as for Client
     *
     * @return array<string,mixed> `{ access_token, token_type, scope, installation_id, vendor_id }`
     *
     * @throws ApiException with getErrorCode() e.g. `invalid_grant`, `invalid_client`
     */
    public static function exchangeCode(
        string $clientId,
        string $clientSecret,
        string $code,
        string $baseUrl = Client::DEFAULT_BASE_URL,
        ?callable $transport = null,
    ): array {
        $transport ??= Http::curlTransport();
        $body = http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ], '', '&', PHP_QUERY_RFC1738);
        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            'User-Agent: helpvox-php/' . Client::VERSION,
        ];

        $response = $transport('POST', rtrim($baseUrl, '/') . '/api/oauth/token', $headers, $body);
        $status = (int) ($response['status'] ?? 0);
        $raw = (string) ($response['body'] ?? '');

        if ($status < 200 || $status >= 300) {
            throw Http::error($status, $raw);
        }
        $data = Http::decode($raw);
        if ($data === null || !isset($data['access_token']) || !\is_string($data['access_token'])) {
            throw new ApiException('The token response has no access_token.', $status, 'invalid_response', null, $data);
        }

        return $data;
    }
}
