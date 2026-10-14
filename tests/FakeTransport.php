<?php

declare(strict_types=1);

namespace HelpVox\Tests;

/** Records requests and answers with canned responses. */
final class FakeTransport
{
    /** @var list<array{method:string, url:string, headers:list<string>, body:?string}> */
    public array $requests = [];

    /** @param list<array{status:int, body:string}> $responses */
    public function __construct(private array $responses)
    {
    }

    /** @param list<string> $headers */
    public function __invoke(string $method, string $url, array $headers, ?string $body): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        return array_shift($this->responses) ?? ['status' => 500, 'body' => ''];
    }

    public function last(): array
    {
        return $this->requests[\count($this->requests) - 1];
    }

    public function header(string $name): ?string
    {
        foreach ($this->last()['headers'] as $line) {
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            if (strcasecmp($k, $name) === 0) {
                return $v;
            }
        }

        return null;
    }
}
