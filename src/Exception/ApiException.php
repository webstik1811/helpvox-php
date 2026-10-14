<?php

declare(strict_types=1);

namespace HelpVox\Exception;

/**
 * A non-2xx answer from the HelpVox API.
 *
 * REST errors use `{"error":{"code","message","scope"?}}`; the OAuth token endpoint
 * uses `{"error":"…","error_description":"…"}`. Both are mapped here.
 */
class ApiException extends HelpVoxException
{
    /**
     * @param array<string,mixed>|null $body decoded response body, when it was JSON
     */
    public function __construct(
        string $message,
        private readonly int $status,
        private readonly string $errorCode,
        private readonly ?string $requiredScope = null,
        private readonly ?array $body = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /** HTTP status (0 when the request never got an answer). */
    public function getStatus(): int
    {
        return $this->status;
    }

    /** Machine-readable code, e.g. `insufficient_scope`, `not_found`, `invalid_grant`. */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** The scope the endpoint needs, on `403 insufficient_scope`. */
    public function getRequiredScope(): ?string
    {
        return $this->requiredScope;
    }

    /** @return array<string,mixed>|null */
    public function getBody(): ?array
    {
        return $this->body;
    }
}
