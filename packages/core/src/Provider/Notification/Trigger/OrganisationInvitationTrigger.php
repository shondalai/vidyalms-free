<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Trigger;

use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\DeclaresNotificationTemplateBlueprint;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\DirectOutboxOnly;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTemplateBlueprint;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTriggerInterface;

/**
 * Secure direct-outbox identities; never materialize secrets in a domain event.
 *
 * **The two keys match the shipped vocabulary and no rename was ever needed.**
 * Every free event trigger's key() is `vidyalms.mail.*` (attempt_graded,
 * certificate_issued, content_unlocked, course_completed, enrolled,
 * submission_received) and its label() is `vidyalms.trigger.*`, which is the
 * catalogue key a picker renders. These two follow exactly that split. An
 * earlier reading of this file mistook the labels for keys and recorded the
 * pair as an outlier awaiting a rename; they are not, and a rename would have
 * orphaned the stored rows (`notification_rules.trigger_key` and
 * `notification_templates.template_key` are stored columns, and a rule whose
 * trigger no longer resolves stops firing rather than failing).
 *
 * There is deliberately no alias map. Adding one here would let these two
 * triggers answer to a second spelling that the other six do not, and
 * `NotificationAdmin::save` validates a submitted trigger_key with a plain
 * registry `has()`, so a spelling the registry does not hold is refused
 * whatever a map in one provider says.
 *
 * **These two are not automation triggers, and that is a decision rather than
 * an omission.** They implement neither EventTriggerInterface nor
 * SweepTriggerInterface, so they declare no eventClasses(), no
 * parametersFor() and no subjectFor(), and an automation fan-out has nothing
 * to resolve a subject from. Promoting them would need a domain event
 * carrying the invitation token or the proof code, which is exactly what the
 * first line of this docblock refuses and what the encrypted invitation
 * ceremony exists to prevent; and the recipient of an invitation may not be a
 * user at all yet, so a subject pair would be null on the path that matters.
 * DirectOutboxOnly names the third kind so the rule builder's trigger picker
 * excludes it by declaration rather than by discovering at run time that a
 * rule cannot fan out.
 */
final class OrganisationInvitationTrigger implements
    NotificationTriggerInterface,
    DeclaresNotificationTemplateBlueprint,
    DirectOutboxOnly
{
    public function __construct(private readonly bool $proof = false)
    {
    }

    public function key(): string
    {
        return $this->proof ? 'vidyalms.mail.organisation_proof' : 'vidyalms.mail.organisation_invitation';
    }

    /**
     * A catalogue key, as every other trigger's label is: the human readable
     * strings are packages/core/i18n/catalogue.php's
     * "Organisation invitation" and "Membership confirmation code".
     */
    public function label(): string
    {
        return $this->proof ? 'vidyalms.trigger.organisation_proof' : 'vidyalms.trigger.organisation_invitation';
    }

    public function kind(): string
    {
        return self::KIND_EVENT;
    }

    public function categoryKey(): string
    {
        return 'account';
    }

    public function messageClass(): NotificationClass
    {
        return NotificationClass::Transactional;
    }

    public function defaultAudienceKey(): string
    {
        return 'learner';
    }

    public function defaultTemplateKey(): string
    {
        return $this->key();
    }

    public function declaredPlaceholders(): array
    {
        return ['organisation_name', $this->proof ? 'proof_code' : 'invitation.url'];
    }

    public function notificationTemplateBlueprint(): NotificationTemplateBlueprint
    {
        $token = $this->proof ? 'proof_code' : 'invitation.url';
        return new NotificationTemplateBlueprint(
            1,
            [
            'subject' => $this->proof ? 'Confirm your membership of {organisation_name}' : 'You’re invited to {organisation_name}',
            'preheader' => $this->proof ? 'Your private confirmation code expires in ten minutes.' : 'Your team is ready to welcome you.',
            'heading' => $this->proof ? 'One final step' : 'Learn together. Grow together.',
            'paragraphs' => $this->proof
                ? ['Your confirmation code is {proof_code}.', 'Enter it in the signed-in window that requested it. Never share this code.']
                : ['You have been invited to join {organisation_name}.',
                    'Sign in with this email address. We’ll send a fresh confirmation code before adding you to the team.'],
            'action_label' => $this->proof ? null : 'Review invitation',
            'action_url' => $this->proof ? null : '{invitation.url}',
            'footer' => 'If you were not expecting this message, you can safely ignore it.',
            'logo_url' => null, 'logo_alt' => null, 'accent_color' => '#3155A6',
            'short_body' => $this->proof ? 'Your membership code is {proof_code}.' : 'You’re invited to {organisation_name}.',
            ],
            ['organisation_name' => 'Acme Learning', $token => $this->proof ? 'DEMO-ONLY-00' : 'https://example.invalid/#/invitation/demo'],
            ['organisation_name', $token]
        );
    }
}
