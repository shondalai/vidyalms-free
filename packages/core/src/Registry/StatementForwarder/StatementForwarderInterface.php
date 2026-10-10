<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\StatementForwarder;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Reporting\XapiStatement;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * Where a built statement goes after the core has published it.
 *
 * The split already decided stays: the core builds statements and publishes
 * `XapiStatementBuilt`, and holds no LRS, no endpoint, no credential and no
 * queue, because a build that cannot say what it emits cannot be verified
 * against the standard by anyone. An LRS package, a Caliper package or a
 * warehouse package each register a mapper next to their own code.
 *
 * **Caliper does not go in the core.** Its Envelope (a required sensor, a
 * `sendTime` to millisecond UTC precision, a `dataVersion` and `data`), its
 * `urn:uuid` v4 event ids and its per-domain profile vocabulary are a second
 * wire format, and this registry is the whole point.
 *
 * **Miss direction: silent.** The statement is built, published and forwarded
 * nowhere, which is exactly today's behaviour, so an absent forwarder is the
 * normal state rather than a fault.
 *
 * **The idempotency rule is here because it is easy to lose in a rewrite.**
 * Forward with `PUT /statements?statementId=` using the id the statement
 * already carries, so a redelivery is a no-op returning 204. Send `occurredAt`
 * as `timestamp` and never the fan-out time. Treat a 409 as a mismatch to
 * investigate rather than something to retry. Correct a wrong statement by
 * **voiding** it with the reserved `voided` verb and a `StatementRef` rather
 * than resending a changed one, noting that voiding statements cannot
 * themselves be voided. And resume from `X-Experience-API-Consistent-Through`
 * rather than from a local clock. xAPI 2.0 is IEEE 9274.1.1-2023 and a
 * conformant 2.0 LRS must still accept 1.0.3 statements, so the existing
 * builder stays valid.
 */
interface StatementForwarderInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * The event names or statement verbs this forwarder wants, or an empty
     * list for all of them.
     *
     * Keying is what lets a warehouse package take completions and an LRS
     * package take everything without either of them filtering the other's
     * traffic.
     *
     * **The verb is in the vocabulary and that is not a convenience.** Exactly
     * one event is published into this fan-out, `vidyalms.xapi.statement_built`,
     * so a filter matching only the event name had one value it could ever be
     * given and the promise above was unkeepable: a forwarder wanting
     * completions and nothing else received either everything or nothing. The
     * originating fact is the statement's own verb, which the statement
     * already carries, so the filter reads both and a forwarder names either.
     *
     * @return list<string> event names, statement verb URIs, or both
     */
    public function eventNames(): array;

    /**
     * Never throws out of the fan-out. A forwarder that cannot reach its
     * endpoint records that and returns; the statement is already built and
     * already published, and taking the caller down with it would make an
     * optional integration a hard dependency of grading an attempt.
     */
    public function forward(XapiStatement $statement, StatementForwardContext $context): void;
}
