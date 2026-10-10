<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Application\Credentials\RetireCredential;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatusRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\IssuanceRecord;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The other end of status list allocation.
 *
 * Allocation is done by the code that creates the credential and nothing was
 * symmetrical with it, so a host removing a certificate row left its entries
 * behind pointing at nothing: both live sites finished a smoke run with every
 * status entry orphaned, and a publisher walking live credentials clears the
 * bit of one that no longer exists, telling every wallet it is in force.
 */
final class CredentialStatusRetirementTest extends CredentialsTestCase
{
    private Principal $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCourse(7, 'Advanced Weaving');
        $this->ports->users->add(5, 'Asha Learner', 'asha@example.test');
        $this->ports->authorization->grant(42, Capability::MANAGE);
        $this->manager = Principal::user(42);
    }

    public function testRetiringACredentialLeavesItsSlotClaimedAndNoLongerItsOwn(): void
    {
        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $before = $this->credentialStatus()->entriesFor(IssuanceRecord::SUBJECT_CERTIFICATE, $certificate->id);

        self::assertCount(2, $before, 'one entry per supported purpose');

        $retire = new RetireCredential($this->credentialStatus(), $this->guard, $this->ports->clock);
        $lists = $retire->certificate($this->manager, $certificate->id);

        self::assertNotSame([], $lists);
        self::assertSame(
            [],
            $this->credentialStatus()->entriesFor(IssuanceRecord::SUBJECT_CERTIFICATE, $certificate->id),
        );

        // The row stays, because the index is printed inside a document
        // somebody may still hold and handing it to the next credential
        // would make one holder's revocation answer for another's.
        $rows = $this->ports->database->selectAll(
            Query::from('credential_status_entries')
                ->where('subject_type', '=', CredentialStatusRepository::SUBJECT_RETIRED),
        );

        self::assertCount(2, $rows);
    }

    public function testARetiredSlotIsNeverHandedToTheNextCredential(): void
    {
        $first = $this->issueWithSnapshot()->execute(10, 5, 7);
        $indexes = array_map(
            static fn (object $entry): int => $entry->index,
            $this->credentialStatus()->entriesFor(IssuanceRecord::SUBJECT_CERTIFICATE, $first->id),
        );

        (new RetireCredential($this->credentialStatus(), $this->guard, $this->ports->clock))
            ->certificate($this->manager, $first->id);

        $this->seedCourse(8, 'Second Course');
        $second = $this->issueWithSnapshot()->execute(11, 5, 8);

        foreach ($this->credentialStatus()->entriesFor(IssuanceRecord::SUBJECT_CERTIFICATE, $second->id) as $entry) {
            self::assertNotContains($entry->index, $indexes, 'a retired index was reused');
        }
    }

    public function testRetirementDirtiesTheListsSoTheNextPublicationReconsidersThem(): void
    {
        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);

        $lists = (new RetireCredential($this->credentialStatus(), $this->guard, $this->ports->clock))
            ->certificate($this->manager, $certificate->id);

        foreach ($lists as $listId) {
            $list = $this->credentialStatus()->findList($listId);

            self::assertNotNull($list);
            self::assertNotNull($list->dirtySince, 'a retired slot left its list clean');
        }
    }

    public function testRetirementRequiresManage(): void
    {
        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $retire = new RetireCredential($this->credentialStatus(), $this->guard, $this->ports->clock);

        $this->expectException(\Throwable::class);

        $retire->certificate(Principal::guest(), $certificate->id);
    }

    public function testRetiringACredentialThatHeldNoSlotIsANoOp(): void
    {
        $retire = new RetireCredential($this->credentialStatus(), $this->guard, $this->ports->clock);

        self::assertSame([], $retire->badgeAward($this->manager, 4242));
    }
}
