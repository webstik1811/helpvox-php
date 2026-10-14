<?php

declare(strict_types=1);

namespace HelpVox\Tests;

use HelpVox\SessionToken;
use PHPUnit\Framework\TestCase;

final class SessionTokenTest extends TestCase
{
    private const SECRET = 'hvs_test_secret';
    private const NOW = 1760000000;

    private const CLAIMS = [
        'iss' => 'https://www.helpvox.ai',
        'aud' => 'hvapp_abc',
        'sub' => 'user_1',
        'installation_id' => 'inst-1',
        'vendor_id' => 'ven-1',
        'lang' => 'bg',
    ];

    public function testRoundTrip(): void
    {
        $jwt = SessionToken::sign(self::SECRET, self::CLAIMS, self::NOW);
        $claims = SessionToken::verify(self::SECRET, $jwt, 'hvapp_abc', self::NOW + 10);

        self::assertNotNull($claims);
        self::assertSame('inst-1', $claims['installation_id']);
        self::assertSame(self::NOW + 60, $claims['exp']);
    }

    /** Token produced by signSessionToken() in src/lib/apps/signing.ts (Node). */
    public function testVerifiesServerToken(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJodHRwczovL3d3dy5oZWxwdm94LmFpIiwiYXVkIjoiaHZhcHBfYWJjIiwic3ViIjoidXNlcl8xIiwiaW5zdGFsbGF0aW9uX2lkIjoiaW5zdC0xIiwidmVuZG9yX2lkIjoidmVuLTEiLCJsYW5nIjoiYmciLCJpYXQiOjE3NjAwMDAwMDAsImV4cCI6MTc2MDAwMDA2MH0.k6xmyszx0vfQ88NwvzEG_p2WoXvFM1kO4DITpPVdvvc';
        $claims = SessionToken::verify(self::SECRET, $jwt, 'hvapp_abc', self::NOW);

        self::assertNotNull($claims);
        self::assertSame('user_1', $claims['sub']);
        self::assertSame($jwt, SessionToken::sign(self::SECRET, self::CLAIMS, self::NOW));
    }

    public function testWrongAudience(): void
    {
        $jwt = SessionToken::sign(self::SECRET, self::CLAIMS, self::NOW);
        self::assertNull(SessionToken::verify(self::SECRET, $jwt, 'hvapp_other', self::NOW));
    }

    public function testExpiryWithFiveSecondLeeway(): void
    {
        $jwt = SessionToken::sign(self::SECRET, self::CLAIMS, self::NOW);
        self::assertNotNull(SessionToken::verify(self::SECRET, $jwt, 'hvapp_abc', self::NOW + 65));
        self::assertNull(SessionToken::verify(self::SECRET, $jwt, 'hvapp_abc', self::NOW + 66));
    }

    public function testWrongSecretAndTampering(): void
    {
        $jwt = SessionToken::sign(self::SECRET, self::CLAIMS, self::NOW);
        self::assertNull(SessionToken::verify('hvs_other', $jwt, 'hvapp_abc', self::NOW));

        [$h, , $s] = explode('.', $jwt);
        $forged = rtrim(strtr(base64_encode(json_encode(self::CLAIMS + ['exp' => self::NOW + 9999])), '+/', '-_'), '=');
        self::assertNull(SessionToken::verify(self::SECRET, "$h.$forged.$s", 'hvapp_abc', self::NOW));
        self::assertNull(SessionToken::verify(self::SECRET, 'not-a-jwt', 'hvapp_abc', self::NOW));
    }

    public function testRejectsOtherAlgorithms(): void
    {
        $h = rtrim(strtr(base64_encode('{"alg":"none","typ":"JWT"}'), '+/', '-_'), '=');
        $p = rtrim(strtr(base64_encode(json_encode(self::CLAIMS + ['iat' => self::NOW, 'exp' => self::NOW + 60])), '+/', '-_'), '=');
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$p", self::SECRET, true)), '+/', '-_'), '=');
        self::assertNull(SessionToken::verify(self::SECRET, "$h.$p.$sig", 'hvapp_abc', self::NOW));
    }
}
