<?php

/**
 * Webhook endpoint — the manifest's `webhooks.url`.
 * Answer 2xx within 10 seconds; anything else is retried with the same event id.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use HelpVox\Exception\SignatureException;
use HelpVox\Webhook;

try {
    $event = Webhook::parse((string) getenv('HELPVOX_CLIENT_SECRET'));
} catch (SignatureException $e) {
    http_response_code(401);
    exit;
}

$pdo = new PDO((string) getenv('DB_DSN'), (string) getenv('DB_USER'), (string) getenv('DB_PASS'));

// Deduplicate: retries carry the same id.
$seen = $pdo->prepare('INSERT IGNORE INTO helpvox_events (id, type, received_at) VALUES (?, ?, NOW())');
$seen->execute([$event->id, $event->type]);
if ($seen->rowCount() === 0) {
    http_response_code(200);
    exit;
}

switch ($event->type) {
    case 'order.status_changed':
        if (($event->data['status'] ?? null) === 'delivered') {
            // e.g. award loyalty points for $event->data['order_id']
        }
        break;

    case 'customer.redact':
        // Delete what you hold about this customer.
        $del = $pdo->prepare('DELETE FROM loyalty_accounts WHERE installation_id = ? AND (phone = ? OR email = ?)');
        $del->execute([$event->installationId, $event->data['phone'] ?? '', $event->data['email'] ?? '']);
        break;

    case 'app.uninstalled':
        $del = $pdo->prepare('DELETE FROM helpvox_installations WHERE installation_id = ?');
        $del->execute([$event->installationId]);
        break;
}

http_response_code(200);
