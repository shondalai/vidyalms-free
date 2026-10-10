<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification\Trigger;

use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * What a notification rule fires on.
 *
 * Triggers come in two kinds behind one registry, because no event exists for
 * the absence of an event: an event trigger binds to domain event classes, a
 * sweep trigger answers what is due. One generic scheduled task runs every
 * registered sweep, so a new sweep needs no new task type and no scheduler
 * registration.
 *
 * The category and the class live here rather than on the rule row. Category
 * is the preference unit and the template key is the message identity, so a
 * new template inherits an existing category and needs no preference
 * migration; there is therefore no categories table and a row cannot name a
 * category no provider owns. The class is derived at enqueue from the trigger
 * that owns the template, and a rule cannot raise it.
 *
 * declaredPlaceholders() is what turns an unknown placeholder into a
 * save-time error rather than a send-time one, and it is what the admin
 * insert menu offers, scoped to the selected trigger.
 *
 * The miss direction is published: a rule naming an unregistered trigger is
 * inert and is reported as inert on the rules screen, never fired with a
 * guessed template.
 */
interface NotificationTriggerInterface extends ProviderInterface
{
    public const KIND_EVENT = 'event';
    public const KIND_SWEEP = 'sweep';

    public function label(): string;

    /** One of KIND_EVENT or KIND_SWEEP. */
    public function kind(): string;

    public function categoryKey(): string;

    public function messageClass(): NotificationClass;

    public function defaultAudienceKey(): string;

    /** The template key this trigger's rules default to. */
    public function defaultTemplateKey(): string;

    /**
     * The placeholder vocabulary this trigger's templates may use.
     *
     * @return list<string>
     */
    public function declaredPlaceholders(): array;
}
