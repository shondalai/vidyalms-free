<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefaultDefinitionVersion;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionVersionAwareInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\GradeResult;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeInterface;

/**
 * What the key resolves to once a package is gone.
 *
 * Replacing a provider with one that refuses is the documented way to turn a
 * type off, and it is the honest simulation of a removal: the key stays
 * resolvable, which is exactly why the version gate is needed. A provider
 * that supports no stored version at all routes every row to the placeholder
 * rather than to a formula that cannot read the shape.
 */
final class RemovedEssayPlaceholder implements QuestionTypeInterface, DefinitionVersionAwareInterface
{
    use DefaultDefinitionVersion;

    public function __construct(private readonly TranslatorPort $translator)
    {
    }

    public function key(): string
    {
        return RemovableEssayType::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('x_acme.essay.removed');
    }

    public function supportedDefinitionVersions(): array
    {
        return [];
    }

    public function validateDefinition(array $definition): void
    {
    }

    public function presentForLearner(array $definition): array
    {
        return [];
    }

    public function grade(array $definition, mixed $answer): GradeResult
    {
        return GradeResult::incorrect();
    }
}
