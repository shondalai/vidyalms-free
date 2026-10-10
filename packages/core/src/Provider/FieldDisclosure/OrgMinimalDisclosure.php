<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\FieldDisclosure;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Context\FieldDisclosureContext;
use Shondalai\VidyaLMS\Core\Registry\FieldDisclosure\FieldDisclosureDecision;
use Shondalai\VidyaLMS\Core\Registry\FieldDisclosure\FieldDisclosurePolicyInterface;

/**
 * Only what proves completion, which is the free build's whole disclosure rule.
 *
 * The design's list is six things an organisation-scoped principal never sees:
 * a learner's answer text, an open-response submission body, an integrity
 * signal, an IP address, an email address on a domain the organisation has not
 * claimed, and any row for a course the learner took outside the organisation
 * relationship. Five of the six are field decisions and are made here. The
 * sixth, `non_org_course_row`, is a **row** rule and not a field rule, and it is
 * already enforced one layer down by the entitlement bound every organisation
 * read carries, so it is named in {@see DENIED} for the vocabulary's sake and
 * answered as an omission for any caller that asks about it as a field.
 *
 * **The deny list is matched on aliases as well as on the vocabulary word,**
 * because the vocabulary names a kind of data and a report names a column. A
 * policy that only recognised the word `ip_address` would disclose a column
 * called `ip` and a column called `remote_addr` while claiming to hide IP
 * addresses, which is the failure the whole pipeline exists to prevent. The
 * aliases are matched case-insensitively against the column key and its last
 * dotted or aliased segment, so `a.email` and `email` decide alike.
 *
 * **`email_on_unclaimed_domain` reads as "every address" in the free build, and
 * that is the honest answer rather than a placeholder.** Domain claiming lands
 * with the identity seam; until an organisation can hold a verified domain, no
 * domain is claimed, so no address is on a claimed one. The context carries the
 * resolved scope precisely so the Business policy that consults `org_domains`
 * decides the same question with more to read, and this one does not have to be
 * rewritten when it does.
 */
final class OrgMinimalDisclosure implements FieldDisclosurePolicyInterface
{
    public const KEY = 'org_minimal';

    /**
     * The design's six denied kinds, each with the column spellings a report is
     * likely to use for it. The vocabulary word is always its own first alias,
     * so the spec's list and this map cannot drift apart silently.
     *
     * @var array<string, list<string>>
     */
    public const DENIED = [
        'answer_text' => ['answer_text', 'answer', 'answer_body', 'response_text', 'learner_answer'],
        'submission_body' => ['submission_body', 'submission_text', 'body', 'response_body', 'essay'],
        'integrity_signal' => ['integrity_signal', 'integrity_signals', 'integrity_flag', 'proctoring_signal'],
        'ip_address' => ['ip_address', 'ip', 'ip_hash', 'remote_addr', 'client_ip'],
        'email_on_unclaimed_domain' => ['email_on_unclaimed_domain', 'email', 'email_address', 'contact_email'],
        'non_org_course_row' => ['non_org_course_row'],
    ];

    public function __construct(private readonly TranslatorPort $translator)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.field_disclosure.org_minimal');
    }

    public function decide(FieldDisclosureContext $context): string
    {
        return self::denies($context->field)
            ? FieldDisclosureDecision::OMIT
            : FieldDisclosureDecision::ALLOW;
    }

    /** Whether this column name names one of the six denied kinds. */
    public static function denies(string $field): bool
    {
        $name = self::normalise($field);

        if ($name === '') {
            return true;
        }

        foreach (self::DENIED as $aliases) {
            if (\in_array($name, $aliases, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A column key reduced to the name a deny list can recognise: lowercased,
     * with a table prefix and an "AS" alias stripped.
     */
    private static function normalise(string $field): string
    {
        $name = strtolower(trim($field));
        $alias = strrpos($name, ' as ');

        if ($alias !== false) {
            $name = trim(substr($name, $alias + 4));
        }

        $dot = strrpos($name, '.');

        return $dot === false ? $name : substr($name, $dot + 1);
    }
}
