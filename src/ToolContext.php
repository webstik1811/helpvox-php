<?php

declare(strict_types=1);

namespace HelpVox;

/**
 * The `context` of an agent tool call (contract section 8).
 */
final class ToolContext
{
    /** @param array<string,mixed> $raw */
    public function __construct(
        /** The tool's name from the manifest, e.g. `get_balance`. */
        public readonly string $tool,
        public readonly string $installationId,
        public readonly string $vendorId,
        /** `phone`, `chat` or `test`. */
        public readonly string $channel,
        public readonly ?string $conversationId,
        public readonly ?string $language,
        public readonly ?string $country,
        /** Only with customers:read, on the phone channel, when the number is known. */
        public readonly ?string $callerPhone,
        public readonly array $raw = [],
    ) {
    }

    /** @param array<string,mixed> $context */
    public static function fromArray(string $tool, array $context): self
    {
        $str = static fn (string $k): ?string => isset($context[$k]) && \is_scalar($context[$k]) && $context[$k] !== ''
            ? (string) $context[$k]
            : null;

        return new self(
            tool: $tool,
            installationId: $str('installation_id') ?? '',
            vendorId: $str('vendor_id') ?? '',
            channel: $str('channel') ?? '',
            conversationId: $str('conversation_id'),
            language: $str('language'),
            country: $str('country'),
            callerPhone: $str('caller_phone'),
            raw: $context,
        );
    }

    public function isTest(): bool
    {
        return $this->channel === 'test';
    }
}
