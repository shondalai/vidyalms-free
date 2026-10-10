<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Provider\Certificate\CoreCertificateBlueprints;
use Shondalai\VidyaLMS\Core\Provider\Certificate\PdfFallbackCertificateRenderer;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateRendererInterface;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateRendererRegistry;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateTemplateBlueprint;
use Shondalai\VidyaLMS\Core\Registry\Certificate\InspectsCertificateSource;
use Shondalai\VidyaLMS\Core\Registry\Certificate\SupportsTemplateFormats;
use Shondalai\VidyaLMS\Core\Registry\Context\CertificateRenderContext;

/** Publication and cloning are explicit operations, never editable flags. */
final class CertificateStudio
{
    public const MAX_PAGES = 8;
    public const MAX_ELEMENTS_PER_PAGE = 100;

    public function __construct(
        private readonly CertificateTemplateRepository $templates,
        private readonly CertificateTemplateAggregate $aggregate,
        private readonly CertificateTemplateViews $views,
        private readonly CertificateTemplateChecks $checks,
        private readonly CertificateRendererRegistry $renderers,
        private readonly CoreCertificateBlueprints $blueprints,
        private readonly AccessGuard $guard,
        private readonly ClockPort $clock,
        private readonly TranslatorPort $translator,
    ) {
    }

    /** @return array<string, mixed> */
    public function catalogue(Principal $principal): array
    {
        $this->guard->require($principal, Capability::MANAGE);

        return ['items' => array_map(function (CertificateTemplateBlueprint $blueprint): array {
            return [
                'key' => $blueprint->key,
                'version' => $blueprint->version,
                'name' => $this->translator->translate($blueprint->nameKey),
                'description' => $this->translator->translate($blueprint->descriptionKey),
                'category' => $this->translator->translate($blueprint->categoryKey),
                'category_key' => $blueprint->categoryKey,
                'paper' => $blueprint->paperPreset,
                'source_digest' => $blueprint->sourceDigest,
                'format' => 'elements',
                'required_providers' => $blueprint->requiredElements,
                'preview_html' => $this->blueprintPreview($blueprint),
            ];
        }, $this->blueprints->all()), 'renderer_options' => $this->rendererOptions()];
    }

    /** @return list<array{key: string, label: string, formats: list<string>}> */
    public function rendererOptions(): array
    {
        $options = [];
        foreach ($this->renderers->keys() as $key) {
            $renderer = $this->renderers->get($key);
            if ($renderer instanceof PdfFallbackCertificateRenderer) {
                // It only keeps designs that already name "pdf" issuing, and
                // it refuses publication, so offering it would hand an author
                // a choice that cannot be published.
                continue;
            }
            $options[] = ['key' => $key, 'label' => $renderer->label(), 'formats' => self::formatsOf($renderer)];
        }

        return $options;
    }

    /**
     * Called only after the HTTP atomic transaction has rolled back. A fresh
     * read then distinguishes a competing unique-slot winner from a storage
     * failure without relying on database-specific error text or SQL states.
     * @param array<string, mixed> $body
     */
    public function defaultChanged(array $body): bool
    {
        $current = $this->templates->findPublishedDefault();
        return ($current === null ? null : (int) $current['id']) !== ($body['expected_default_id'] ?? null)
            || ($current === null ? null : (int) $current['revision']) !== ($body['expected_default_revision'] ?? null);
    }

    /**
     * Read-only assignment metadata. Draft changes never leak into the picker
     * for the last published design; missing providers remain visible.
     * @param array<string, mixed>|null $row
     * @return array{
     *   id: ?int, name: ?string, published_revision: ?int, renderer: ?string,
     *   format: ?string, is_default: bool, available: bool, diagnostic: ?string
     * }
     */
    public function publishedSummary(?array $row): array
    {
        $source = $row;
        $diagnostic = $row === null || ($row['state'] ?? '') !== 'published'
            ? 'certificate_policy.template_unavailable' : null;
        if ($row !== null && !empty($row['published_summary'])) {
            try {
                $summary = json_decode((string) $row['published_summary'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($summary)) {
                    throw new \UnexpectedValueException('The published summary is not an object.');
                }
                $source = $summary;
            } catch (\Throwable) {
                $diagnostic = 'certificate_policy.snapshot_unavailable';
            }
        }
        if ($source !== null && $diagnostic === null) {
            try {
                $this->validateRenderer($source);
            } catch (ValidationException) {
                $diagnostic = 'certificate_policy.renderer_unavailable';
            }
        }
        return [
            'id' => $row === null ? null : (int) $row['id'],
            'name' => $source === null ? null : (string) $source['name'],
            'published_revision' => $source === null ? null : (int) ($source['revision'] ?? 1),
            'renderer' => $source === null ? null : (string) $source['renderer'],
            'format' => $source === null ? null : (string) $source['format'],
            'is_default' => (int) ($row['is_default'] ?? 0) === 1,
            'available' => $diagnostic === null,
            'diagnostic' => $diagnostic,
        ];
    }

    /** @param array<string, mixed> $body */
    public function publish(Principal $principal, int $id, array $body): CertificateTemplateView
    {
        $this->guard->require($principal, Capability::MANAGE);
        $fields = new CertificateTemplateFields($body);
        $revision = $fields->expectedRevision();
        $fields->check();
        $this->aggregate->mutate($id, $revision, function (array $row, array $pages, int $claimed) use ($principal, $id): void {
            if (($row['state'] ?? '') === 'archived') {
                throw ValidationException::forField('state', 'Clone an archived template before publishing.');
            }
            $this->validatePublication($row, $pages);
            $renderer = $this->renderers->get((string) $row['renderer']);
            $provenance = $renderer instanceof InspectsCertificateSource ? $renderer->renderProvenance() : null;
            $row['render_provenance'] = $provenance;
            if ($renderer instanceof InspectsCertificateSource) {
                $row = array_merge($row, $renderer->prepareSource($row, $pages));
            }
            $row['revision'] = $claimed;
            $this->templates->update($id, [
                'state' => 'published',
                'published_snapshot' => json_encode(TemplateSnapshot::freezeTemplate($row, $pages), JSON_THROW_ON_ERROR),
                'published_summary' => json_encode([
                    'name' => $row['name'], 'renderer' => $row['renderer'],
                    'format' => $row['format'], 'revision' => $claimed,
                ], JSON_THROW_ON_ERROR),
                'published_name' => $row['name'],
                'published_revision' => $claimed,
                'has_unpublished_changes' => 0,
                'published_at' => $this->clock->now()->format('Y-m-d H:i:s'),
                'published_by' => $principal->userId,
                'render_provenance' => $provenance === null ? null : json_encode($provenance, JSON_THROW_ON_ERROR),
            ]);
        });

        return $this->views->detail($this->aggregate->require($id));
    }

    /**
     * The default race claims both roots in increasing order. The nullable
     * unique slot independently forbids two winners at the database boundary.
     * @param array<string, mixed> $body
     */
    public function selectDefault(Principal $principal, int $id, array $body): CertificateTemplateView
    {
        $this->guard->require($principal, Capability::MANAGE);
        $fields = new CertificateTemplateFields($body);
        $revision = $fields->expectedRevision();
        $previousId = $fields->nullableInt('expected_default_id', 1);
        $previousRevision = $fields->nullableInt('expected_default_revision', 1);
        if (!$fields->has('expected_default_id') || !$fields->has('expected_default_revision')) {
            $fields->reject('expected_default_id', 'State the default identity and revision observed by the author.');
        }
        if (($previousId === null) !== ($previousRevision === null)) {
            $fields->reject('expected_default_revision', 'The default identity and revision must both be null or both positive.');
        }
        $fields->check();
        $this->aggregate->write(function () use ($id, $revision, $previousId, $previousRevision): void {
            $observed = $this->templates->findPublishedDefault();
            if (
                ($observed === null ? null : (int) $observed['id']) !== $previousId
                || ($observed === null ? null : (int) $observed['revision']) !== $previousRevision
            ) {
                throw new ConflictException('The site default changed. Refresh before selecting a replacement.');
            }
            $claims = [$id => $revision];
            if ($previousId !== null && $previousId !== $id) {
                $claims[$previousId] = (int) $previousRevision;
            }
            ksort($claims, SORT_NUMERIC);
            foreach ($claims as $templateId => $expected) {
                $before = $this->aggregate->require($templateId);
                $dirty = empty($before['published_snapshot']) ? 0 : (int) $before['has_unpublished_changes'];
                $this->aggregate->mutate($templateId, $expected, function () use ($templateId, $dirty): void {
                    $this->templates->update($templateId, ['has_unpublished_changes' => $dirty]);
                });
            }
            $target = $this->aggregate->require($id);
            if (($target['state'] ?? '') !== 'published' || empty($target['published_snapshot'])) {
                throw ValidationException::forField('template_id', 'The site default must have a published design.');
            }
            $published = self::publishedBody($target);
            $this->validatePublication($published, TemplateSnapshot::pagesOf(self::snapshot($target)));
            if ($previousId !== null && $previousId !== $id) {
                $this->templates->update($previousId, ['is_default' => 0, 'active_default_slot' => null]);
            }
            $this->templates->update($id, ['is_default' => 1, 'active_default_slot' => 'site']);
        });

        return $this->views->detail($this->aggregate->require($id));
    }

    /** @param array<string, mixed> $body */
    public function cloneBlueprint(Principal $principal, string $key, array $body): CertificateTemplateView
    {
        $this->guard->require($principal, Capability::MANAGE);
        foreach ($this->blueprints->all() as $blueprint) {
            if ($blueprint->key !== $key) {
                continue;
            }
            if (($body['version'] ?? null) !== $blueprint->version) {
                throw new ConflictException('The requested blueprint version is unavailable.');
            }
            $source = $blueprint->source;
            $source['category'] = $blueprint->categoryKey;

            return $this->cloneSource($source, $body, [
                'blueprint_key' => $key,
                'blueprint_version' => $blueprint->version,
                'source_digest' => $blueprint->sourceDigest,
            ]);
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        throw EntityNotFoundException::for('Certificate blueprint', $key);
    }

    /** @param array<string, mixed> $body */
    public function clonePublished(Principal $principal, int $id, array $body): CertificateTemplateView
    {
        $this->guard->require($principal, Capability::MANAGE);
        $source = $this->publishedForIssuance($id);
        if (($body['published_revision'] ?? null) !== (int) $source['revision']) {
            throw new ConflictException('The requested published revision is unavailable.');
        }

        return $this->cloneSource($source, $body, [
            'source_template_id' => $id,
            'published_revision' => (int) $source['revision'],
            'source_digest' => hash('sha256', json_encode($source, JSON_THROW_ON_ERROR)),
        ]);
    }

    /** @return array<string, mixed> */
    public function publishedForIssuance(int $id): array
    {
        $row = $this->aggregate->require($id);
        if (($row['state'] ?? '') !== 'published') {
            throw ValidationException::forField('template_id', 'The assigned certificate template is not published.');
        }
        if (empty($row['published_snapshot'])) {
            // The first issue adopts legacy source under the same root lock
            // that protects edits; it cannot freeze half of an edit.
            $this->aggregate->mutate($id, (int) $row['revision'], function () use ($id): void {
                $this->templates->update($id, ['has_unpublished_changes' => 0]);
            });
            $row = $this->aggregate->require($id);
        }
        $snapshot = self::snapshot($row);
        $published = TemplateSnapshot::templateOf($snapshot);
        $this->validateRenderer($published);

        return $published;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function snapshot(array $row): array
    {
        $snapshot = $row['published_snapshot'] ?? null;
        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
        }
        if (!is_array($snapshot)) {
            throw ValidationException::forField('template_id', 'The published design is unavailable.');
        }
        return $snapshot;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function publishedBody(array $row): array
    {
        return TemplateSnapshot::templateOf(self::snapshot($row));
    }

    /** @param array<string, mixed> $row */
    private function validateRenderer(array $row): void
    {
        $key = (string) ($row['renderer'] ?? 'html');
        if (!$this->renderers->has($key)) {
            throw ValidationException::forField('renderer', 'The selected renderer is unavailable.');
        }
        if (!in_array($row['format'] ?? 'html', self::formatsOf($this->renderers->get($key)), true)) {
            throw ValidationException::forField('renderer', 'The renderer cannot consume this template format.');
        }
    }

    /**
     * The formats a renderer consumes, with the capability's stated default
     * for one that does not declare them.
     *
     * Treating the absent capability as "consumes nothing" made a renderer
     * written against the shipped CertificateRendererInterface alone
     * unpublishable, which is a shipped interface being narrowed after the
     * fact by a capability added beside it.
     *
     * @return list<string>
     */
    private static function formatsOf(CertificateRendererInterface $renderer): array
    {
        return $renderer instanceof SupportsTemplateFormats
            ? $renderer->supportedTemplateFormats()
            : SupportsTemplateFormats::DEFAULT_FORMATS;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<CertificateTemplatePage> $pages
     */
    private function validatePublication(array $row, array $pages): void
    {
        $this->validateRenderer($row);
        if (($row['format'] ?? 'html') === 'elements') {
            if ($pages === [] || count($pages) > self::MAX_PAGES) {
                throw ValidationException::forField('pages', 'A certificate needs between 1 and 8 pages.');
            }
            foreach ($pages as $page) {
                if ($page->elements === [] || count($page->elements) > self::MAX_ELEMENTS_PER_PAGE) {
                    throw ValidationException::forField('pages', 'Every page needs between 1 and 100 elements.');
                }
                if (
                    $page->width < 10 || $page->height < 10 || $page->width > 1000 || $page->height > 1000
                    || $page->marginLeft + $page->marginRight >= $page->width
                    || $page->marginTop + $page->marginBottom >= $page->height
                ) {
                    throw ValidationException::forField('pages', 'Page geometry has no usable paper area.');
                }
            }
        } else {
            $body = (string) ($row['body'] ?? '');
            if (
                // Portable core extracts text on both CMS hosts; HTML output is escaped separately by the host or React.
                // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
                trim(strip_tags($body)) === '' || (!str_contains($body, '{verification_url}')
                && !str_contains($body, '{certificate.verification_url}'))
            ) {
                throw ValidationException::forField('body', 'A certificate needs meaningful text and a readable verification URL.');
            }
        }
        foreach ($this->checks->run($row, $pages) as $finding) {
            if ($finding->isBlocking()) {
                throw ValidationException::forField(
                    'publication',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $this->translator->translate($finding->messageKey, $finding->parameters)
                );
            }
        }
        $renderer = $this->renderers->get((string) $row['renderer']);
        if ($renderer instanceof InspectsCertificateSource) {
            foreach ($renderer->sourceFindings($row, $pages) as $finding) {
                if ($finding->blocks()) {
                    throw ValidationException::forField(
                        'publication',
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                        $this->translator->translate($finding->messageKey, $finding->parameters)
                    );
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $body
     * @param array<string, mixed> $provenance
     */
    private function cloneSource(array $source, array $body, array $provenance): CertificateTemplateView
    {
        $fields = new CertificateTemplateFields($body);
        $name = $fields->requiredString('name', 255);
        $renderer = $fields->optionalString('renderer', 30) ?? (string) $source['renderer'];
        $fields->check();
        $source['renderer'] = $renderer;
        $this->validateRenderer($source);
        $id = $this->aggregate->write(function () use ($name, $source, $provenance, $renderer): int {
            $id = $this->templates->insert(
                $name,
                $renderer,
                (string) ($source['body'] ?? ''),
                false,
                'draft',
                null,
                (string) $source['format'],
            );
            $this->templates->update($id, [
                'locale' => $source['locale'] ?? null,
                'achievement_type' => $source['achievement_type'] ?? 'CertificateOfCompletion',
                'expiry_mode' => $source['expiry_mode'] ?? 'never',
                'expiry_days' => $source['expiry_days'] ?? null,
                'expiry_at' => $source['expiry_at'] ?? null,
                'category' => $source['category'] ?? null,
                'provenance' => json_encode($provenance, JSON_THROW_ON_ERROR),
            ]);
            $pages = $source['pages'] ?? [];
            if (!is_array($pages) || count($pages) > self::MAX_PAGES) {
                throw ValidationException::forField('pages', 'The source page limit was exceeded.');
            }
            foreach ($pages as $pageSource) {
                if (!is_array($pageSource)) {
                    throw ValidationException::forField('pages', 'The source contains an invalid page.');
                }
                $page = CertificateTemplatePage::fromSnapshot($pageSource);
                $pageId = $this->templates->insertPage(new CertificateTemplatePage(
                    0,
                    $id,
                    $page->sequence,
                    $page->width,
                    $page->height,
                    $page->orientation,
                    $page->marginTop,
                    $page->marginRight,
                    $page->marginBottom,
                    $page->marginLeft,
                    $page->background,
                ));
                if (count($page->elements) > self::MAX_ELEMENTS_PER_PAGE) {
                    throw ValidationException::forField('elements', 'The source element limit was exceeded.');
                }
                foreach ($page->elements as $element) {
                    $this->templates->insertElement($pageId, new CertificateTemplateElement(
                        0,
                        $pageId,
                        $element->element,
                        $element->data,
                        $element->dataVersion,
                        $element->sequence,
                        $element->name,
                        $element->posX,
                        $element->posY,
                        $element->width,
                        $element->height,
                        $element->refPoint,
                        $element->alignment,
                    ));
                }
            }
            return $id;
        });

        return $this->views->detail($this->aggregate->require($id));
    }

    private function blueprintPreview(CertificateTemplateBlueprint $blueprint): string
    {
        $pages = array_map(CertificateTemplatePage::fromSnapshot(...), $blueprint->source['pages']);
        return $this->renderers->get('elements')->render(CertificateRenderContext::create(
            [],
            $blueprint->source,
            [
                'site.name' => 'VidyaLMS Academy', 'user.full_name' => 'Alexandra Morgan',
                'course.title' => 'Leadership & Professional Excellence',
                'certificate.issued_at' => '5 September 2026', 'certificate.code_display' => 'PREVIEW-0000',
                'certificate.verification_url' => 'https://example.invalid/verify/PREVIEW-0000',
            ],
            (string) $blueprint->source['locale'],
            'UTC',
            $pages,
            'https://example.invalid/verify/PREVIEW-0000',
            CertificateRenderContext::PURPOSE_PREVIEW,
        ));
    }
}
