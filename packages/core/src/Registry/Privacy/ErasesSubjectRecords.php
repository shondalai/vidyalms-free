<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Privacy;

/**
 * The contributor can erase the rows it holds about one person, for the
 * **host's own** subject request.
 *
 * **It is a capability interface over the shipped contract, never a changed
 * signature.** {@see PrivacyContributorInterface} is implemented by shipped
 * providers and, once Pro registers one, by packages this repository does not
 * build; adding a fifth required method to it would break every one of them on
 * the day their host is upgraded. So it is probed with `instanceof`, exactly as
 * {@see EnumeratesOrganisationUsers} and {@see ErasesWithOutcome} are.
 *
 * **It is the other direction from `eraseFor()`, and the difference is who is
 * asking.** `eraseFor(OrgPrivacyContext)` is an organisation erasing what it
 * controls about one of its members, and a learner's own course history is
 * deliberately not in it. This is the site itself answering a subject's own
 * request under the host's privacy tooling, which reaches everything, because
 * the site is the controller of the whole record. A contributor may serve
 * either, both or neither.
 *
 * **Why the contributor does the work rather than describing it.** The two host
 * privacy hooks used to hold their own table lists, a Joomla `EXPORT_BY_USER`
 * array of eight tuples and a WordPress `OWNED_BY_USER` array of ten names
 * beside five anonymisation pairs, and adding a table meant editing both hosts
 * and a language file. A plan handed back to a host would have moved the list
 * without removing the second reading of it: the host would still have decided
 * what a step means, in what order steps run, and what an anonymised value is,
 * and the two hosts would have decided differently the first time the shape
 * grew. So the contributor holds the ports it already has and does the writes,
 * and each host hook loops over the registry and adds up what it is told.
 *
 * Default when absent: the contributor holds nothing the host's subject request
 * erases, and the host erases nothing on its behalf. That is the honest reading
 * for a contributor over rows an organisation owns, and it is what the two
 * organisation contributors answer by not implementing this.
 */
interface ErasesSubjectRecords
{
    /**
     * Erase this person's rows and answer what happened, per record class.
     *
     * The counts are keyed by the same record class keys
     * {@see PrivacyContributorInterface::describes()} names, so a host can
     * report per class without a second vocabulary.
     *
     * `erased` counts rows deleted outright, because the row exists only to
     * describe this person. `anonymised` counts rows that keep their content
     * and lose the subject's identity, because the row belongs to somebody
     * else and deleting it would destroy that third party's record.
     * `warnings` carries anything an operator has to be told, and an
     * implementation reports a failure there rather than throwing: one table
     * that could not be written must not abandon the rest of the erasure
     * half-done.
     *
     * The email is passed because one record class is legitimately keyed on an
     * address rather than an account: mail delivered before or after a person
     * had one. It is never used as an identity key.
     *
     * @return array{erased: array<string, int>, anonymised: array<string, int>, warnings: list<string>}
     */
    public function eraseSubjectRecords(int $userId, string $email): array;
}
