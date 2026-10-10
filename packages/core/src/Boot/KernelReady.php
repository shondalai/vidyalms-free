<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Boot;

use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Registry\Registries;

/**
 * The kernel-ready seam, declared by the core rather than by each adapter.
 *
 * Every host dispatches this once per request, after the kernel exists and
 * before any caller receives it. It is the only moment at which a package
 * outside the core can reach `$kernel->registries` to register a provider,
 * `$kernel->ports()` to build a repository for it, and `addRoutes()` to
 * contribute an endpoint.
 *
 * **Why a core class and not a convention.** Each adapter used to name its
 * own event and compose its own payload, and neither carried the ports, so
 * every repository-backed provider had to be handed a container through a
 * parameter the event did not carry. A third host implementing the seam had
 * nothing normative to build against and would have reinvented the name, the
 * payload, whether ports are passed and the ordering guarantee. Here the
 * payload has one shape and one owner.
 *
 * **The ordering guarantee.** A host dispatches this exactly once per kernel,
 * to every listener, in the host's own listener order. A package must
 * therefore not assume it runs before or after any other, and must use the
 * kernel carried in the payload rather than resolving one from the host
 * container: resolving re-enters the factory that is still building this one.
 * `register()` throws on a key collision so two packages that both believe
 * they own a key fail loudly instead of racing; `replace()` returns the
 * displaced provider so a decorator delegates rather than reimplements.
 *
 * A listener that throws is logged and swallowed by both shipped adapters, so
 * a third-party package cannot take the site down at boot, and whatever it
 * registered before it threw stays registered.
 */
final class KernelReady
{
    /**
     * The seam's own name, host-independent, for documentation and for a
     * host whose event system takes an arbitrary string.
     */
    public const NAME = 'vidyalms.kernel_ready';

    /** The Joomla event name, dispatched on the application dispatcher. */
    public const JOOMLA_EVENT = 'onVidyaLmsKernelReady';

    /** The WordPress action name, fired with the payload's kernel. */
    public const WORDPRESS_HOOK = 'vidyalms_kernel_ready';

    public function __construct(public readonly CoreKernel $kernel)
    {
    }

    public function ports(): Ports
    {
        return $this->kernel->ports();
    }

    public function registries(): Registries
    {
        return $this->kernel->registries;
    }

    /**
     * The payload every adapter dispatches.
     *
     * "subject" is Joomla's own convention for the event's subject and is the
     * same object as "kernel"; a host that has no such notion may ignore it.
     * "ports" is what makes a repository-backed provider buildable from the
     * event alone. "event" carries this object so a listener written against
     * a later core can read a member added after its host was built without
     * the payload growing a key that host does not know to pass on.
     *
     * @return array{kernel: CoreKernel, ports: Ports, subject: CoreKernel, event: self}
     */
    public function payload(): array
    {
        return [
            'kernel' => $this->kernel,
            'ports' => $this->ports(),
            'subject' => $this->kernel,
            'event' => $this,
        ];
    }
}
