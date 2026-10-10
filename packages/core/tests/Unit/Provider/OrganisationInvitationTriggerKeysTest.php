<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\OrganisationInvitationTrigger;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTriggerInterface;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * One trigger vocabulary, and the organisation pair is inside it.
 *
 * The pair was once recorded as an outlier that said "mail" where every other
 * trigger said "trigger", and an alias map was added to soften a rename that
 * was never needed. It was a misreading: key() and label() are different
 * things, every free event trigger's key() is vidyalms.mail.* and its label()
 * is the vidyalms.trigger.* catalogue key a picker renders, and these two
 * follow that split already.
 *
 * So this pins the split across every registered trigger rather than two
 * strings on one provider. A trigger key is a stored column value
 * (notification_rules.trigger_key, notification_templates.template_key) and a
 * rule whose trigger stops resolving stops firing rather than failing, so the
 * one thing that must never drift is a key, and the one thing that must never
 * be mistaken for a key is a label.
 */
#[CoversClass(OrganisationInvitationTrigger::class)]
final class OrganisationInvitationTriggerKeysTest extends TestCase
{
    public function testTheShippedKeysAreFrozenAtTheirStoredSpelling(): void
    {
        self::assertSame(
            'vidyalms.mail.organisation_invitation',
            (new OrganisationInvitationTrigger())->key(),
        );
        self::assertSame(
            'vidyalms.mail.organisation_proof',
            (new OrganisationInvitationTrigger(true))->key(),
        );
    }

    public function testTheDefaultTemplateKeyIsTheStoredKeySoSeededTemplatesStayResolvable(): void
    {
        self::assertSame(
            (new OrganisationInvitationTrigger())->key(),
            (new OrganisationInvitationTrigger())->defaultTemplateKey(),
        );
        self::assertSame(
            (new OrganisationInvitationTrigger(true))->key(),
            (new OrganisationInvitationTrigger(true))->defaultTemplateKey(),
        );
    }

    /**
     * The split, over the whole registry: no key is a label spelling and
     * every label is one. A provider that returned its label from key() would
     * store a value no earlier row uses and resolve none of them.
     */
    public function testEveryRegisteredTriggerKeepsTheKeyAndLabelVocabulariesApart(): void
    {
        $triggers = $this->registeredTriggers();

        self::assertGreaterThanOrEqual(8, \count($triggers));

        foreach ($triggers as $trigger) {
            $key = $trigger->key();
            $label = $trigger->label();

            self::assertStringStartsWith('vidyalms.trigger.', $label, $key . ' publishes a label outside the catalogue vocabulary.');
            self::assertStringStartsNotWith('vidyalms.trigger.', $key, $key . ' stores a label spelling as its key.');
            self::assertNotSame($key, $label, $key . ' publishes its stored key as its label.');
        }
    }

    /**
     * The organisation pair is ordinary. Six free triggers key on
     * vidyalms.mail.*, and so do these two, which is why no rename was owed.
     */
    public function testTheOrganisationPairSharesThePrefixTheOtherMailTriggersUse(): void
    {
        $mail = [];

        foreach ($this->registeredTriggers() as $trigger) {
            if (str_starts_with($trigger->key(), 'vidyalms.mail.')) {
                $mail[] = $trigger->key();
            }
        }

        self::assertContains('vidyalms.mail.organisation_invitation', $mail);
        self::assertContains('vidyalms.mail.organisation_proof', $mail);
        self::assertGreaterThanOrEqual(8, \count($mail), 'The mail prefix is the shipped majority, not an exception.');
    }

    /** No alias map: a second spelling on two providers only would be a second vocabulary. */
    public function testTheProviderPublishesNoAliasVocabulary(): void
    {
        $reflection = new \ReflectionClass(OrganisationInvitationTrigger::class);
        $own = array_values(array_map(
            static fn (\ReflectionClassConstant $constant): string => $constant->getName(),
            array_filter(
                $reflection->getReflectionConstants(),
                static fn (\ReflectionClassConstant $constant): bool
                    => $constant->getDeclaringClass()->getName() === OrganisationInvitationTrigger::class,
            ),
        ));

        self::assertSame([], $own, 'The provider declares a constant vocabulary of its own.');
        self::assertFalse($reflection->hasMethod('canonicalKey'));
    }

    /** @return list<NotificationTriggerInterface> */
    private function registeredTriggers(): array
    {
        $env = TestPorts::create();
        $kernel = new CoreKernel($env->ports, [
            'organisation_invitation_secret' => 'trigger-vocabulary-secret-trigger-vocabulary',
            'organisation_invitation_url' => 'https://example.test/membership',
        ]);

        $triggers = [];

        foreach ($kernel->registries->notificationTriggers->keys() as $key) {
            $triggers[] = $kernel->registries->notificationTriggers->get($key);
        }

        return $triggers;
    }
}
