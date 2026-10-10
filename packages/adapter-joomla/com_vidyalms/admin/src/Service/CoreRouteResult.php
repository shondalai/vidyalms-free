<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Service;

defined('_JEXEC') or die;

use Shondalai\VidyaLMS\Core\Http\ApiResponse;

/**
 * The answer a server-rendered page reads back from CoreRoute.
 *
 * A page never inspects a status code by number. It asks whether the call
 * succeeded, and otherwise renders one of four states: the route is not there
 * on this build, the caller may not have it, the caller asked too often, or
 * something else went wrong. That keeps the page correct on a build where the
 * route has not shipped yet and on one where it has.
 *
 * @since 1.0.0
 */
final class CoreRouteResult
{
    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    private function __construct(
        public readonly int $status,
        public readonly array $data,
        public readonly array $headers = [],
    ) {
    }

    public static function fromResponse(ApiResponse $response): self
    {
        return new self($response->status, $response->data ?? [], $response->headers);
    }

    /**
     * A kernel this site could not build, or a throwable that escaped it. It
     * is deliberately not a 404: the page says the service is unavailable
     * rather than claiming the visitor's token was wrong.
     */
    public static function unavailable(): self
    {
        return new self(503, []);
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * One response header by name, case insensitively, or the empty string.
     *
     * A server rendered page reads this to carry a control the core middleware
     * set onto the host response it is about to write. X-Robots-Tag is the
     * case that matters: the verification page discloses a person, the header
     * is set once in core middleware rather than in each adapter, and it has
     * to reach the crawler whichever transport served the page.
     */
    public function header(string $name): string
    {
        $wanted = strtolower($name);

        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === $wanted) {
                return $value;
            }
        }

        return '';
    }

    /**
     * The route is not registered on this build, or the site could not build a
     * kernel. A page renders the same "not available" state it renders for a
     * site whose administrator has switched notifications off, rather than an
     * error a visitor can do nothing about.
     */
    public function routeMissing(): bool
    {
        return $this->status === 404 || $this->status === 501 || $this->status === 503;
    }

    public function unauthorized(): bool
    {
        return $this->status === 401 || $this->status === 403;
    }

    public function rateLimited(): bool
    {
        return $this->status === 429;
    }

    /**
     * The problem detail, when the kernel answered application/problem+json.
     */
    public function detail(): string
    {
        $detail = $this->data['detail'] ?? $this->data['title'] ?? '';

        return \is_string($detail) ? $detail : '';
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->data[$key] ?? null;

        return \is_string($value) ? $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->data[$key] ?? null;

        return \is_bool($value) ? $value : $default;
    }

    /**
     * @return list<string>
     */
    public function strings(string $key): array
    {
        $value = $this->data[$key] ?? null;

        if (!\is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (\is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(string $key): array
    {
        $value = $this->data[$key] ?? null;

        if (!\is_array($value)) {
            return [];
        }

        $rows = [];

        foreach ($value as $item) {
            if (\is_array($item)) {
                $rows[] = $item;
            }
        }

        return $rows;
    }
}
