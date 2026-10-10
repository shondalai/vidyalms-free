<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Privacy;

/**
 * The contributor can describe its record classes in the terms an Article 30
 * record is written in.
 *
 * **It is a capability interface over the shipped contract, never a changed
 * signature.** {@see PrivacyContributorInterface::describes()} answers a record
 * class key to a label, which is what the two host privacy hooks need to draw a
 * per-user export, and widening that return type would break every shipped and
 * third-party contributor at once. So the richer half sits here and is probed
 * with `instanceof`.
 *
 * Default when absent: a record class carries its label and nothing else, and
 * the Article 30 generator records it as undescribed rather than inventing a
 * purpose for it. An invented purpose in a statutory record is worse than a gap
 * an operator can see and fill.
 *
 * GDPR Article 30(1) names what a controller's record contains: the purposes of
 * the processing, the categories of data subjects and of personal data, the
 * categories of recipients, any third-country transfers, the envisaged erasure
 * time limits and a general description of the security measures. The keys below
 * are those, and the retention criterion is deliberately absent from them: it is
 * the **organisation's** retention policy and not the contributor's, so the
 * generator reads it from `organisations.retention_policy` and a contributor
 * cannot answer for a controller it does not know.
 */
interface DescribesForArticle30
{
    /** The purposes of the processing, as a translatable key or a sentence. */
    public const PURPOSE = 'purpose';

    /** Categories of data subjects: learners, administrators, invitees. */
    public const SUBJECT_CATEGORIES = 'subject_categories';

    /** Categories of personal data: identifiers, progress, assessment evidence. */
    public const DATA_CATEGORIES = 'data_categories';

    /** Categories of recipient this record class is disclosed to. */
    public const RECIPIENTS = 'recipients';

    /** The lawful basis this record class is processed under. */
    public const LAWFUL_BASIS = 'lawful_basis';

    /** The tables this record class is actually stored in, for an auditor. */
    public const TABLES = 'tables';

    /**
     * Article 30 metadata per record class, keyed exactly as
     * {@see PrivacyContributorInterface::describes()} keys its labels.
     *
     * A record class present in `describes()` and absent here is undescribed and
     * is recorded as such. A record class present here and absent from
     * `describes()` is ignored, because the shipped method is the register of
     * what this contributor holds and a second, longer list beside it would be a
     * second answer to the same question.
     *
     * @return array<string, array<string, mixed>>
     */
    public function article30(): array;
}
