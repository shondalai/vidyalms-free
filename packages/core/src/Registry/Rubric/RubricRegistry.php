<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Rubric;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * The rubric implementations this site has, which is at most one.
 *
 * The miss is degradation to points: a site with no provider marks a
 * constructed response the way it always did, a grader types a number, and
 * the criteria member of the grade request is refused as a field the site
 * cannot honour rather than silently dropped. That is the safe direction:
 * accepting a per-criterion mark nobody can record would lose the rater's
 * judgement while telling them it was saved.
 *
 * @extends AbstractProviderRegistry<RubricProviderInterface>
 */
final class RubricRegistry extends AbstractProviderRegistry
{
    /** @param array<string, mixed> $definition */
    public function validateAttachment(
        \Shondalai\VidyaLMS\Core\Application\Shared\Principal $principal,
        array $definition,
        ?int $courseId,
    ): void {
        $versionId = $definition['rubric_version_id'] ?? null;
        if ($versionId === null) {
            return;
        }
        if (is_int($versionId) && $versionId > 0) {
            foreach ($this->all() as $provider) {
                if ($provider instanceof AuthorisesRubricAttachment) {
                    $provider->requireAttachment($principal, $versionId, $courseId);
                    return;
                }
            }
        }
        throw \Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException::forField(
            'rubric_version_id', 'Choose an available rubric for this course or bank.',
        );
    }

    protected function providerInterface(): string
    {
        return RubricProviderInterface::class;
    }

    /**
     * The rubric a question is marked against, and the provider that owns
     * it, or null when nothing claims the question.
     *
     * The pair travels together because a caller that has one always needs
     * the other: the rubric to validate and render, the provider to compute
     * and record. Returning the rubric alone would make every call site look
     * the provider up again and pick a different one on a site that somehow
     * has two.
     *
     * @return array{RubricProviderInterface, ResolvedRubric}|null
     */
    public function forQuestion(int $questionId): ?array
    {
        foreach ($this->all() as $provider) {
            $rubric = $provider->forQuestion($questionId);

            if ($rubric !== null) {
                return [$provider, $rubric];
            }
        }

        return null;
    }
}
