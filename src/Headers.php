<?php

declare(strict_types=1);

namespace HelpVox;

/**
 * Reads request headers case-insensitively, from an explicit array or from the
 * current PHP request (getallheaders(), falling back to $_SERVER on hosts where
 * that function is missing).
 *
 * @internal
 */
final class Headers
{
    /** @var array<string,string> lower-case name => value */
    private array $values = [];

    /**
     * @param array<string,string|list<string>>|null $headers name => value (or list of values, as PSR-7 and WordPress give them)
     */
    public function __construct(?array $headers = null)
    {
        $headers ??= self::fromRequest();
        foreach ($headers as $name => $value) {
            if (\is_array($value)) {
                $value = $value === [] ? '' : (string) reset($value);
            }
            // WordPress normalises "HelpVox-Signature" to "helpvox_signature".
            $key = strtolower(str_replace('_', '-', (string) $name));
            $this->values[$key] = (string) $value;
        }
    }

    public function get(string $name): ?string
    {
        return $this->values[strtolower($name)] ?? null;
    }

    /** @return array<string,string> */
    private static function fromRequest(): array
    {
        if (\function_exists('getallheaders')) {
            $all = getallheaders();
            if (\is_array($all) && $all !== []) {
                return $all;
            }
        }
        $out = [];
        foreach ($_SERVER as $key => $value) {
            if (\is_string($key) && str_starts_with($key, 'HTTP_')) {
                $out[substr($key, 5)] = (string) $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    public static function rawBody(): string
    {
        $body = file_get_contents('php://input');

        return $body === false ? '' : $body;
    }
}
