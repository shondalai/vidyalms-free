<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Certificate\Element;

use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateElementInterface;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ElementMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ValidationFinding;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementRenderContext;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementValidationContext;
use Shondalai\VidyaLMS\Core\Registry\Context\TemplateContext;
use Shondalai\VidyaLMS\Core\Registry\Context\UpgradeContext;

/**
 * What the seventeen free elements share, and nothing else.
 *
 * The typography block is shared because every text-bearing element on a
 * certificate has a font, a size, a weight and a colour, and seventeen
 * near-identical copies of the same four keys is how a payload grammar drifts
 * into seventeen dialects. It lives in the payload rather than in a column,
 * which is the whole point of the split: mod_customcert shipped font, size
 * and colour as dedicated columns and had to migrate them out.
 *
 * The defaults reach for the design tokens rather than hardcoded literals, so
 * a site that has themed the product gets a certificate that matches it, and
 * a site that has not gets the fallback in the same declaration.
 */
abstract class AbstractCertificateElement implements CertificateElementInterface, ElementMetadataInterface
{
    protected const DEFAULT_FONT_FAMILY = 'var(--vy-font-display, Georgia, "Times New Roman", serif)';
    protected const DEFAULT_COLOUR = 'var(--vy-accent, #24405c)';

    public function label(): string
    {
        return 'vidyalms.certificate_element.' . $this->key();
    }

    /**
     * Available everywhere unless an element says otherwise. Refusing is the
     * exception and it is always for a stated reason: an element that cannot
     * render anything useful on this template should not be in the palette.
     */
    public function isAvailable(TemplateContext $context): bool
    {
        return true;
    }

    public function validate(array $payload, ElementValidationContext $context): array
    {
        return [];
    }

    public function currentShape(): int
    {
        return 1;
    }

    /**
     * A payload at the version this provider writes needs no upgrade, and a
     * payload below it is handed back untouched here: an upgrader that
     * guesses is worse than one that declines, and the chain that cannot be
     * completed leaves the payload exactly as stored.
     */
    public function upgrade(array $payload, int $from, UpgradeContext $context): array
    {
        return $payload;
    }

    public function group(): string
    {
        return ElementMetadataInterface::GROUP_CONTENT;
    }

    public function order(): int
    {
        return 100;
    }

    /**
     * The typography every text-bearing element declares, in one place.
     *
     * @return array<string, mixed>
     */
    protected function typographyDefaults(int $size = 16, int $weight = 400): array
    {
        return [
            'font' => ['family' => self::DEFAULT_FONT_FAMILY, 'size' => $size, 'weight' => $weight],
            'colour' => self::DEFAULT_COLOUR,
            'line_height' => 1.4,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function typographySchema(): array
    {
        return [
            'font' => [
                'type' => 'object',
                'label' => 'vidyalms.certificate_element.field.font',
                'properties' => [
                    'family' => ['type' => 'string'],
                    'size' => ['type' => 'integer', 'minimum' => 4, 'maximum' => 200],
                    'weight' => ['type' => 'integer', 'minimum' => 100, 'maximum' => 900],
                ],
            ],
            'colour' => ['type' => 'string', 'label' => 'vidyalms.certificate_element.field.colour'],
            'line_height' => ['type' => 'number', 'label' => 'vidyalms.certificate_element.field.line_height'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function typographyStyle(array $payload): string
    {
        $font = $payload['font'] ?? [];
        $font = \is_array($font) ? $font : [];

        $family = \is_string($font['family'] ?? null) ? $font['family'] : self::DEFAULT_FONT_FAMILY;
        $size = \is_int($font['size'] ?? null) || \is_float($font['size'] ?? null) ? (int) $font['size'] : 16;
        $weight = \is_int($font['weight'] ?? null) ? $font['weight'] : 400;
        $colour = \is_string($payload['colour'] ?? null) ? $payload['colour'] : self::DEFAULT_COLOUR;
        $lineHeight = \is_int($payload['line_height'] ?? null) || \is_float($payload['line_height'] ?? null)
            ? (float) $payload['line_height']
            : 1.4;

        // Both strings are author-controlled payload and both land inside a
        // double-quoted style attribute, so a quote in either closes the
        // attribute and everything after it becomes markup. The class
        // escapes its text and did not escape these, which is an omission
        // rather than a decision.
        return sprintf(
            'font-family: %s; font-size: %dpt; font-weight: %d; color: %s; line-height: %s;',
            $this->escape($family),
            $size,
            $weight,
            $this->escape($colour),
            rtrim(rtrim(number_format($lineHeight, 2, '.', ''), '0'), '.'),
        );
    }

    /**
     * One absolutely positioned block, which is the only shape an element
     * ever emits. Elements do not choose their own position: the box came
     * from the layout service.
     */
    protected function block(ElementRenderContext $context, string $inner, string $extraStyle = ''): string
    {
        return '<div class="vy-cert-element" style="' . $context->box->style() . ' ' . $extraStyle . '">'
            . $inner
            . '</div>';
    }

    /**
     * What an element draws when its provider is installed and its value is
     * not there: never the empty string.
     *
     * The renderer's own rule is that a miss fails visible, "instead of a
     * certificate quietly losing a line", and eleven elements were returning
     * '' the moment a token was absent. That made installing a package
     * *remove* a line the free build had shown as a placeholder, which
     * inverts the whole direction: an author looking at the output could not
     * tell an element that decided to draw nothing from an element that was
     * never there.
     *
     * The two purposes get different answers because they are different
     * questions. In a preview the author is asking where things will sit, so
     * the absence is drawn as a visible note in the element's own box. On an
     * issued certificate the holder is not the audience for our diagnostics,
     * so the block is drawn empty: the box is present, the layout does not
     * shift, the reason is recorded on the element as a data attribute, and
     * nothing that is not a fact about the credential is printed on it.
     */
    protected function absent(ElementRenderContext $context, string $reason = 'no_value'): string
    {
        $attributes = ' data-vy-element="' . $this->escape($this->key()) . '"'
            . ' data-vy-empty="' . $this->escape($reason) . '"';

        if (!$context->isPreview()) {
            return '<div class="vy-cert-element vy-cert-element-empty" style="'
                . $context->box->style() . '"' . $attributes . '></div>';
        }

        return '<div class="vy-cert-element vy-cert-element-empty vy-cert-element-note" style="'
            . $context->box->style() . '"' . $attributes . '>'
            // The element key rather than prose, for the reason an
            // unresolved token is left intact in the output: the author is
            // looking at their own template and the key is what they
            // authored. A host that wants a sentence has both facts on the
            // element as data attributes.
            . $this->escape($this->key() . ' (' . $reason . ')')
            . '</div>';
    }

    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * The finding an element returns when a required piece of its payload is
     * missing. Errors refuse the save, and alt text is always an error: an
     * image with no alternative on an evidentiary page fails SC 1.1.1 and no
     * warning nobody reads will fix it.
     */
    protected function missing(string $field): ValidationFinding
    {
        return ValidationFinding::error(
            $this->key() . '.' . $field . '_required',
            'vidyalms.certificate_element.error.' . $field . '_required',
            ['element' => $this->key()],
        );
    }
}
