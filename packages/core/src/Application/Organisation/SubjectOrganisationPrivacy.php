<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Registry\Privacy\ErasesSubjectRecords;
use Shondalai\VidyaLMS\Core\Registry\Privacy\PrivacyContributorRegistry;
use Shondalai\VidyaLMS\Core\Registry\Privacy\SubjectPrivacyGroup;

/** Trusted native subject-request bridge. No public route or authority bypass is exposed. */
final class SubjectOrganisationPrivacy
{
    public function __construct(private readonly PrivacyContributorRegistry $contributors, private readonly DatabasePort $database)
    {
    }

    /** @return list<SubjectPrivacyGroup> */
    public function groups(int $userId, string $email): array
    {
        $groups = [];
        foreach ($this->contributors->all() as $contributor) {
            array_push($groups, ...$contributor->subjectGroups($userId, $email));
        }
        return $groups;
    }

    /** @return list<string> */
    public function missing(): array
    {
        return $this->contributors->missing();
    }

    /**
     * Erase everything the registered contributors hold about one person, for
     * the host's own subject request.
     *
     * The loop is here and not in either privacy hook for the reason
     * {@see groups()} is: two independently written loops are two readings of
     * one contract, and the day they disagreed the disagreement would look
     * like a host difference rather than a defect. Both hooks used to hold a
     * table list of their own instead, a Joomla array of ten tables and five
     * anonymisation calls and a WordPress array of ten names beside five
     * pairs, and adding a table meant editing both.
     *
     * A contributor that does not declare {@see ErasesSubjectRecords} holds
     * nothing this direction erases and is skipped, which is what the two
     * organisation contributors mean by not declaring it. A contributor that
     * throws is named in the warnings and the rest still run: one broken
     * package must not abandon an erasure half done.
     *
     * @return array{erased: array<string, int>, anonymised: array<string, int>, warnings: list<string>}
     */
    public function eraseSubjectRecords(int $userId, string $email): array
    {
        $erased = [];
        $anonymised = [];
        $warnings = [];

        foreach ($this->contributors->all() as $contributor) {
            if (!$contributor instanceof ErasesSubjectRecords) {
                continue;
            }

            try {
                $outcome = $contributor->eraseSubjectRecords($userId, $email);
            } catch (\Throwable $failure) {
                $warnings[] = $contributor->key() . ': ' . $failure->getMessage();

                continue;
            }

            foreach ($outcome['erased'] as $recordClass => $count) {
                $erased[$recordClass] = ($erased[$recordClass] ?? 0) + $count;
            }

            foreach ($outcome['anonymised'] as $reference => $count) {
                $anonymised[$reference] = ($anonymised[$reference] ?? 0) + $count;
            }

            array_push($warnings, ...$outcome['warnings']);
        }

        return ['erased' => $erased, 'anonymised' => $anonymised, 'warnings' => $warnings];
    }

    /**
     * A host's legacy all-user delete must never silently bypass
     * relationship or ledger retention.
     *
     * **A contributor that erases its own rows is not a reason to refuse.**
     * The refusal exists for rows the host request must not touch: an
     * organisation relationship, a seat ledger entry, a report artefact
     * somebody else commissioned. A contributor declaring
     * {@see ErasesSubjectRecords} is saying the opposite, that these rows are
     * the subject's own and it will erase them when asked, so counting them
     * here would refuse every erasure the moment the core registered a
     * contributor over the learner's own learning: any learner with one
     * enrolment would have been undeleteable on both hosts, which is a
     * statutory obligation failed silently.
     */
    public function requiresReview(int $userId, string $email): bool
    {
        if ($this->missing() !== []) {
            return true;
        }
        foreach ($this->contributors->all() as $contributor) {
            if ($contributor instanceof ErasesSubjectRecords) {
                continue;
            }

            foreach ($contributor->subjectGroups($userId, $email) as $group) {
                if ($this->database->count(($group->query)()) > 0) {
                    return true;
                }
            }
        }
        return false;
    }
}
