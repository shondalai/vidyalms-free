<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry;

/**
 * One extension point. The free build registers its default providers at
 * boot; Pro and third-party packages register more through the same method.
 * Nothing here knows which package a provider came from; the platform
 * records that separately so an uninstall can be reported to the admin.
 *
 * Two ways in, and the difference is the point:
 *
 * - register() adds a new key and throws on a duplicate. The strictness is a
 *   feature: it catches two packages that both believe they own a key.
 * - replace() overrides a key that already exists and returns the provider it
 *   displaced. Overriding is therefore always explicit, never a side effect of
 *   boot order. The return value is what makes an override a decoration rather
 *   than a reimplementation: the caller keeps the displaced provider and
 *   delegates to it for everything it does not change, so a Pro renderer can
 *   wrap the free one and a site policy can gate a free enrolment rule without
 *   knowing which class implemented it.
 *
 * Consumers hold the registry object and resolve providers when they run, so a
 * replacement installed after boot is seen by use cases already constructed.
 *
 * There is no unregister(), on purpose. Stored rows reference provider keys
 * (lessons.type, questions.type, entitlements.source), and the core keeps
 * those keys resolvable so removed extensions degrade to a placeholder instead
 * of breaking a course. Turning a provider off is expressed by replacing it
 * with an implementation that refuses, which keeps the key resolvable and the
 * refusal visible.
 *
 * @template TProvider of ProviderInterface
 */
abstract class AbstractProviderRegistry
{
    /** @var array<string, TProvider> */
    private array $providers = [];

    /**
     * The interface every provider in this registry must implement.
     *
     * @return class-string<TProvider>
     */
    abstract protected function providerInterface(): string;

    /**
     * @param TProvider $provider
     */
    public function register(ProviderInterface $provider): void
    {
        $this->registerUntyped($provider);
    }

    /**
     * The same registration, for a caller holding many registries in one map
     * and only ProviderInterface in hand.
     *
     * A dispatcher that maps an extension point name to its registry cannot
     * name each one's provider type, and that dispatcher is what makes
     * adding an extension point a map key rather than an edit to four
     * shipped files. guardKind() is the runtime check that keeps it safe,
     * and it is the same check the typed entry point performs: a provider of
     * the wrong kind is refused here exactly as it is there.
     */
    public function registerUntyped(ProviderInterface $provider): void
    {
        $this->guardKind($provider);

        $key = $provider->key();

        if (isset($this->providers[$key])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw DuplicateProviderException::forKey($key, static::class);
        }

        $this->providers[$key] = $provider;
    }

    /**
     * The same override, for a caller holding many registries in one map and
     * only ProviderInterface in hand.
     *
     * The typed entry point cannot serve that caller: the generic parameter
     * is covariant, so a dispatcher that maps an extension point name to its
     * registry has nothing it can pass. This is the replace() counterpart of
     * registerUntyped(), and it is safe for the same reason: guardKind() is
     * the runtime check, and it is the same check the typed entry point
     * performs.
     */
    public function replaceUntyped(ProviderInterface $provider): ProviderInterface
    {
        $this->guardKind($provider);

        $key = $provider->key();
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $displaced = $this->providers[$key] ?? throw ProviderNotRegisteredException::forKey($key, static::class);

        if ($displaced === $provider) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ProviderAlreadyInstalledException::forKey($key, static::class);
        }

        $this->providers[$key] = $provider;

        return $displaced;
    }

    /**
     * Overrides an already registered key and hands back what was there, so
     * the replacement can delegate to it. The key keeps its position among
     * keys().
     *
     * That position is not a presentation decision and must not be treated
     * as one. Keeping it is what makes a decoration invisible to anything
     * that iterates the registry, which is the point; but a surface that
     * shows providers to a person declares its own order instead, because
     * decorating a set of built-in providers would otherwise pin them at the
     * top of every list and split their family across it. The question type
     * picker declares group and order through ReportingMetadataInterface for
     * exactly that reason.
     *
     * @param TProvider $provider
     *
     * @return TProvider the displaced provider
     */
    public function replace(ProviderInterface $provider): ProviderInterface
    {
        $this->guardKind($provider);

        $key = $provider->key();
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $displaced = $this->providers[$key] ?? throw ProviderNotRegisteredException::forKey($key, static::class);

        // Replacing a provider with itself would hand the caller its own
        // instance to delegate to, and the usual wrapper shape (label() calls
        // through to the displaced provider) would then recurse until the
        // stack gives out. It is always a double-install bug.
        if ($displaced === $provider) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ProviderAlreadyInstalledException::forKey($key, static::class);
        }

        $this->providers[$key] = $provider;

        return $displaced;
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * @return TProvider
     */
    public function get(string $key): ProviderInterface
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        return $this->providers[$key] ?? throw UnknownProviderException::forKey($key, static::class);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }

    /**
     * @return list<TProvider>
     */
    public function all(): array
    {
        return array_values($this->providers);
    }

    /**
     * The runtime check the two entry points share, and the reason the
     * untyped one is safe: a provider of the wrong kind is refused with a
     * message naming both.
     *
     * @phpstan-assert TProvider $provider
     */
    private function guardKind(ProviderInterface $provider): void
    {
        $interface = $this->providerInterface();

        if (!$provider instanceof $interface) {
            throw new \InvalidArgumentException(sprintf(
                '%s accepts only %s implementations, got %s.',
                static::class,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $interface,
                $provider::class,
            ));
        }

        $this->guardProvider($provider);
    }

    /**
     * The registry's own admission rule, beyond implementing the interface.
     *
     * It exists because some interfaces publish a rule in prose that nothing
     * enforces, and a rule an implementer can break silently is a rule the
     * core will later have to defend against at every call site instead of
     * once here. The default admits everything, so no shipped registry
     * changes; a registry with a rule refuses, naming both what was declared
     * and what is allowed, in the same shape guardKind() uses.
     *
     * It runs on every entry point, because a rule that register() enforces
     * and replace() does not is a rule with a decorator-shaped hole in it.
     *
     * @param TProvider $provider
     */
    protected function guardProvider(ProviderInterface $provider): void
    {
    }
}
