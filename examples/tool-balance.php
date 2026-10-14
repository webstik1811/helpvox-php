<?php

/**
 * Agent tool endpoint — the `url` of the `get_balance` tool in the manifest:
 *
 *     {
 *       "name": "get_balance",
 *       "description": "Looks up the customer's loyalty points by phone number. Use when they ask about points or bonuses.",
 *       "input_schema": {
 *         "type": "object",
 *         "properties": { "phone": { "type": "string", "description": "Customer phone in international format" } },
 *         "required": ["phone"]
 *       },
 *       "url": "https://app.example.com/tool-balance.php"
 *     }
 *
 * Phone tools have 1.5 s by default: answer from your own database, not from slow APIs.
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use HelpVox\Exception\ToolError;
use HelpVox\Tool;
use HelpVox\ToolContext;

Tool::handle((string) getenv('HELPVOX_CLIENT_SECRET'), function (array $input, ToolContext $ctx): string {
    $phone = trim((string) ($input['phone'] ?? $ctx->callerPhone ?? ''));
    if ($phone === '') {
        throw new ToolError('I need the phone number the account is registered with.');
    }

    $pdo = new PDO((string) getenv('DB_DSN'), (string) getenv('DB_USER'), (string) getenv('DB_PASS'));
    $stmt = $pdo->prepare('SELECT points FROM loyalty_accounts WHERE installation_id = ? AND phone = ?');
    $stmt->execute([$ctx->installationId, $phone]);
    $points = $stmt->fetchColumn();

    if ($points === false) {
        throw new ToolError('There is no loyalty account for this phone number.');
    }

    return $ctx->language === 'bg'
        ? "Клиентът има {$points} точки. 100 точки са 1 евро отстъпка."
        : "The customer has {$points} points. 100 points are worth 1 EUR off.";
});
