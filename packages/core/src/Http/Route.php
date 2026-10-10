<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http;

/**
 * One route of the /v1 contract: a method, a pattern with {param} segments,
 * and the handler that serves it.
 */
final class Route
{
    /** @var list<string> */
    private readonly array $segments;

    public function __construct(
        public readonly string $method,
        public readonly string $pattern,
        public readonly HandlerInterface $handler,
    ) {
        $trimmed = trim($pattern, '/');
        $this->segments = $trimmed === '' ? [] : explode('/', $trimmed);
    }

    /**
     * Returns the extracted path parameters when the path matches, or null.
     *
     * @return array<string, string>|null
     */
    public function match(string $method, string $path): ?array
    {
        if (strtoupper($method) !== $this->method) {
            return null;
        }

        $trimmed = trim($path, '/');
        $parts = $trimmed === '' ? [] : explode('/', $trimmed);

        if (\count($parts) !== \count($this->segments)) {
            return null;
        }

        $params = [];

        foreach ($this->segments as $index => $segment) {
            $part = $parts[$index];

            if (str_starts_with($segment, '{') && str_ends_with($segment, '}')) {
                if ($part === '') {
                    return null;
                }

                $params[substr($segment, 1, -1)] = rawurldecode($part);
                continue;
            }

            if ($segment !== $part) {
                return null;
            }
        }

        return $params;
    }
}
