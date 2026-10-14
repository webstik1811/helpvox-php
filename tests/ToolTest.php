<?php

declare(strict_types=1);

namespace HelpVox\Tests;

use HelpVox\Exception\ToolError;
use HelpVox\Signature;
use HelpVox\Tool;
use HelpVox\ToolContext;
use PHPUnit\Framework\TestCase;

final class ToolTest extends TestCase
{
    private const SECRET = 'hvs_test_secret';

    private static function request(array $input = ['phone' => '+359888123456']): string
    {
        return json_encode([
            'tool' => 'get_balance',
            'input' => $input,
            'context' => [
                'installation_id' => 'inst-1',
                'vendor_id' => 'ven-1',
                'channel' => 'phone',
                'conversation_id' => 'conv_1',
                'language' => 'bg',
                'country' => 'BG',
                'caller_phone' => '+359888123456',
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private static function headers(string $body): array
    {
        return ['HelpVox-Signature' => Signature::sign(self::SECRET, $body), 'HelpVox-Installation' => 'inst-1'];
    }

    public function testSuccess(): void
    {
        $body = self::request();
        $seen = null;
        [$status, $out] = Tool::process(self::SECRET, function (array $input, ToolContext $ctx) use (&$seen): string {
            $seen = [$input, $ctx];

            return 'Клиентът има 120 точки.';
        }, $body, self::headers($body));

        self::assertSame(200, $status);
        self::assertSame(['result' => 'Клиентът има 120 точки.'], $out);
        self::assertSame('+359888123456', $seen[0]['phone']);
        self::assertSame('get_balance', $seen[1]->tool);
        self::assertSame('inst-1', $seen[1]->installationId);
        self::assertSame('ven-1', $seen[1]->vendorId);
        self::assertSame('phone', $seen[1]->channel);
        self::assertSame('conv_1', $seen[1]->conversationId);
        self::assertSame('bg', $seen[1]->language);
        self::assertSame('BG', $seen[1]->country);
        self::assertSame('+359888123456', $seen[1]->callerPhone);
        self::assertFalse($seen[1]->isTest());
    }

    public function testResultIsTruncatedTo2000Characters(): void
    {
        $body = self::request();
        [, $out] = Tool::process(self::SECRET, fn () => str_repeat('я', 2500), $body, self::headers($body));
        self::assertSame(2000, mb_strlen($out['result'], 'UTF-8'));
        self::assertTrue(mb_check_encoding($out['result'], 'UTF-8'));
    }

    public function testToolError(): void
    {
        $body = self::request();
        [$status, $out] = Tool::process(self::SECRET, function (): string {
            throw new ToolError('No loyalty account for this phone number.');
        }, $body, self::headers($body));

        self::assertSame(200, $status);
        self::assertSame(['error' => 'No loyalty account for this phone number.'], $out);
    }

    public function testUnexpectedExceptionDoesNotLeak(): void
    {
        $body = self::request();
        $log = ini_set('error_log', '/dev/null');
        [$status, $out] = Tool::process(self::SECRET, function (): string {
            throw new \RuntimeException('SQLSTATE[HY000] password=secret');
        }, $body, self::headers($body));
        ini_set('error_log', (string) $log);

        self::assertSame(200, $status);
        self::assertSame(['error' => Tool::GENERIC_ERROR], $out);
    }

    public function testBadSignatureIs401AndHandlerNotCalled(): void
    {
        $body = self::request();
        $called = false;
        [$status, $out] = Tool::process(self::SECRET, function () use (&$called): string {
            $called = true;

            return 'x';
        }, $body, ['HelpVox-Signature' => Signature::sign('hvs_wrong', $body)]);

        self::assertSame(401, $status);
        self::assertArrayHasKey('error', $out);
        self::assertFalse($called);
    }

    public function testMalformedSignedBody(): void
    {
        $body = '{"nope":true}';
        [$status, $out] = Tool::process(self::SECRET, fn () => 'x', $body, self::headers($body));
        self::assertSame(200, $status);
        self::assertArrayHasKey('error', $out);
    }

    public function testHandleWritesJson(): void
    {
        $body = self::request();
        ob_start();
        Tool::handle(self::SECRET, fn (array $input) => 'Balance for ' . $input['phone'], $body, self::headers($body));
        $printed = (string) ob_get_clean();

        self::assertSame(['result' => 'Balance for +359888123456'], json_decode($printed, true));
    }
}
