<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

/**
 * What a data-use policy has to say, as the eight parts a version carries.
 *
 * **The version is the departure, and it is the difference between a consent
 * record and a consent claim.** The register this is measured against carries
 * a user, a context and a time and no version at all, so a changed policy
 * cannot force re-acceptance and no audit row can say which policy was in
 * force. Here the version is on the acceptance row, is stamped onto every
 * run, and a changed version re-prompts.
 *
 * The first four parts are the published baseline: the feature is based on
 * external large language models; accuracy varies and outputs must be
 * double-checked; information shared is handled under the model provider's
 * privacy policy; and a record of interactions may be saved on this site.
 *
 * The last four are what only this design can supply, and they are the reason
 * the baseline is not enough: **which tasks are enabled on this site, which
 * route and model family serves them, exactly which fields are sent and which
 * are never sent, and how long records are kept and where to ask.** All four
 * are answerable here because tasks, routes, redaction manifests and
 * retention windows are all rows rather than prose.
 *
 * Every part is a body key rather than a sentence, because the core owns no
 * locale and a policy is published per locale by the site owner.
 */
final class AiDataUsePolicyContent
{
    /** The four baseline parts, in the order they are shown. */
    public const BASELINE_PARTS = [
        'based_on_external_models',
        'accuracy_varies_check_outputs',
        'handled_under_provider_policy',
        'interactions_may_be_recorded',
    ];

    /**
     * The four this design adds, each answerable from a table rather than
     * from a promise.
     */
    public const DERIVED_PARTS = [
        'enabled_tasks',
        'route_and_model_family',
        'fields_sent_and_never_sent',
        'retention_and_where_to_ask',
    ];

    /**
     * The version, which is the field the baseline lacks and the whole reason
     * a changed policy can re-prompt.
     */
    public const VERSION_FIELD = 'version';

    private function __construct()
    {
    }

    /**
     * Every part a published version carries, baseline first.
     *
     * @return list<string>
     */
    public static function parts(): array
    {
        return array_merge(self::BASELINE_PARTS, self::DERIVED_PARTS);
    }

    /**
     * The skeleton of one version's body, with every part present and empty.
     *
     * Present and empty rather than absent, because a site owner editing a
     * policy has to be shown the four questions this design can answer and
     * the baseline four together: a part that is missing from the form is a
     * part nobody is asked.
     *
     * @return array<string, string>
     */
    public static function skeleton(): array
    {
        $body = [];

        foreach (self::parts() as $part) {
            $body[$part] = '';
        }

        return $body;
    }

    /**
     * Whether a stored body says something for every part.
     *
     * A version with an empty part has not disclosed what that part is for,
     * and disclosure at or before first interaction is the obligation the
     * whole acceptance gate exists to meet.
     *
     * @param array<string, mixed> $body
     *
     * @return list<string> the parts that are still empty
     */
    public static function missingParts(array $body): array
    {
        $missing = [];

        foreach (self::parts() as $part) {
            $value = $body[$part] ?? null;

            if (!\is_string($value) || trim($value) === '') {
                $missing[] = $part;
            }
        }

        return $missing;
    }
}
