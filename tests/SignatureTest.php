<?php

declare(strict_types=1);

namespace HelpVox\Tests;

use HelpVox\Signature;
use PHPUnit\Framework\TestCase;

final class SignatureTest extends TestCase
{
    private const SECRET = 'hvs_test_secret';
    private const NOW = 1760000000;

    public function testRoundTrip(): void
    {
        $body = '{"hello":"world"}';
        $header = Signature::sign(self::SECRET, $body, self::NOW);
        self::assertMatchesRegularExpression('/^t=1760000000,v1=[0-9a-f]{64}$/', $header);
        self::assertTrue(Signature::verify(self::SECRET, $body, $header, self::NOW));
    }

    /** Vectors produced by src/lib/apps/signing.ts (Node). */
    public function testMatchesServerVectors(): void
    {
        $body = '{"id":"evt_1","type":"order.created"}';
        $expected = 't=1760000000,v1=4e2bd7f82e7ae747510d46fba3efecbfe75682e915437b25e135354ae42e2cd9';
        self::assertSame($expected, Signature::sign(self::SECRET, $body, self::NOW));
        self::assertTrue(Signature::verify(self::SECRET, $body, $expected, self::NOW));

        $utf8 = '{"tool":"x","input":{"name":"Иван"}}';
        $header = 't=1760000000,v1=23aa0ba1ee8e83343a88fce53ff04d31d417dc4a67fc388ef2a8de68dc608639';
        self::assertTrue(Signature::verify(self::SECRET, $utf8, $header, self::NOW));
    }

    public function testTamperedBodyFails(): void
    {
        $header = Signature::sign(self::SECRET, '{"amount":10}', self::NOW);
        self::assertFalse(Signature::verify(self::SECRET, '{"amount":99}', $header, self::NOW));
    }

    public function testWrongSecretFails(): void
    {
        $header = Signature::sign(self::SECRET, 'x', self::NOW);
        self::assertFalse(Signature::verify('hvs_other', 'x', $header, self::NOW));
    }

    public function testStaleAndFutureTimestampsFail(): void
    {
        $header = Signature::sign(self::SECRET, 'x', self::NOW);
        self::assertTrue(Signature::verify(self::SECRET, 'x', $header, self::NOW + 300));
        self::assertFalse(Signature::verify(self::SECRET, 'x', $header, self::NOW + 301));
        self::assertFalse(Signature::verify(self::SECRET, 'x', $header, self::NOW - 301));
    }

    public function testMalformedHeadersFail(): void
    {
        self::assertFalse(Signature::verify(self::SECRET, 'x', null, self::NOW));
        self::assertFalse(Signature::verify(self::SECRET, 'x', '', self::NOW));
        self::assertFalse(Signature::verify(self::SECRET, 'x', 'garbage', self::NOW));
        self::assertFalse(Signature::verify(self::SECRET, 'x', 't=abc,v1=00', self::NOW));
        self::assertFalse(Signature::verify(self::SECRET, 'x', 't=' . self::NOW, self::NOW));
    }

    public function testToleratesSpacesAndOrder(): void
    {
        $header = Signature::sign(self::SECRET, 'x', self::NOW);
        [$t, $v1] = explode(',', $header);
        self::assertTrue(Signature::verify(self::SECRET, 'x', $v1 . ', ' . $t, self::NOW));
    }
}
