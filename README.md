# HelpVox PHP SDK

Official PHP SDK for the [HelpVox](https://www.helpvox.ai) app platform. It covers the whole
contract an app needs: the OAuth install, signed events, agent tools, the dashboard page token and
the REST API.

- PHP 8.1+, **no runtime dependencies** (only `ext-curl` and `ext-json`) — works on shared hosting.
- Signatures are checked against the raw body with a constant-time compare.

```bash
composer require helpvox/sdk
```

Without Composer, copy `src/` and register a PSR-4 autoloader for `HelpVox\` → `src/`.

Your app has a `client_id` (`hvapp_…`) and a `client_secret` (`hvs_…`). The secret signs **everything**
HelpVox sends you — events, tool calls and dashboard tokens — so keep it in an environment variable,
never in the code. The examples read it from `HELPVOX_CLIENT_SECRET`.

## 1. Install flow (OAuth)

Send the merchant to the consent screen:

```php
session_start();
$_SESSION['helpvox_state'] = bin2hex(random_bytes(16));

header('Location: ' . HelpVox\OAuth::installUrl(
    'loyalty-points',                              // your manifest slug
    'https://app.example.com/oauth-callback.php',  // one of the manifest's redirect_urls
    $_SESSION['helpvox_state'],
));
```

After **Install**, HelpVox redirects to your callback with `?code=hvic_…&installation_id=…&state=…`:

```php
if (!hash_equals($_SESSION['helpvox_state'] ?? '', (string) ($_GET['state'] ?? ''))) {
    http_response_code(400);
    exit;
}

$token = HelpVox\OAuth::exchangeCode(
    getenv('HELPVOX_CLIENT_ID'),
    getenv('HELPVOX_CLIENT_SECRET'),
    $_GET['code'],
);
// ['access_token' => 'hva_…', 'token_type' => 'bearer', 'scope' => 'orders:read agent:tools',
//  'installation_id' => '…', 'vendor_id' => '…']

save_installation($token['installation_id'], $token['access_token']);   // overwrite on reinstall

// Agent tools stay OFF until you call ready() — do it once your own setup is done.
(new HelpVox\Client($token['access_token']))->ready();
```

The code lives 10 minutes and works **once**; using it twice revokes the installation, so do not
retry `exchangeCode()` automatically. The access token has no expiry — it dies on uninstall.

## 2. Receiving events

Point `webhooks.url` in the manifest at a script like this:

```php
use HelpVox\Exception\SignatureException;
use HelpVox\Webhook;

try {
    $event = Webhook::parse(getenv('HELPVOX_CLIENT_SECRET'));   // reads php://input and the headers
} catch (SignatureException $e) {
    http_response_code(401);
    exit;
}

if (already_processed($event->id)) {        // retries reuse the same id
    exit;
}

match ($event->type) {
    'order.status_changed' => on_status($event->data['order_id'], $event->data['status']),
    'customer.redact'      => forget_customer($event->data['phone'] ?? null, $event->data['email'] ?? null),
    'app.uninstalled'      => delete_installation($event->installationId),
    default                => null,
};
```

`$event` has `id`, `type`, `createdAt`, `vendorId`, `installationId`, `data` and `isTest()` (true for
events sent with `sendTestEvent()`). Answer `2xx` within 10 seconds; anything else is retried
(1 min … 12 h). Events carry IDs and status, not customer details — fetch more through the API.

## 3. An agent tool

Declare the tool in the manifest (`agent_tools[].url`) and answer it with `Tool::handle()`:

```php
use HelpVox\Exception\ToolError;
use HelpVox\Tool;
use HelpVox\ToolContext;

Tool::handle(getenv('HELPVOX_CLIENT_SECRET'), function (array $input, ToolContext $ctx): string {
    $points = find_points($ctx->installationId, $input['phone']);
    if ($points === null) {
        throw new ToolError('There is no loyalty account for this phone number.');
    }
    return "The customer has {$points} points.";
});
```

- Return plain text — it is what the agent relays (cut to 2000 characters).
- Throw `ToolError` for an expected failure; its message is what the agent tells the customer.
- Any other exception becomes a generic error (details go to `error_log`, never to the customer).
- A bad signature gets `401`; everything else is `200` JSON, as the contract requires.
- `$ctx` has `tool`, `installationId`, `vendorId`, `channel` (`phone`/`chat`/`test`), `conversationId`,
  `language`, `country` and `callerPhone` (only with `customers:read`, on the phone, when known).
- Be fast: phone tools have **1.5 s** unless the manifest marks them `slow` (up to 8 s).

### WordPress / WooCommerce

In a plugin, register a REST route and call the pure `Tool::process()`, which returns
`[status, body]` instead of printing:

```php
add_action('rest_api_init', function () {
    register_rest_route('my-loyalty/v1', '/tools/balance', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',   // authentication is the HelpVox signature
        'callback'            => function (WP_REST_Request $request) {
            [$status, $body] = HelpVox\Tool::process(
                (string) get_option('my_loyalty_helpvox_secret'),
                function (array $input, HelpVox\ToolContext $ctx): string {
                    $user = get_user_by('email', $input['email'] ?? '');
                    if (!$user) {
                        throw new HelpVox\Exception\ToolError('No customer with this email.');
                    }
                    return sprintf('The customer has %d points.', (int) get_user_meta($user->ID, 'points', true));
                },
                $request->get_body(),       // the raw body — required for the signature
                $request->get_headers(),    // WordPress keys (helpvox_signature) are understood
            );
            return new WP_REST_Response($body, $status);
        },
    ]);
});
```

The tool URL is then `https://shop.example.com/wp-json/my-loyalty/v1/tools/balance`. Webhooks work the
same way: `HelpVox\Webhook::parse($secret, $request->get_body(), $request->get_headers())`.

## 4. The dashboard page (iframe)

If the manifest has `ui.settings_page`, HelpVox embeds it as
`<settings_page>?installation_id=<uuid>&session=<JWT>`. The token lives 60 seconds — verify it and
start your own session:

```php
$claims = HelpVox\SessionToken::verify(
    getenv('HELPVOX_CLIENT_SECRET'),
    (string) ($_GET['session'] ?? ''),
    getenv('HELPVOX_CLIENT_ID'),          // the token's audience
);
if ($claims === null) {
    http_response_code(401);
    exit('Open this page from the HelpVox dashboard.');
}

session_start();
$_SESSION['installation_id'] = $claims['installation_id'];
$_SESSION['helpvox_user'] = $claims['sub'];
// $claims also has vendor_id, lang, iss, iat, exp
```

Inside an iframe your session cookie needs `SameSite=None; Secure`
(`session_set_cookie_params(['samesite' => 'None', 'secure' => true])` before `session_start()`).

## 5. Calling the API

```php
$hv = new HelpVox\Client($accessToken);   // hva_… installation token, or a hv_live_… API key

$hv->app();                                  // installation, vendor, scopes, ready, capabilities
$hv->orders(['status' => 'new']);
$hv->order($orderId);
$hv->updateOrder($orderId, ['note' => 'Call before delivery']);
$hv->products();
$hv->calls();
$hv->call($callId);
$hv->callbacks();
$hv->appointments();
$hv->sendTestEvent('order.created');         // sample event to your own webhook

// Order discounts (orders:write) — e.g. spend loyalty points inside a tool call:
$discount = $hv->createDiscount([
    'conversation_id' => $ctx->conversationId,
    'amount'          => 12.00,
    'currency'        => 'EUR',
    'label'           => 'Loyalty points',
    'reference'       => 'redeem-42',
]);
$hv->discount($discount['id']);
$hv->cancelDiscount($discount['id']);

// Anything else under /api/v1:
$hv->request('GET', '/api/v1/knowledge');
```

Errors throw `HelpVox\Exception\ApiException`:

```php
try {
    $hv->orders();
} catch (HelpVox\Exception\ApiException $e) {
    $e->getStatus();          // 403
    $e->getErrorCode();       // 'insufficient_scope'
    $e->getRequiredScope();   // 'orders:read'
    $e->getMessage();
}
```

Requests time out after 30 seconds. Without `customers:read`, customer fields are **absent** from
responses (not null) — use `$order['customer_phone'] ?? null`-style access.

## Testing your app

`Signature::sign()` and `SessionToken::sign()` produce exactly what HelpVox sends, so you can test
your endpoints without HelpVox:

```php
$body = json_encode(['tool' => 'get_balance', 'input' => ['phone' => '+359888123456'], 'context' => ['channel' => 'test']]);
[$status, $response] = HelpVox\Tool::process($secret, $handler, $body, [
    'HelpVox-Signature' => HelpVox\Signature::sign($secret, $body),
]);
```

`Client` and `OAuth::exchangeCode()` accept a `$transport` callable
`fn(string $method, string $url, array $headers, ?string $body): array{status:int, body:string}`
to replace HTTP in tests.

Run the SDK's own tests with `composer install && vendor/bin/phpunit`.

## License

MIT
