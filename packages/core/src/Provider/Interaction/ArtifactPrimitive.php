<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Interaction;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Answer\AnswerError;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Answer\AnswerValidity;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Answer\ErrorKind;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\BaseType;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Cardinality;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseValue;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Surface;
use Shondalai\VidyaLMS\Core\Port\I18n\NeutralStrings;
use Shondalai\VidyaLMS\Core\Registry\Context\DecodeContext;
use Shondalai\VidyaLMS\Core\Registry\Interaction\InteractionPrimitiveRegistry;

/**
 * A produced file: an upload, a drawing, a recording.
 *
 * Every artifact type marks externally, so the primitive's job is the
 * envelope and the accepted-type check, never a score.
 */
final class ArtifactPrimitive extends AbstractInteractionPrimitive
{
    public const CONSTRAINT_ACCEPTED_TYPES = 'accepted_types';

    public function key(): string
    {
        return InteractionPrimitiveRegistry::ARTIFACT;
    }

    public function surfaces(): array
    {
        return [Surface::Flow, Surface::Media];
    }

    public function shapes(): array
    {
        return [
            ResponseShape::of(Cardinality::Single, BaseType::File),
            ResponseShape::of(Cardinality::Multiple, BaseType::File),
            ResponseShape::anyRecord(),
        ];
    }

    public function projectConstraints(array $definition): array
    {
        $constraints = parent::projectConstraints($definition);
        $accepted = $definition[self::CONSTRAINT_ACCEPTED_TYPES] ?? null;

        if (\is_array($accepted)) {
            $types = [];

            foreach ($accepted as $type) {
                if (\is_string($type)) {
                    $types[] = strtolower($type);
                }
            }

            if ($types !== []) {
                $constraints[self::CONSTRAINT_ACCEPTED_TYPES] = $types;
            }
        }

        return $constraints;
    }

    public function validateAnswer(array $constraints, ResponseValue $value, DecodeContext $context): AnswerValidity
    {
        if ($value->isNullOrEmpty()) {
            return AnswerValidity::empty();
        }

        $accepted = $constraints[self::CONSTRAINT_ACCEPTED_TYPES] ?? null;

        if (!\is_array($accepted) || $accepted === []) {
            return AnswerValidity::valid();
        }

        $controlId = \is_string($constraints[self::CONSTRAINT_CONTROL_ID] ?? null)
            ? $constraints[self::CONSTRAINT_CONTROL_ID]
            : $this->key();
        $errors = [];

        foreach ($this->filesOf($value) as $file) {
            $mime = $file['mime'] ?? null;

            if (\is_string($mime) && !\in_array(strtolower($mime), $accepted, true)) {
                $errors[] = AnswerError::of(
                    $controlId,
                    ErrorKind::UnsupportedFileType,
                    $this->say(NeutralStrings::ERROR_UNSUPPORTED_FILE_TYPE),
                );
            }
        }

        return $errors === [] ? AnswerValidity::valid() : AnswerValidity::invalid($errors);
    }

    protected function shuffledFields(array $definition = []): array
    {
        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function filesOf(ResponseValue $value): array
    {
        $raw = $value->value;

        if (!\is_array($raw)) {
            return [];
        }

        if (isset($raw['mime'])) {
            /** @var array<string, mixed> $raw */
            return [$raw];
        }

        $files = [];

        foreach ($raw as $entry) {
            if (\is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $files[] = $entry;
            }
        }

        return $files;
    }
}
