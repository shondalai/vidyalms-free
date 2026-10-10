<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Certificate\Element;

use Shondalai\VidyaLMS\Core\Registry\Certificate\ContributesToCredential;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ElementMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ProvidesCredentialValue;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ResolvesToText;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementRenderContext;
use Shondalai\VidyaLMS\Core\Registry\Context\TemplateContext;
use Shondalai\VidyaLMS\Core\Provider\Token\CreditTokenResolver;

/**
 * The credit this completion carries, which is the line a state board reads.
 *
 * It is here rather than in Pro because the free contact_hours scheme, the
 * free course_credits table and the free credit tokens would otherwise all
 * feed an element nobody could draw, which is the empty-seam failure the
 * free-provider rule exists to prevent.
 *
 * It refuses to be offered on a template no course carries credit under,
 * which is mod_customcert can_add() doing its job: an element that renders an
 * empty line on every certificate a site issues is worse than an element that
 * is not in the palette.
 */
final class CreditStatementElement extends AbstractCertificateElement implements
    ResolvesToText,
    ContributesToCredential,
    ProvidesCredentialValue
{
    public function key(): string
    {
        return 'credit_statement';
    }

    public function group(): string
    {
        return ElementMetadataInterface::GROUP_COMPLIANCE;
    }

    public function order(): int
    {
        return 10;
    }

    public function credentialProperty(): string
    {
        // AchievementSubject.creditsEarned, which is credentialSubject and
        // never credentialSubject.achievement: Open Badges 3.0's Achievement
        // class defines no such term, so a claim there is dropped or errors
        // under JSON-LD expansion while the standards slot stays empty.
        return 'credentialSubject.creditsEarned';
    }

    /**
     * The amount as a number, because the standard declares one and this
     * element prints a sentence. On a course carrying credit under two
     * schemes the printed line names both; the numeric property names the
     * first, which is what the format's own arithmetic already does.
     */
    public function credentialValue(array $payload, ElementRenderContext $context): ?float
    {
        $rows = CreditTokenResolver::decodeRows($context->token(CreditTokenResolver::TOKEN_ROWS));
        $amount = $rows === [] ? $context->token('credit.amount') : $rows[0]['amount'];

        return $amount === null || !is_numeric($amount) ? null : (float) $amount;
    }

    public function isAvailable(TemplateContext $context): bool
    {
        return $context->hasAnyCreditScheme();
    }

    public function defaultPayload(): array
    {
        return $this->typographyDefaults(12) + [
            'template' => '{credit.amount} {credit.unit}',
            'show_field_of_study' => true,
        ];
    }

    public function formSchema(): array
    {
        return [
            'fields' => $this->typographySchema() + [
                'template' => ['type' => 'string', 'label' => 'vidyalms.certificate_element.field.credit_template'],
                'show_field_of_study' => [
                    'type' => 'boolean',
                    'label' => 'vidyalms.certificate_element.field.show_field_of_study',
                ],
            ],
        ];
    }

    /**
     * One line per credit row, not one line for the first of them.
     *
     * A completion really can carry credit under two schemes: that is why
     * certificate_credits is a child table and why compliance reporting
     * groups by scheme. The singular {credit.*} tokens answer the first row
     * and keep answering it, so an already-authored template renders exactly
     * what it rendered before on a single-scheme course; on a two-scheme
     * course the same template now states both, because a certificate that
     * silently drops one is a compliance defect on the document NASBA says is
     * typically the only one a state board reviews.
     */
    public function resolveText(array $payload, ElementRenderContext $context): ?string
    {
        $template = $payload['template'] ?? null;
        $template = \is_string($template) && $template !== '' ? $template : '{credit.amount} {credit.unit}';
        $showField = ($payload['show_field_of_study'] ?? true) !== false;

        $rows = CreditTokenResolver::decodeRows($context->token(CreditTokenResolver::TOKEN_ROWS));

        if ($rows === []) {
            $amount = $context->token('credit.amount');

            if ($amount === null || $amount === '') {
                return null;
            }

            return $this->line($context->resolveText($template), $context->token('credit.field_of_study'), $showField);
        }

        $lines = [];

        foreach ($rows as $row) {
            // This row's own values first, then the shared resolution for
            // anything else the template names. The other way round the
            // singular tokens are already gone and every line repeats the
            // first row.
            $lines[] = $this->line(
                $context->resolveText(strtr($template, [
                    '{credit.amount}' => $row['amount'],
                    '{credit.unit}' => $row['unit'],
                    '{credit.scheme}' => $row['scheme'],
                    '{credit.field_of_study}' => $row['field_of_study'],
                ])),
                $row['field_of_study'],
                $showField,
            );
        }

        return implode('; ', $lines);
    }

    private function line(string $text, ?string $field, bool $showField): string
    {
        if ($showField && $field !== null && $field !== '') {
            $text .= ' (' . $field . ')';
        }

        return $text;
    }

    public function renderHtml(array $payload, ElementRenderContext $context): string
    {
        $text = $this->resolveText($payload, $context);

        if ($text === null && !$context->isPreview()) {
            return $this->absent($context, 'no_credit');
        }

        return $this->block(
            $context,
            '<span class="vy-cert-credit">' . $this->escape($text ?? '') . '</span>',
            $this->typographyStyle($payload),
        );
    }
}
