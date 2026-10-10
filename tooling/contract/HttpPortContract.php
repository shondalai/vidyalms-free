<?php

/**
 * @package     VidyaLMS
 * @subpackage  Tooling
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 */

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Tooling\Contract;

use Shondalai\VidyaLMS\Core\Port\Http\HttpPolicy;
use Shondalai\VidyaLMS\Core\Port\Http\HttpPort;
use Shondalai\VidyaLMS\Core\Port\Http\HttpRequest;
use Shondalai\VidyaLMS\Core\Port\Http\HttpResponse;

/**
 * What every implementation of HttpPort must answer identically: RefusingHttp
 * in the core, JoomlaHttp on Joomla, WpHttp on WordPress, and whatever a third
 * adapter supplies.
 *
 * It is a plain class rather than a PHPUnit test for the reason
 * {@see DatabasePortContract} is: two of the implementations only exist inside
 * a booted CMS, where PHPUnit is not available. The core wraps it in a test;
 * each adapter's build/port-contract.php calls it against its own port.
 *
 * **It makes no outbound call.** Every assertion here is about a refusal, and
 * every refusal is decided before the transport is reached: a host outside the
 * allowlist, a scheme the request grammar refuses, a port with no
 * implementation behind it. A conformance kit that needed the network would be
 * a kit nobody could run on the site that most needs it.
 *
 * The three things it checks are exactly the three an adapter used to decide
 * for itself, each of which is a security surface rather than a detail:
 *
 * 1. **The allowlist is a host match and not a suffix match.** An entry
 *    `api.stripe.com` must not admit `notapi.stripe.com`, which is a host an
 *    attacker can register, and an entry `.stripe.com` must admit a subdomain
 *    and nothing else.
 * 2. **A host that is not allowed is refused before the call**, with the
 *    published reason, and the refusal says no call was made.
 * 3. **The refusal vocabulary is the published one.** A caller branches on
 *    these four strings, and an adapter inventing a fifth is an adapter whose
 *    failures nothing can act on.
 */
final class HttpPortContract
{
    /** A host nothing may resolve. RFC 2606 reserves it precisely for this. */
    public const FORBIDDEN_HOST = 'blocked.invalid';

    /** @var list<string> */
    private array $failures = [];

    /**
     * Null when only the policy half is being checked.
     *
     * A nullable property rather than a stand-in class, because this file is
     * required by both adapters' smoke scripts **before** the CMS autoloader
     * has resolved anything: a class declared here with `implements HttpPort`
     * in its header fatals at require time, where a type hint on a property
     * is resolved only when something is passed.
     */
    private function __construct(
        private readonly ?HttpPort $port,
    ) {
    }

    /**
     * @return list<string> one line per broken expectation; empty means the
     *                      port satisfies the contract
     */
    public static function failures(HttpPort $port): array
    {
        $contract = new self($port);
        $contract->run();

        return $contract->failures;
    }

    /**
     * The half of the contract that is pure policy, checkable with no port at
     * all, so an adapter author can run it before wiring anything.
     *
     * @return list<string>
     */
    public static function policyFailures(): array
    {
        $contract = new self(null);
        $contract->checkPolicy();

        return $contract->failures;
    }

    private function run(): void
    {
        $this->checkPolicy();

        if ($this->port === null) {
            return;
        }

        // A host no allowlist can contain is refused before the transport, and
        // the answer says a call was not made. This is the whole reason the
        // port publishes allows() beside request(): a settings screen has to
        // be able to tell a site owner that a configured gateway is on a host
        // their site will not reach, rather than leaving them to find out at
        // the first payment.
        $this->true(
            !$this->port->allows(self::FORBIDDEN_HOST),
            'allows() admits ' . self::FORBIDDEN_HOST . ', which no site should reach',
        );

        $response = $this->port->request(HttpRequest::create('GET', 'https://' . self::FORBIDDEN_HOST . '/probe'));

        $this->true(!$response->wasMade(), 'a refused request reported that a call was made');
        $this->true(
            \in_array($response->refusedReason, [
                HttpResponse::REFUSED_HOST_NOT_ALLOWED,
                HttpResponse::REFUSED_NO_IMPLEMENTATION,
            ], true),
            sprintf(
                'a call to a host outside the allowlist was refused with "%s", which is not a published reason',
                (string) $response->refusedReason,
            ),
        );
        $this->same(0, $response->status, 'a refusal carries a status');
        $this->same('', $response->body, 'a refusal carries a body');
    }

    private function checkPolicy(): void
    {
        // The classic mistake, asserted rather than described: suffix matching
        // without the dot admits a host an attacker can register.
        $this->true(HttpPolicy::allows(['api.stripe.com'], 'api.stripe.com'), 'an exact host is not allowed');
        $this->true(
            !HttpPolicy::allows(['api.stripe.com'], 'notapi.stripe.com'),
            'the allowlist suffix-matched, so notapi.stripe.com passed for api.stripe.com',
        );
        $this->true(HttpPolicy::allows(['.stripe.com'], 'api.stripe.com'), 'a dotted entry did not admit a subdomain');
        $this->true(!HttpPolicy::allows(['.stripe.com'], 'stripe.com.evil.test'), 'a dotted entry matched a suffix');
        $this->true(!HttpPolicy::allows(['api.stripe.com'], ''), 'an empty host was allowed');
        $this->true(HttpPolicy::allows(['api.stripe.com'], ' API.Stripe.com '), 'the host match is case sensitive');

        // The clamp is a ceiling and never a floor: a caller can ask for less
        // and never for more, because a provider naming a timeout longer than
        // the web server's turns a slow remote into an exhausted pool.
        $this->same(15, HttpPolicy::timeoutFor(null), 'the default timeout moved');
        $this->same(5, HttpPolicy::timeoutFor(5, 30), 'a caller asking for less was raised to the ceiling');
        $this->same(30, HttpPolicy::timeoutFor(600, 30), 'a caller asking for more was not lowered to the ceiling');
        $this->same(
            HttpPolicy::MAX_TIMEOUT_SECONDS,
            HttpPolicy::timeoutFor(null, 9999),
            'a site ceiling above the maximum was honoured',
        );
        $this->same(1, HttpPolicy::timeoutFor(0, 30), 'a zero timeout was passed through');

        // One vocabulary for both hosts. WordPress puts the word in the error
        // code and Joomla puts it in the message, so both spellings classify
        // the same way or one failure is called two things.
        $this->same(
            HttpResponse::REFUSED_TIMEOUT,
            HttpPolicy::classify('cURL error 28: Operation timed out after 15000 milliseconds'),
            'a curl timeout was not classified as a timeout',
        );
        $this->same(
            HttpResponse::REFUSED_TIMEOUT,
            HttpPolicy::classify('http_request_failed timeout'),
            'a WordPress timeout code was not classified as a timeout',
        );
        $this->same(
            HttpResponse::REFUSED_TRANSPORT,
            HttpPolicy::classify('Could not resolve host'),
            'an unreachable host was classified as a timeout',
        );
    }

    private function same(mixed $expected, mixed $actual, string $what): void
    {
        if ($expected === $actual) {
            return;
        }

        $this->failures[] = sprintf(
            '%s: expected %s, got %s',
            $what,
            json_encode($expected),
            json_encode($actual),
        );
    }

    private function true(bool $condition, string $what): void
    {
        if (!$condition) {
            $this->failures[] = $what;
        }
    }
}
