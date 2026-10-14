<?php

declare(strict_types=1);

namespace HelpVox\Tests;

use HelpVox\Exception\ApiException;
use HelpVox\OAuth;
use PHPUnit\Framework\TestCase;

final class OAuthTest extends TestCase
{
    public function testInstallUrl(): void
    {
        self::assertSame('https://www.helpvox.ai/apps/install/loyalty-points', OAuth::installUrl('loyalty-points'));
        self::assertSame(
            'https://example.test/apps/install/loyalty-points?redirect_url=https%3A%2F%2Fapp.example.com%2Foauth%2Fcallback&state=abc%20123',
            OAuth::installUrl('loyalty-points', 'https://app.example.com/oauth/callback', 'abc 123', 'https://example.test/'),
        );
    }

    public function testExchangeCode(): void
    {
        $fake = new FakeTransport([[
            'status' => 200,
            'body' => '{"access_token":"hva_x","token_type":"bearer","scope":"orders:read agent:tools","installation_id":"inst-1","vendor_id":"ven-1"}',
        ]]);

        $token = OAuth::exchangeCode('hvapp_1', 'hvs_secret', 'hvic_code', 'https://example.test', $fake);

        self::assertSame('hva_x', $token['access_token']);
        self::assertSame('inst-1', $token['installation_id']);
        self::assertSame('POST', $fake->last()['method']);
        self::assertSame('https://example.test/api/oauth/token', $fake->last()['url']);
        self::assertSame('application/x-www-form-urlencoded', $fake->header('Content-Type'));
        parse_str((string) $fake->last()['body'], $form);
        self::assertSame([
            'grant_type' => 'authorization_code',
            'code' => 'hvic_code',
            'client_id' => 'hvapp_1',
            'client_secret' => 'hvs_secret',
        ], $form);
    }

    public function testExchangeError(): void
    {
        $fake = new FakeTransport([[
            'status' => 400,
            'body' => '{"error":"invalid_grant","error_description":"The code has expired."}',
        ]]);

        try {
            OAuth::exchangeCode('hvapp_1', 'hvs_secret', 'hvic_old', 'https://example.test', $fake);
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(400, $e->getStatus());
            self::assertSame('invalid_grant', $e->getErrorCode());
            self::assertSame('The code has expired.', $e->getMessage());
        }
    }

    public function testExchangeWithoutToken(): void
    {
        $fake = new FakeTransport([['status' => 200, 'body' => '{}']]);
        $this->expectException(ApiException::class);
        OAuth::exchangeCode('hvapp_1', 'hvs_secret', 'hvic_code', 'https://example.test', $fake);
    }
}
