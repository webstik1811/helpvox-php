<?php

declare(strict_types=1);

namespace HelpVox\Tests;

use HelpVox\Client;
use HelpVox\Exception\ApiException;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    public function testSendsAuthAndDecodes(): void
    {
        $fake = new FakeTransport([['status' => 200, 'body' => '{"installation_id":"inst-1","ready":false}']]);
        $hv = new Client('hva_token', 'https://example.test/', $fake);

        $app = $hv->app();

        self::assertSame('inst-1', $app['installation_id']);
        self::assertSame('GET', $fake->last()['method']);
        self::assertSame('https://example.test/api/v1/app', $fake->last()['url']);
        self::assertSame('Bearer hva_token', $fake->header('Authorization'));
        self::assertSame('helpvox-php/' . Client::VERSION, $fake->header('User-Agent'));
        self::assertNull($fake->last()['body']);
    }

    public function testQueryAndBodies(): void
    {
        $fake = new FakeTransport(array_fill(0, 6, ['status' => 200, 'body' => '{}']));
        $hv = new Client('hv_live_key', 'https://example.test', $fake);

        $hv->orders(['status' => 'new', 'limit' => 10, 'skip' => null, 'paid' => true]);
        self::assertSame('https://example.test/api/v1/orders?status=new&limit=10&paid=true', $fake->last()['url']);

        $hv->updateOrder('a/b', ['note' => 'Позвънете преди доставка']);
        self::assertSame('PATCH', $fake->last()['method']);
        self::assertSame('https://example.test/api/v1/orders/a%2Fb', $fake->last()['url']);
        self::assertSame('{"note":"Позвънете преди доставка"}', $fake->last()['body']);
        self::assertSame('application/json', $fake->header('Content-Type'));

        $hv->ready();
        self::assertSame('POST', $fake->last()['method']);
        self::assertSame('{}', $fake->last()['body']);

        $hv->sendTestEvent('order.created', []);
        self::assertSame('{"event":"order.created","data":{}}', $fake->last()['body']);

        $hv->cancelDiscount('d1');
        self::assertSame('DELETE', $fake->last()['method']);
        self::assertSame('https://example.test/api/v1/discounts/d1', $fake->last()['url']);

        $hv->request('GET', 'calls');
        self::assertSame('https://example.test/api/v1/calls', $fake->last()['url']);
    }

    public function testInsufficientScope(): void
    {
        $fake = new FakeTransport([[
            'status' => 403,
            'body' => '{"error":{"code":"insufficient_scope","message":"This endpoint needs the \"orders:read\" scope.","scope":"orders:read"}}',
        ]]);
        $hv = new Client('hva_token', 'https://example.test', $fake);

        try {
            $hv->orders();
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(403, $e->getStatus());
            self::assertSame('insufficient_scope', $e->getErrorCode());
            self::assertSame('orders:read', $e->getRequiredScope());
            self::assertStringContainsString('orders:read', $e->getMessage());
        }
    }

    public function testNotSubscribedAndNonJsonErrors(): void
    {
        $fake = new FakeTransport([
            ['status' => 409, 'body' => '{"error":{"code":"not_subscribed","message":"nope"}}'],
            ['status' => 502, 'body' => '<html>Bad gateway</html>'],
        ]);
        $hv = new Client('hva_token', 'https://example.test', $fake);

        try {
            $hv->sendTestEvent('order.created');
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('not_subscribed', $e->getErrorCode());
            self::assertNull($e->getRequiredScope());
        }

        try {
            $hv->calls();
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame(502, $e->getStatus());
            self::assertSame('http_502', $e->getErrorCode());
        }
    }

    public function testEmptySuccessBody(): void
    {
        $fake = new FakeTransport([['status' => 204, 'body' => '']]);
        $hv = new Client('hva_token', 'https://example.test', $fake);
        self::assertSame([], $hv->cancelDiscount('d1'));
    }
}
