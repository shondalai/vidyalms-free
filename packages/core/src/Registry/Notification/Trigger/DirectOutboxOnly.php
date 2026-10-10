<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification\Trigger;

/**
 * This trigger is a direct-outbox identity and is deliberately not an
 * automation trigger.
 *
 * Absent means the trigger is an ordinary automation trigger candidate: it is
 * an EventTriggerInterface or a SweepTriggerInterface, the fan-out can resolve
 * a subject from it, and the rule builder offers it. **The builder's trigger
 * picker excludes every trigger declaring this, and so does the fan-out.**
 *
 * The decision it records, taken rather than deferred. Two shipped triggers
 * implement NotificationTriggerInterface and neither of the two kinds, so
 * they declare no eventClasses(), no parametersFor() and no subjectFor(): the
 * organisation invitation and the membership confirmation code. They exist so
 * that a secret is never materialised in a domain event and is instead handed
 * straight to the outbox by the use case that minted it. Promoting them to
 * EventTriggerInterface would need an event carrying the invitation token or
 * the proof code, which is precisely what the encrypted invitation ceremony
 * exists to prevent, and a subjectFor() over an invitation whose recipient
 * may not yet be a user would answer with a null pair every time.
 *
 * So the third kind stays, and it is named rather than left as an anomaly the
 * fan-out discovers at run time. A trigger declaring this may still own a
 * template, a category and a message class, which is everything the direct
 * outbox path asks of it; it simply cannot be the trigger of a rule, because
 * a rule with no resolvable subject has nothing to fan out over, nothing to
 * key an idempotency digest on and nothing to apply a per-subject cooldown to.
 *
 * A package wanting an automation on the same moment registers an ordinary
 * event trigger over an event that carries no secret, which is a registration
 * and not a core edit.
 */
interface DirectOutboxOnly
{
}
