<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Boot;

use Shondalai\VidyaLMS\Core\Port\Ports;

/**
 * The port-contribution moment, one step before the kernel exists.
 *
 * ## Why it had to exist
 *
 * `Ports` is final with a readonly constructor and is built by the free
 * adapter before the kernel is constructed, so **no package outside the free
 * adapter could seat one**. Three ports are optional and defaulted precisely
 * so a paid package can supply them, and none of them could actually be
 * supplied: a Pro package holding a domain verifier had nowhere to put it, and
 * `ManageOrganisationJoining::canVerify()` answered false on every site with
 * that package installed. The organisation design recorded the gap and named
 * the fix as "a port-contribution moment on the kernel-ready seam so a package
 * can seat a port". This is that moment.
 *
 * It is deliberately **not** {@see KernelReady}. That seam fires after the
 * kernel exists, and by then every use case the kernel builds eagerly is
 * holding the port it was given. A contribution has to happen while `Ports` is
 * still being assembled, which is one step earlier and is a different event.
 *
 * ## Ports stays final and stays readonly
 *
 * Nothing here mutates one. A listener seats an implementation into this
 * collector, and {@see seal()} produces a **new** `Ports` with those
 * substitutions in place, leaving the object it was handed exactly as it was.
 * A value object is not made mutable by there being a second way to build one.
 *
 * ## Only the contributable ports, and the list is published
 *
 * {@see CONTRIBUTABLE} is the whole set a listener may seat: the three
 * identity ports the seam was built for, plus the two the free adapters leave
 * null. Everything else is refused by name and the refusal is visible through
 * {@see refused()}.
 *
 * The list exists because the seam had none. `seat()` matched a seated object
 * against the declared type of a constructor parameter and nothing else, so
 * `seat(DatabasePort::class, $spy)` substituted the site's own database handle
 * and the sealed `Ports` handed it to every use case, silently and with
 * `contested()` empty. Both docblocks said this could not happen. A seam that
 * lets a listener replace the database is not a contribution seam, it is a
 * hook into every read and write on the site.
 *
 * ## One seat per port, and the first wins
 *
 * Two packages both seating a domain verifier is two answers to "was this
 * domain proved", and the run that got the other one is the one nobody could
 * explain afterwards. So a second seat for a port already taken is refused and
 * the refusal is visible: {@see contested()} names every port more than one
 * package tried to claim, so a host can log it rather than a site behaving
 * differently depending on listener order.
 *
 * ## A host that does not dispatch it loses nothing
 *
 * The moment is optional. An adapter that never dispatches this builds `Ports`
 * exactly as it did before and every default stands, which is what makes this
 * additive rather than a change every host must make at once.
 */
final class PortsReady
{
    /** The seam's own name, host independent. */
    public const NAME = 'vidyalms.ports_ready';

    /** The Joomla event name, dispatched on the application dispatcher. */
    public const JOOMLA_EVENT = 'onVidyaLmsPortsReady';

    /** The WordPress action name. */
    public const WORDPRESS_HOOK = 'vidyalms_ports_ready';

    /**
     * Every port a listener may seat, and no others.
     *
     * The three identity ports are what the seam was built for: a package
     * holding a domain verifier, a host SSO capture or a verified-address
     * reader has nowhere else to put it. `courseLinks` and `pdf` are here
     * because both free adapters leave them null and a package supplying
     * either is supplying something the site does not have rather than
     * replacing something it does.
     *
     * Nothing else is contributable. A port the host itself owns is the host's
     * answer to a question about the host, and a listener replacing one is
     * replacing the site's own database, mailer, scheduler or authorisation
     * with something the site never chose.
     *
     * @var list<class-string>
     */
    public const CONTRIBUTABLE = [
        \Shondalai\VidyaLMS\Core\Port\Identity\AssertedIdentityPort::class,
        \Shondalai\VidyaLMS\Core\Port\Identity\DomainVerificationPort::class,
        \Shondalai\VidyaLMS\Core\Port\Identity\VerifiedAddressesPort::class,
        \Shondalai\VidyaLMS\Core\Port\Links\CourseLinkPort::class,
        \Shondalai\VidyaLMS\Core\Port\Pdf\PdfPort::class,
    ];

    /** @var array<class-string, object> */
    private array $seated = [];

    /** @var list<class-string> */
    private array $contested = [];

    /** @var list<class-string> */
    private array $refused = [];

    private function __construct()
    {
    }

    /** The named factory, and the only way one of these is made. */
    public static function collect(): self
    {
        return new self();
    }

    /**
     * Seats one implementation of one port.
     *
     * The port is named by its interface, which is what makes the substitution
     * checkable: {@see seal()} matches a seated object against the declared
     * type of each constructor parameter, so a listener that seats the wrong
     * kind of object seats nothing rather than producing a `Ports` whose
     * members do not mean what their names say.
     *
     * Returns whether the seat was taken. False means the port is not one a
     * listener may seat, or another package already holds it, and the caller
     * may say so.
     *
     * @param class-string $port
     */
    public function seat(string $port, object $implementation): bool
    {
        if (!\in_array($port, self::CONTRIBUTABLE, true)) {
            if (!\in_array($port, $this->refused, true)) {
                $this->refused[] = $port;
            }

            return false;
        }

        if (!$implementation instanceof $port) {
            return false;
        }

        if (isset($this->seated[$port])) {
            if (!\in_array($port, $this->contested, true)) {
                $this->contested[] = $port;
            }

            return false;
        }

        $this->seated[$port] = $implementation;

        return true;
    }

    /** Whether anything has been seated at all, so a host can skip the rebuild. */
    public function isEmpty(): bool
    {
        return $this->seated === [];
    }

    /**
     * The ports more than one package tried to claim.
     *
     * @return list<class-string>
     */
    public function contested(): array
    {
        return $this->contested;
    }

    /**
     * The ports a listener tried to seat that no listener may seat.
     *
     * Published beside {@see contested()} so a host can log the attempt: a
     * package trying to replace the site's database handle is either a bug or
     * something the site owner needs to know about, and answering false and
     * saying nothing would leave both invisible.
     *
     * @return list<class-string>
     */
    public function refused(): array
    {
        return $this->refused;
    }

    /**
     * The final `Ports`, with every seated implementation in place.
     *
     * Reflection over the constructor's own parameters rather than a written
     * list of members, deliberately: a list here would be a second place every
     * future port has to be added, and the one that is forgotten is the one
     * that silently stops being carried across.
     *
     * What may be seated is a different question and is answered before this
     * runs, by {@see seat()} against {@see CONTRIBUTABLE}. So a parameter whose
     * type is not on that list can never have a seated object waiting for it,
     * and every parameter with nothing seated for it is passed through
     * untouched.
     */
    public function seal(Ports $ports): Ports
    {
        if ($this->seated === []) {
            return $ports;
        }

        $class = new \ReflectionClass(Ports::class);
        $constructor = $class->getConstructor();

        if ($constructor === null) {
            return $ports;
        }

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();
            $current = $class->hasProperty($name) ? $ports->{$name} : null;
            $arguments[$name] = $this->replacementFor($parameter, $current);
        }

        return $class->newInstanceArgs($arguments);
    }

    /**
     * The seated implementation for one constructor parameter, or what is
     * already there.
     */
    private function replacementFor(\ReflectionParameter $parameter, mixed $current): mixed
    {
        $type = $parameter->getType();

        if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return $current;
        }

        $seated = $this->seated[$type->getName()] ?? null;

        return $seated ?? $current;
    }
}
