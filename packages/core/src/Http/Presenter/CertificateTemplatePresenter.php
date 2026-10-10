<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Presenter;

use Shondalai\VidyaLMS\Core\Application\Credentials\CertificatePreviewResult;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateTemplateElementView;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateTemplateListing;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateTemplateMutationResult;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateTemplatePageView;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateTemplateView;
use Shondalai\VidyaLMS\Core\Http\Handler\Presenter;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationFinding;

/**
 * The wire shape of the three levels of a certificate template.
 *
 * A presenter of its own rather than three more methods on the credentials
 * one: the aggregate is the authoring surface's shape and a certificate is
 * the holder's, they change for different reasons, and a package decorating
 * one has no business seeing the other.
 *
 * Findings leave with their key and their parameters as well as a message, so
 * a host that translates them itself can, and one that does not still has
 * something to draw. The key is the contract and the message is a
 * convenience, which is the rule the whole reason-string design turns on.
 */
final class CertificateTemplatePresenter
{
    public const SHAPE_TEMPLATE = 'certificate_template';
    public const SHAPE_TEMPLATE_PAGE = 'certificate_template_page';
    public const SHAPE_TEMPLATE_ELEMENT = 'certificate_template_element';

    public function __construct(
        private readonly Decorated $decorated,
        /**
         * Absent, a finding leaves carrying its key as its message, which is
         * what a host with no catalogue would have shown anyway. It is never
         * an English sentence assembled here.
         */
        private readonly ?TranslatorPort $translator = null,
    ) {
    }

    /**
     * The list shape: the row and the two counts, without the aggregate.
     *
     * @return array<string, mixed>
     */
    public function template(CertificateTemplateView $view): array
    {
        $row = $view->row;

        return $this->decorated->apply(self::SHAPE_TEMPLATE, [
            'id' => $view->id(),
            'template_key' => self::nullableString($row['template_key'] ?? null),
            'name' => self::string($row['name'] ?? null),
            'renderer' => self::string($row['renderer'] ?? null, 'html'),
            'format' => self::string($row['format'] ?? null, 'html'),
            'achievement_type' => self::string($row['achievement_type'] ?? null, 'CertificateOfCompletion'),
            'revision' => $view->revision(),
            'published_revision' => isset($row['published_revision']) ? (int) $row['published_revision'] : null,
            'published_at' => self::nullableString($row['published_at'] ?? null),
            'has_unpublished_changes' => (int) ($row['has_unpublished_changes'] ?? 1) === 1,
            'category' => self::nullableString($row['category'] ?? null),
            'provenance' => self::document($row['provenance'] ?? null),
            'schema_version' => self::int($row['schema_version'] ?? null, 1),
            'locale' => self::nullableString($row['locale'] ?? null),
            'state' => self::string($row['state'] ?? null, 'published'),
            'is_default' => (int) ($row['is_default'] ?? 0) === 1,
            'expiry_mode' => self::string($row['expiry_mode'] ?? null, 'never'),
            'expiry_days' => isset($row['expiry_days']) && is_numeric($row['expiry_days'])
                ? (int) $row['expiry_days']
                : null,
            'expiry_at' => self::nullableString($row['expiry_at'] ?? null),
            'locked_at' => self::nullableString($row['locked_at'] ?? null),
            // The lock is a fact of the read and not only of the refusal: a
            // screen that has to discover it by attempting a save offers an
            // author a control that cannot work.
            'locked_fields' => $view->lockedFields,
            'page_count' => $view->pageCount,
            'element_count' => $view->elementCount,
            'thumbnail' => $row['thumbnail'] ?? null,
        ], $view);
    }

    /**
     * The whole aggregate: the template level, its body, its pages and what
     * the template-level checks found.
     *
     * @return array<string, mixed>
     */
    public function templateDetail(CertificateTemplateView $view): array
    {
        $body = $view->row['body'] ?? null;
        $format = self::string($view->row['format'] ?? null, 'html');

        return array_merge($this->template($view), [
            'body' => $format === 'elements' || !\is_string($body) || $body === '' ? null : $body,
            'pages' => array_map(
                fn (CertificateTemplatePageView $page): array => $this->page($page),
                $view->pages,
            ),
            'default_selection' => $view->row['default_selection'] ?? ['id' => null, 'revision' => null],
            'findings' => array_map(
                fn (PublicationFinding $finding): array => $this->finding($finding),
                $view->findings,
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function listing(CertificateTemplateListing $listing): array
    {
        return [
            'items' => array_map(
                fn (CertificateTemplateView $view): array => $this->template($view),
                $listing->items,
            ),
            'total' => $listing->total,
            'page' => $listing->page,
            'per_page' => $listing->perPage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function page(CertificateTemplatePageView $view): array
    {
        $page = $view->page;

        return $this->decorated->apply(self::SHAPE_TEMPLATE_PAGE, [
            'id' => $page->id,
            'template_id' => $page->templateId,
            'sequence' => $page->sequence,
            'width' => $page->width === floor($page->width) ? (int) $page->width : $page->width,
            'height' => $page->height === floor($page->height) ? (int) $page->height : $page->height,
            'orientation' => $page->orientation,
            'margin_top' => $page->marginTop,
            'margin_right' => $page->marginRight,
            'margin_bottom' => $page->marginBottom,
            'margin_left' => $page->marginLeft,
            'background' => $page->background,
            'elements' => array_map(
                fn (CertificateTemplateElementView $element): array => $this->element($element),
                $view->elements,
            ),
        ], $view);
    }

    /**
     * @return array<string, mixed>
     */
    public function element(CertificateTemplateElementView $view): array
    {
        $element = $view->element;

        return $this->decorated->apply(self::SHAPE_TEMPLATE_ELEMENT, [
            'id' => $element->id,
            'page_id' => $element->pageId,
            'sequence' => $element->sequence,
            'element' => $element->element,
            'name' => $element->name,
            // Declared free-form by the contract, so an empty payload has to
            // leave as an object rather than as the list PHP would encode.
            'data' => Presenter::freeFormObject($element->data),
            'data_version' => $element->dataVersion,
            'current_version' => $view->currentVersion,
            'available' => $view->available,
            'package' => $view->package,
            'posx' => $element->posX,
            'posy' => $element->posY,
            'width' => $element->width,
            'height' => $element->height,
            'refpoint' => $element->refPoint,
            'alignment' => $element->alignment,
        ], $view);
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(CertificatePreviewResult $preview): array
    {
        return [
            'media_type' => $preview->mediaType,
            'html' => $preview->html,
            'data_url' => $preview->dataUrl,
            'findings' => array_map(
                fn (PublicationFinding $finding): array => $this->finding($finding),
                $preview->findings,
            ),
            'unresolved_tokens' => $preview->unresolvedTokens,
        ];
    }

    /**
     * @param CertificateTemplateMutationResult<CertificateTemplatePageView> $result
     * @return array<string, mixed>
     */
    public function pageMutation(CertificateTemplateMutationResult $result): array
    {
        return ['template_revision' => $result->revision, 'item' => $this->page($result->value)];
    }

    /**
     * @param CertificateTemplateMutationResult<CertificateTemplateElementView> $result
     * @return array<string, mixed>
     */
    public function elementMutation(CertificateTemplateMutationResult $result): array
    {
        return ['template_revision' => $result->revision, 'item' => $this->element($result->value)];
    }

    /**
     * @param CertificateTemplateMutationResult<list<CertificateTemplatePageView>> $result
     * @return array<string, mixed>
     */
    public function pagesMutation(CertificateTemplateMutationResult $result): array
    {
        return [
            'template_revision' => $result->revision,
            'items' => array_map($this->page(...), $result->value),
        ];
    }

    /**
     * @param CertificateTemplateMutationResult<list<CertificateTemplateElementView>> $result
     * @return array<string, mixed>
     */
    public function elementsMutation(CertificateTemplateMutationResult $result): array
    {
        return [
            'template_revision' => $result->revision,
            'items' => array_map($this->element(...), $result->value),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function finding(PublicationFinding $finding): array
    {
        return [
            'severity' => $finding->isBlocking() ? 'error' : 'warning',
            'key' => $finding->messageKey,
            'message' => $this->translator === null
                ? $finding->messageKey
                : $this->translator->translate($finding->messageKey, $finding->parameters),
            // The checks are per template and per element key rather than per
            // element row, so nothing here can honestly name an element id.
            'element_id' => null,
            'parameters' => Presenter::freeFormObject($finding->parameters),
        ];
    }

    private static function string(mixed $value, string $fallback = ''): string
    {
        return \is_string($value) && $value !== '' ? $value : $fallback;
    }

    /** @return array<string, mixed>|null */
    private static function document(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        return is_array($value) ? $value : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    private static function int(mixed $value, int $fallback): int
    {
        return is_numeric($value) ? (int) $value : $fallback;
    }
}
