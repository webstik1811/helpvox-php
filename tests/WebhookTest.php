<?php

declare(strict_types=1);

namespace HelpVox\Tests;

use HelpVox\Exception\SignatureException;
use HelpVox\Signature;
use HelpVox\Webhook;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const SECRET = 'hvs_test_secret';

    private static function body(array $data = ['order_id' => 'o1', 'status' => 'delivered']): string
    {
        return json_encode([
            'id' => 'evt_123',
            'type' => 'order.status_changed',
            'created_at' => '2026-10-14T10:00:00.000Z',
            'vendor_id' => 'ven-1',
            'installation_id' => 'inst-1',
            'data' => $data,
        ], JSON_THROW_ON_ERROR);
    }

    public function testParsesSignedEvent(): void
    {
        $body = self::body();
        $event = Webhook::parse(self::SECRET, $body, [
            'helpvox-signature' => Signature::sign(self::SECRET, $body),
            'HelpVox-Event' => 'order.status_changed',
        ]);

        self::assertSame('evt_123', $event->id);
        self::assertSame('order.status_changed', $event->type);
        self::assertSame('2026-10-14T10:00:00.000Z', $event->createdAt);
        self::assertSame('ven-1', $event->vendorId);
        self::assertSame('inst-1', $event->installationId);
        self::assertSame('delivered', $event->data['status']);
        self::assertFalse($event->isTest());
        self::assertSame('2026-10-14', $event->createdAtTime()?->format('Y-m-d'));
    }

    public function testHeaderLookupIsCaseInsensitiveAndAcceptsLists(): void
    {
        $body = self::body(['test' => true]);
        $sig = Signature::sign(self::SECRET, $body);

        foreach ([['HELPVOX-SIGNATURE' => $sig], ['HelpVox-Signature' => [$sig]], ['helpvox_signature' => $sig]] as $headers) {
            $event = Webhook::parse(self::SECRET, $body, $headers);
            self::assertTrue($event->isTest());
        }
    }

    public function testRejectsBadSignature(): void
    {
        $body = self::body();
        $this->expectException(SignatureException::class);
        Webhook::parse(self::SECRET, $body, ['HelpVox-Signature' => Signature::sign('hvs_wrong', $body)]);
    }

    public function testRejectsMissingSignature(): void
    {
        $this->expectException(SignatureException::class);
        Webhook::parse(self::SECRET, self::body(), []);
    }

    public function testRejectsStaleSignature(): void
    {
        $body = self::body();
        $this->expectException(SignatureException::class);
        Webhook::parse(self::SECRET, $body, ['HelpVox-Signature' => Signature::sign(self::SECRET, $body, time() - 600)]);
    }

    public function testRejectsSignedNonEvent(): void
    {
        $this->expectException(SignatureException::class);
        Webhook::parse(self::SECRET, '[]', ['HelpVox-Signature' => Signature::sign(self::SECRET, '[]')]);
    }
}
