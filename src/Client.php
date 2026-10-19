<?php

declare(strict_types=1);

namespace HelpVox;

use HelpVox\Exception\ApiException;

/**
 * REST client for `/api/v1/*`.
 *
 * Authenticates with an installation access token (`hva_…`, from OAuth::exchangeCode())
 * or a company API key (`hv_live_…`). What you can read and write depends on the
 * scopes the merchant granted; a missing scope throws ApiException with code
 * `insufficient_scope` and getRequiredScope() set.
 *
 *     $hv = new HelpVox\Client($accessToken);
 *     $order = $hv->order($orderId);
 */
final class Client
{
    public const VERSION = '0.2.0';
    public const DEFAULT_BASE_URL = 'https://www.helpvox.ai';

    private readonly string $baseUrl;

    /** @var callable(string, string, list<string>, ?string): array{status:int, body:string} */
    private $transport;

    /**
     * @param string        $token     `hva_…` installation token or `hv_live_…` API key
     * @param string        $baseUrl   HelpVox base URL, without a trailing slash
     * @param callable|null $transport fn(string $method, string $url, array $headers, ?string $body): array{status:int, body:string}
     *                                 — inject a fake in tests; defaults to ext-curl with a 30 s timeout
     */
    public function __construct(
        private readonly string $token,
        string $baseUrl = self::DEFAULT_BASE_URL,
        ?callable $transport = null,
    ) {
        if ($token === '') {
            throw new \InvalidArgumentException('HelpVox token is empty.');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport ?? Http::curlTransport();
    }

    /**
     * Calls any `/api/v1/*` endpoint.
     *
     * @param string                    $path  e.g. `/api/v1/orders` or `orders` (the `/api/v1/` prefix is added when missing)
     * @param array<string,mixed>|null  $body  JSON body
     * @param array<string,mixed>       $query query string parameters; null values are dropped
     *
     * @return array<string,mixed> decoded JSON (empty array for an empty 2xx body)
     *
     * @throws ApiException on non-2xx or network failure
     */
    public function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $method = strtoupper($method);
        $url = $this->baseUrl . self::normalizePath($path) . self::queryString($query);

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
            'User-Agent: helpvox-php/' . self::VERSION,
        ];
        $payload = null;
        if ($body !== null) {
            // An empty PHP array must go out as {} — the API expects objects.
            $payload = $body === [] ? '{}' : Http::encode($body);
            $headers[] = 'Content-Type: application/json';
        }

        $response = ($this->transport)($method, $url, $headers, $payload);
        $status = (int) ($response['status'] ?? 0);
        $raw = (string) ($response['body'] ?? '');

        if ($status < 200 || $status >= 300) {
            throw Http::error($status, $raw);
        }
        if ($raw === '') {
            return [];
        }
        $data = Http::decode($raw);
        if ($data === null) {
            throw new ApiException('HelpVox returned a body that is not a JSON object.', $status, 'invalid_response');
        }

        return $data;
    }

    // ─── App-only endpoints ─────────────────────────────────────────────────

    /**
     * `GET /api/v1/app` — installation_id, app, vendor, scopes, ready, update_pending,
     * tools, events, capabilities.
     *
     * @return array<string,mixed>
     */
    public function app(): array
    {
        return $this->request('GET', '/api/v1/app');
    }

    /**
     * `POST /api/v1/app/ready` — turns the app's agent tools on. Call once your own
     * setup for this installation is done.
     *
     * @return array<string,mixed> `{ ok: true, ready_at }`
     */
    public function ready(): array
    {
        return $this->request('POST', '/api/v1/app/ready', []);
    }

    /**
     * `POST /api/v1/app/events/test` — sends a sample event to this installation only
     * (`data.test` is true). Throws `not_subscribed` (409) if the manifest does not list it.
     *
     * @param array<string,mixed>|null $data custom payload instead of the built-in sample
     *
     * @return array<string,mixed>
     */
    public function sendTestEvent(string $event, ?array $data = null): array
    {
        $body = ['event' => $event];
        if ($data !== null) {
            $body['data'] = (object) $data;
        }

        return $this->request('POST', '/api/v1/app/events/test', $body);
    }

    // ─── Orders and catalogue ───────────────────────────────────────────────

    /**
     * @param array<string,mixed> $query filters and paging, passed through as query parameters
     *
     * @return array<string,mixed>
     */
    public function orders(array $query = []): array
    {
        return $this->request('GET', '/api/v1/orders', null, $query);
    }

    /** @return array<string,mixed> */
    public function order(string $id): array
    {
        return $this->request('GET', '/api/v1/orders/' . rawurlencode($id));
    }

    /**
     * `PATCH /api/v1/orders/{id}` (orders:write).
     *
     * @param array<string,mixed> $patch
     *
     * @return array<string,mixed>
     */
    public function updateOrder(string $id, array $patch): array
    {
        return $this->request('PATCH', '/api/v1/orders/' . rawurlencode($id), $patch);
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return array<string,mixed>
     */
    public function products(array $query = []): array
    {
        return $this->request('GET', '/api/v1/products', null, $query);
    }

    // ─── Conversations and requests ─────────────────────────────────────────

    /**
     * @param array<string,mixed> $query
     *
     * @return array<string,mixed>
     */
    public function calls(array $query = []): array
    {
        return $this->request('GET', '/api/v1/calls', null, $query);
    }

    /** @return array<string,mixed> */
    public function call(string $id): array
    {
        return $this->request('GET', '/api/v1/calls/' . rawurlencode($id));
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return array<string,mixed>
     */
    public function callbacks(array $query = []): array
    {
        return $this->request('GET', '/api/v1/callbacks', null, $query);
    }

    /**
     * @param array<string,mixed> $query
     *
     * @return array<string,mixed>
     */
    public function appointments(array $query = []): array
    {
        return $this->request('GET', '/api/v1/appointments', null, $query);
    }

    // ─── Order discounts (orders:write) ─────────────────────────────────────

    /**
     * `POST /api/v1/discounts` — attaches a discount to the order the agent is about
     * to place in a conversation.
     *
     *     $hv->createDiscount([
     *         'conversation_id' => $ctx->conversationId,
     *         'amount' => 12.00,
     *         'currency' => 'EUR',
     *         'label' => 'Loyalty points',
     *         'reference' => 'redeem-123',
     *     ]);
     *
     * @param array<string,mixed> $discount
     *
     * @return array<string,mixed> `{ id, status: "pending", expires_at }`
     */
    public function createDiscount(array $discount): array
    {
        return $this->request('POST', '/api/v1/discounts', $discount);
    }

    /** @return array<string,mixed> */
    public function discount(string $id): array
    {
        return $this->request('GET', '/api/v1/discounts/' . rawurlencode($id));
    }

    /**
     * Cancels a pending discount.
     *
     * @return array<string,mixed>
     */
    public function cancelDiscount(string $id): array
    {
        return $this->request('DELETE', '/api/v1/discounts/' . rawurlencode($id));
    }

    // ─── Extra fees and payment method (orders:write) ───────────────────────

    /**
     * `POST /api/v1/fees` — an extra fee (gift wrapping, insurance) on the order the
     * agent is about to place in a conversation.
     *
     * @param array<string,mixed> $fee
     *
     * @return array<string,mixed>
     */
    public function createFee(array $fee): array
    {
        return $this->request('POST', '/api/v1/fees', $fee);
    }

    /** @return array<string,mixed> */
    public function fee(string $id): array
    {
        return $this->request('GET', '/api/v1/fees/' . rawurlencode($id));
    }

    /** @return array<string,mixed> */
    public function cancelFee(string $id): array
    {
        return $this->request('DELETE', '/api/v1/fees/' . rawurlencode($id));
    }

    /**
     * `POST /api/v1/payment-requests` — card, bank transfer or online payment instead
     * of cash on delivery for the order the agent is about to place.
     *
     *     $hv->createPaymentRequest([
     *         'conversation_id' => $ctx->conversationId,
     *         'method' => 'card',
     *         'label' => 'Card payment by link',
     *     ]);
     *
     * @param array<string,mixed> $request
     *
     * @return array<string,mixed>
     */
    public function createPaymentRequest(array $request): array
    {
        return $this->request('POST', '/api/v1/payment-requests', $request);
    }

    /** @return array<string,mixed> */
    public function paymentRequest(string $id): array
    {
        return $this->request('GET', '/api/v1/payment-requests/' . rawurlencode($id));
    }

    /** @return array<string,mixed> */
    public function cancelPaymentRequest(string $id): array
    {
        return $this->request('DELETE', '/api/v1/payment-requests/' . rawurlencode($id));
    }

    // ─── Knowledge and busy times ───────────────────────────────────────────

    /**
     * Adds knowledge entries owned by your app; they go when it is uninstalled.
     *
     * @param list<array{question:string,answer:string}> $items
     *
     * @return array<string,mixed>
     */
    public function addKnowledge(array $items): array
    {
        return $this->request('POST', '/api/v1/knowledge', ['items' => $items]);
    }

    /**
     * Replaces only your app's own entries.
     *
     * @param list<array{question:string,answer:string}> $items
     *
     * @return array<string,mixed>
     */
    public function replaceKnowledge(array $items): array
    {
        return $this->request('PUT', '/api/v1/knowledge', ['items' => $items]);
    }

    /** @return array<string,mixed> */
    public function deleteKnowledge(string $id): array
    {
        return $this->request('DELETE', '/api/v1/knowledge/' . rawurlencode($id));
    }

    /**
     * `PUT /api/v1/busy-times` (availability:write) — inside from–to your busy times
     * become exactly `slots`; the agent does not book into them.
     *
     * @param array<string,mixed> $busy `{ from, to, resource_id?, slots: [{starts_at, ends_at, reason?}] }`
     *
     * @return array<string,mixed>
     */
    public function setBusyTimes(array $busy): array
    {
        return $this->request('PUT', '/api/v1/busy-times', $busy);
    }

    /**
     * @param array<string,scalar> $query `from`, `to`
     *
     * @return array<string,mixed>
     */
    public function busyTimes(array $query = []): array
    {
        return $this->request('GET', '/api/v1/busy-times', null, $query);
    }

    // ─── Shipping price (orders:write) ──────────────────────────────────────

    /**
     * `POST /api/v1/shipping-quotes` — sets the shipping price (and optionally the
     * courier) for the order the agent is about to place in a conversation. A new
     * quote replaces the previous one.
     *
     *     $hv->createShippingQuote([
     *         'conversation_id' => $ctx->conversationId,
     *         'amount' => 5.90,
     *         'currency' => 'EUR',
     *         'label' => 'Speedy to an office',
     *         'courier' => 'speedy',
     *         'courier_service' => 'office',
     *     ]);
     *
     * @param array<string,mixed> $quote
     *
     * @return array<string,mixed> `{ id, status: "pending", expires_at }`
     */
    public function createShippingQuote(array $quote): array
    {
        return $this->request('POST', '/api/v1/shipping-quotes', $quote);
    }

    /** @return array<string,mixed> */
    public function shippingQuote(string $id): array
    {
        return $this->request('GET', '/api/v1/shipping-quotes/' . rawurlencode($id));
    }

    /**
     * Cancels a pending shipping quote; the order then uses the market's price.
     *
     * @return array<string,mixed>
     */
    public function cancelShippingQuote(string $id): array
    {
        return $this->request('DELETE', '/api/v1/shipping-quotes/' . rawurlencode($id));
    }

    // ─── Helpers ────────────────────────────────────────────────────────────

    private static function normalizePath(string $path): string
    {
        if (preg_match('#^https?://#i', $path)) {
            throw new \InvalidArgumentException('Pass a path such as /api/v1/orders, not a full URL.');
        }
        $path = '/' . ltrim($path, '/');
        if (!str_starts_with($path, '/api/')) {
            $path = '/api/v1' . $path;
        }

        return $path;
    }

    /** @param array<string,mixed> $query */
    private static function queryString(array $query): string
    {
        $clean = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (\is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $clean[$key] = $value;
        }
        if ($clean === []) {
            return '';
        }

        return '?' . http_build_query($clean, '', '&', PHP_QUERY_RFC3986);
    }
}
