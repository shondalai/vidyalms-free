<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Upload\UploadPolicy;

/**
 * Who may put bytes on this site.
 *
 * The rule is teach or manage, and it is here rather than in each adapter's
 * upload controller because that is what the coding standards say a rule is:
 * "a policy guard written in an adapter instead of a provider is a bug,
 * because it covers only the routes that adapter happens to own". It was
 * written twice, as an identical private constant and an identical loop in
 * two controllers, so a host could quietly widen it with nothing failing and
 * a third adapter would have invented its own answer.
 *
 * The per-course rule does not apply and could not: an upload happens while a
 * lesson is being drafted, before it names a course. A stored path only
 * becomes reachable when a use case that does apply the rule accepts it into
 * a lesson.
 *
 * The purpose is taken so a purpose registered by an extension can one day
 * declare a capability of its own rather than inheriting the free build's
 * two; today every purpose answers to the same pair, and the argument is what
 * makes that a decision rather than an assumption.
 */
final class AuthoriseUpload
{
    /** What an authoring purpose requires. */
    public const CAPABILITIES = [Capability::TEACH, Capability::MANAGE];

    /**
     * The purposes a learner answers with, which need no authoring
     * capability at all.
     *
     * The four produced-response types are answered by putting bytes on the
     * site, and this rule was teach-or-manage unconditionally, so a learner
     * got NotAuthorized from the only upload endpoint there is: the types
     * were authorable and not answerable. A learner is not a teacher and
     * must not become one to hand in an essay attachment, so the rule is
     * identity rather than capability here, and the bytes land in a private
     * purpose of their own rather than in the public course-image one.
     *
     * @var list<string>
     */
    public const LEARNER_PURPOSES = [UploadPolicy::PURPOSE_ATTEMPT_ARTIFACT, UploadPolicy::PURPOSE_ASSIGNMENT];

    public function __construct(
        private readonly AccessGuard $guard,
        private readonly \Shondalai\VidyaLMS\Core\Port\Database\DatabasePort $database,
        private readonly \Shondalai\VidyaLMS\Core\Port\Clock\ClockPort $clock,
        private readonly ?\Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess $organisations = null,
    )
    {
    }

    /**
     * @throws \Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException
     * @throws \Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException
     */
    public function execute(Principal $principal, string $purposeKey = '', ?int $organisationId = null): void
    {
        $purposeKey = strtolower(trim($purposeKey));
        if ($purposeKey === UploadPolicy::PURPOSE_ORG_IMPORT) {
            $this->guard->requireAuthenticated($principal);
            if ($organisationId === null || $organisationId < 1 || $this->organisations === null) {
                throw new \Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException('An organisation is required for a roster upload.');
            }
            $this->organisations->require($principal, $organisationId, 'people');
            return;
        }
        if (\in_array($purposeKey, self::LEARNER_PURPOSES, true)) {
            $this->guard->requireAuthenticated($principal);

            if (!$this->guard->can($principal, Capability::TEACH) && !$this->guard->can($principal, Capability::MANAGE)) {
                $now = $this->clock->now()->format('Y-m-d H:i:s');
                $query = \Shondalai\VidyaLMS\Core\Port\Database\Query::from('enrolments', 'n')
                    ->select(['n.id'])->join('entitlements', 'e', 'n.course_id', 'e.course_id')
                    ->where('n.user_id', '=', $principal->userId)->whereIn('n.status', ['active', 'completed'])
                    ->where('e.user_id', '=', $principal->userId)->where('e.status', '=', 'active')
                    ->whereAnyOf(\Shondalai\VidyaLMS\Core\Port\Database\Predicate::isNull('e.starts_at'),
                        \Shondalai\VidyaLMS\Core\Port\Database\Predicate::compare('e.starts_at', '<=', $now))
                    ->whereAnyOf(\Shondalai\VidyaLMS\Core\Port\Database\Predicate::isNull('e.expires_at'),
                        \Shondalai\VidyaLMS\Core\Port\Database\Predicate::compare('e.expires_at', '>', $now));
                if ($this->database->selectOne($query) === null) {
                    throw new \Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException('An active course enrolment is required to upload learner work.');
                }
            }

            return;
        }

        $this->guard->requireAny($principal, ...self::CAPABILITIES);
    }
}
