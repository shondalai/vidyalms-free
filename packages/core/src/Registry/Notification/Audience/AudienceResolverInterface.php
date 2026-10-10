<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification\Audience;

use Shondalai\VidyaLMS\Core\Registry\Context\AudienceContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * Who a rule's message goes to.
 *
 * resolve() returns a generator the sender consumes in batches of at most
 * AudienceContext::batchSize(), so no resolver may materialise a roster in
 * memory: a rule over a five thousand member cohort must not run five
 * thousand policy evaluations inside the transaction that wrote the intent
 * row. The Notification stays single-recipient; the rule fans out, in the
 * binned fan-out task.
 *
 * A free-text address list is deliberately not offered. A comma-separated
 * address field in an admin form is an unauthenticated mail relay, and this
 * registry is the answer to the need it was serving.
 *
 * The miss direction is published: an unresolved audience resolves empty and
 * the rule counts one suppression with reason audience_missing, never a
 * broadcast.
 */
interface AudienceResolverInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * @return iterable<int> user ids
     */
    public function resolve(AudienceContext $context): iterable;
}
