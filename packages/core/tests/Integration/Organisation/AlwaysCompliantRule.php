<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Compliance\ComplianceState;
use Shondalai\VidyaLMS\Core\Domain\Compliance\ComplianceStatus;
use Shondalai\VidyaLMS\Core\Domain\Compliance\ComplianceSubject;
use Shondalai\VidyaLMS\Core\Domain\Compliance\RenewalStatus;
use Shondalai\VidyaLMS\Core\Registry\ComplianceRule\ComplianceRuleInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\ComplianceContext;

/**
 * A third-party rule, of the smallest shape the seam supports.
 *
 * It answers `compliant` the moment a completion exists and `assigned`
 * otherwise, which is exactly the shape both Pro rules have and is what the
 * missing `assigned -> compliant` edge refused for ever. It publishes a closed
 * schema and a shape above one, so the two things the seam ignored, the
 * provider's schema and the provider's shape number, are both visible when they
 * are honoured.
 */
final class AlwaysCompliantRule implements ComplianceRuleInterface
{
    public const KEY = 'acme.always-compliant';

    /** Deliberately not one, because the seam stored the constant 1 for every rule. */
    public const SHAPE = 4;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'acme.compliance_rule.always';
    }

    public function currentShape(): int
    {
        return self::SHAPE;
    }

    /**
     * @return array<string, mixed>
     */
    public function paramsSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://acme.test/schema/compliance-rule/always/1',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['days'],
            'properties' => ['days' => ['type' => 'integer', 'minimum' => 1]],
        ];
    }

    public function evaluate(ComplianceSubject $subject, ComplianceContext $context): ComplianceState
    {
        $due = $context->now->modify('+30 days');

        if ($subject->completedAt === null) {
            return ComplianceState::of(ComplianceStatus::ASSIGNED, RenewalStatus::NOT_DUE, null, $due);
        }

        return ComplianceState::of(
            ComplianceStatus::COMPLIANT,
            RenewalStatus::NOT_DUE,
            null,
            $due,
            null,
            null,
            null,
            null,
            ['seen' => $subject->completedAt->format('Y-m-d')],
            self::SHAPE,
        );
    }
}
