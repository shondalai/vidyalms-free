<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateRendererRegistry;

/**
 * Partial update of the page-independent settings.
 *
 * Editing a template never rewrites a certificate already issued from it,
 * because issuance freezes the resolved template, its pages, its elements and
 * every resolved token value onto the certificate row and rendering reads
 * that snapshot. Correcting a live template and reissuing are two different
 * operations with two different audit trails, which is why this use case does
 * not offer to fix existing certificates.
 *
 * A change to expiry on a locked template is a conflict naming the lock: once
 * a credential has been awarded under a criteria set, that set and its expiry
 * are frozen and a change is a new revision, not an edit.
 *
 * Requires manage.
 */
final class UpdateCertificateTemplate
{
    public function __construct(
        private readonly CertificateTemplateRepository $templates,
        private readonly CertificateTemplateAggregate $aggregate,
        private readonly CertificateTemplateViews $views,
        private readonly CertificateRendererRegistry $renderers,
        private readonly CertificateExpiryRule $expiry,
        private readonly AccessGuard $guard,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public function execute(Principal $principal, int $templateId, array $body): CertificateTemplateView
    {
        $this->guard->require($principal, Capability::MANAGE);

        $precondition = new CertificateTemplateFields($body);
        $expectedRevision = $precondition->expectedRevision();
        $precondition->check();

        $mutation = $this->aggregate->mutate(
            $templateId,
            $expectedRevision,
            function (array $template, array $pages, int $_revision) use ($body, $templateId): CertificateTemplateView {
                $fields = new CertificateTemplateFields($body);
                $fields->requireMutation([
                    'name',
                    'template_key',
                    'renderer',
                    'format',
                    'achievement_type',
                    'body',
                    'locale',
                    'category',
                    'state',
                    'is_default',
                    'expiry_mode',
                    'expiry_days',
                    'expiry_at',
                ]);

                $changes = [];

                if ($fields->has('name')) {
                    $changes['name'] = $fields->requiredString('name', 255);
                }

                if ($fields->has('template_key')) {
                    $templateKey = $fields->nullableString('template_key', 100);
                    $existing = $templateKey === null ? null : $this->templates->findByKey($templateKey);

                    if ($existing !== null && (int) $existing['id'] !== $templateId) {
                        $fields->reject('template_key', 'Another template already uses this key.');
                    }

                    $changes['template_key'] = $templateKey;
                }

                if ($fields->has('renderer')) {
                    $renderer = $fields->optionalString('renderer', 30);

                    if ($renderer !== null && !$this->renderers->has($renderer)) {
                        $fields->reject('renderer', 'One of: ' . implode(', ', $this->renderers->keys()) . '.');
                    }

                    $changes['renderer'] = $renderer;
                }

                if ($fields->has('format')) {
                    $changes['format'] = $fields->optionalEnum('format', CertificateTemplateFields::FORMATS);
                }

                if ($fields->has('achievement_type')) {
                    $changes['achievement_type'] = $fields->optionalString('achievement_type', 50);
                }

                if ($fields->has('body')) {
                    $changes['body'] = $fields->nullableString('body', CreateCertificateTemplate::MAX_BODY) ?? '';
                }

                if ($fields->has('locale')) {
                    $changes['locale'] = $fields->nullableString('locale', 20);
                }
                if ($fields->has('category')) {
                    $changes['category'] = $fields->nullableString('category', 100);
                }

                if ($fields->has('state')) {
                    $changes['state'] = $fields->optionalEnum('state', CertificateTemplateFields::STATES);
                    if ($changes['state'] === CertificateTemplateFields::STATE_PUBLISHED) {
                        $fields->reject('state', 'Use the explicit publish operation.');
                    }
                }

                $isDefault = $fields->has('is_default') ? $fields->optionalBool('is_default') : null;

                if ($isDefault !== null) {
                    $changes['is_default'] = $isDefault ? 1 : 0;
                    $fields->reject('is_default', 'Use the explicit default-selection operation.');
                }

                $expiryChanges = $this->expiry->changesFor($fields, $template);
                $state = \is_string($changes['state'] ?? null)
                    ? $changes['state']
                    : (\is_string($template['state'] ?? null) ? $template['state'] : 'published');

                if ($isDefault === true && $state !== CertificateTemplateFields::STATE_PUBLISHED) {
                    $fields->reject('is_default', 'Only a published template may be the site default.');
                }

                if ($isDefault === false && (int) ($template['is_default'] ?? 0) === 1) {
                    $fields->reject('is_default', 'Make another template the default rather than clearing this one.');
                }

                if ($this->wouldUnpublishTheDefault($template, $changes)) {
                    $fields->reject('state', 'The site default cannot leave the published state.');
                }

                $fields->check();
                $this->aggregate->refuseLockedChange($template, self::differing($template, $expiryChanges));

                $changes = array_merge($changes, $expiryChanges);
                $this->templates->update($templateId, $changes);

                if ($isDefault === true) {
                    // Replaced by the cross-aggregate default CAS in Stage
                    // 6b. Kept here so the unregistered 6a contract does not
                    // change existing publication/default behaviour midway.
                    $this->templates->clearDefaultExcept($templateId);
                }

                $becameElements = ($changes['format'] ?? null) === CertificateTemplateFields::FORMAT_ELEMENTS;

                if ($becameElements && $pages === []) {
                    $this->templates->insertPage(new CertificateTemplatePage(0, $templateId));
                }

                return $this->views->detail($this->aggregate->require($templateId));
            },
        );

        if (!$mutation->value instanceof CertificateTemplateView) {
            throw new \RuntimeException('The certificate template update returned no detail.');
        }

        return $mutation->value;
    }

    /**
     * The columns this write would actually move.
     *
     * @param array<string, mixed> $template
     * @param array<string, mixed> $changes
     *
     * @return list<string>
     */
    private static function differing(array $template, array $changes): array
    {
        $moved = [];

        foreach ($changes as $column => $value) {
            $current = $template[$column] ?? null;

            // Both sides cross the port as strings or nulls, and a day count
            // read back as "30" is the same setting as the 30 that was sent.
            if (self::comparable($current) !== self::comparable($value)) {
                $moved[] = $column;
            }
        }

        return $moved;
    }

    private static function comparable(mixed $value): ?string
    {
        return $value === null ? null : (string) (\is_scalar($value) ? $value : serialize($value));
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $changes
     */
    private function wouldUnpublishTheDefault(array $template, array $changes): bool
    {
        if ((int) ($template['is_default'] ?? 0) !== 1) {
            return false;
        }

        $state = $changes['state'] ?? null;

        return \is_string($state) && $state !== CertificateTemplateFields::STATE_PUBLISHED;
    }
}
