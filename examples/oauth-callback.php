<?php

/**
 * OAuth redirect URL — list it in the manifest's `redirect_urls`.
 *
 * Start the install from your own page with:
 *     session_start();
 *     $_SESSION['helpvox_state'] = bin2hex(random_bytes(16));
 *     header('Location: ' . HelpVox\OAuth::installUrl('loyalty-points', 'https://app.example.com/oauth-callback.php', $_SESSION['helpvox_state']));
 *
 * HelpVox sends the merchant back here with ?code=hvic_…&installation_id=…&state=…
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use HelpVox\Client;
use HelpVox\Exception\ApiException;
use HelpVox\OAuth;

session_start();

$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
if (!is_string($code) || $code === '' || !is_string($state) || !hash_equals($_SESSION['helpvox_state'] ?? '', $state)) {
    http_response_code(400);
    exit('Invalid install request.');
}
unset($_SESSION['helpvox_state']);

try {
    // The code works once — do not retry this call automatically.
    $token = OAuth::exchangeCode((string) getenv('HELPVOX_CLIENT_ID'), (string) getenv('HELPVOX_CLIENT_SECRET'), $code);
} catch (ApiException $e) {
    http_response_code(502);
    exit('Could not finish the installation: ' . htmlspecialchars($e->getMessage()));
}

// Store the token against the installation. One row per installation_id;
// installing again rotates the token, so overwrite it.
$pdo = new PDO((string) getenv('DB_DSN'), (string) getenv('DB_USER'), (string) getenv('DB_PASS'));
$stmt = $pdo->prepare(
    'INSERT INTO helpvox_installations (installation_id, vendor_id, access_token, scope)
     VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE vendor_id = VALUES(vendor_id), access_token = VALUES(access_token), scope = VALUES(scope)'
);
$stmt->execute([$token['installation_id'], $token['vendor_id'], $token['access_token'], $token['scope']]);

// Your own setup for this shop goes here. Then switch the agent tools on.
$hv = new Client($token['access_token']);
$hv->ready();

$app = $hv->app();
echo 'Installed for ' . htmlspecialchars((string) ($app['vendor']['name'] ?? 'your shop')) . '. You can close this tab.';
