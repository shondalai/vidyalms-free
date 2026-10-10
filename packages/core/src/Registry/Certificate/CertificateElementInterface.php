<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Certificate;

use Shondalai\VidyaLMS\Core\Registry\Context\ElementRenderContext;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementValidationContext;
use Shondalai\VidyaLMS\Core\Registry\Context\TemplateContext;
use Shondalai\VidyaLMS\Core\Registry\Context\UpgradeContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * One drawn thing on a certificate page.
 *
 * The split between this and the two host-owned tables is the load-bearing
 * decision of the whole area, and it is Moodle mod_customcert's own 5.2.0
 * correction of its 4.x schema taken for free: layout and ordering (posx,
 * posy, width, height, refpoint, alignment, sequence) are host columns
 * because the designer and the layout service own them, while font, size,
 * colour and every element-specific setting live inside the StoredShape
 * wrapped data column, because they belong to the element provider and a
 * shared column for them makes every new element a migration.
 *
 * isAvailable() is mod_customcert's can_add(): an element may refuse to be
 * offered on a template that lacks what it needs, which is how the credit
 * statement stays out of the palette on a template with no credit scheme.
 *
 * Everything optional is a narrow capability interface probed with
 * instanceof, never a method added here: ResolvesToText, RequiresAsset,
 * ContributesToCredential and RendersToPdf each state what their absence
 * means. A registry miss renders a visible placeholder naming the missing
 * package and never throws, because an unresolved renderer fails visible
 * while an unresolved gate fails closed.
 */
interface CertificateElementInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * The payload a newly added element starts with, unwrapped: StoredShape
     * adds the version on write and strips it on read.
     *
     * @return array<string, mixed>
     */
    public function defaultPayload(): array;

    /**
     * The authoring form, in the same settings-schema shape the quiz settings
     * route already serves, so one client renderer draws both.
     *
     * @return array<string, mixed>
     */
    public function formSchema(): array;

    public function isAvailable(TemplateContext $context): bool;

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<ValidationFinding>
     */
    public function validate(array $payload, ElementValidationContext $context): array;

    /**
     * @param array<string, mixed> $payload
     */
    public function renderHtml(array $payload, ElementRenderContext $context): string;

    /**
     * The payload version this provider writes today. A stored payload above
     * it is a miss and is treated exactly as a missing provider is.
     */
    public function currentShape(): int;

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function upgrade(array $payload, int $from, UpgradeContext $context): array;
}
