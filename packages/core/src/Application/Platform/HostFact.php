<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

/**
 * One host fact the Settings and Dashboard screens state, and nothing else
 * reads: the expiry sweep's schedule label, the host's own settings page.
 *
 * A host that already knows the answer hands over the string. A host that has
 * to look it up (Joomla's scheduler owns the cadence of the expiry task, and
 * only the site owner knows what they set) hands over the lookup instead, so
 * a request that never reaches those two routes never pays for it. The answer
 * is resolved at most once per kernel.
 *
 * An empty string is not a fact, and neither is a lookup that throws: both
 * answer null, which is what the screens render as "the host reports no
 * schedule". A boot fact must never be able to fail a request.
 */
final class HostFact
{
    /** @var \Closure(): ?string */
    private readonly \Closure $resolve;

    private bool $resolved = false;

    private ?string $value = null;

    /**
     * @param \Closure(): ?string $resolve
     */
    private function __construct(\Closure $resolve)
    {
        $this->resolve = $resolve;
    }

    public static function none(): self
    {
        return new self(static fn (): ?string => null);
    }

    public static function text(?string $value): self
    {
        return new self(static fn (): ?string => $value);
    }

    /**
     * @param \Closure(): ?string $resolve
     */
    public static function lazy(\Closure $resolve): self
    {
        return new self($resolve);
    }

    /**
     * Whatever a host put in the kernel's options array: a string, a closure
     * answering one, or anything else, which is no fact at all.
     */
    public static function fromOption(mixed $option): self
    {
        if (\is_string($option)) {
            return self::text($option);
        }

        if ($option instanceof \Closure) {
            /** @var \Closure(): ?string $option */
            return self::lazy($option);
        }

        return self::none();
    }

    public function value(): ?string
    {
        if (!$this->resolved) {
            $this->resolved = true;

            try {
                $resolved = ($this->resolve)();
            } catch (\Throwable) {
                $resolved = null;
            }

            $this->value = $resolved === null || $resolved === '' ? null : $resolved;
        }

        return $this->value;
    }
}
