<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\ListingPolicy;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * The certificate templates on this site, one page at a time.
 *
 * Counts and first-page artwork are loaded in batches for the selected page.
 * Thumbnails use the lightweight HTML renderer, never a PDF worker per card.
 *
 * Requires manage.
 */
final class ListCertificateTemplates
{
    public function __construct(
        private readonly CertificateTemplateRepository $templates,
        private readonly CertificateTemplateViews $views,
        private readonly AccessGuard $guard,
        private readonly ListingPolicy $policy,
        private readonly ?CertificateTemplateThumbnail $thumbnail = null,
    ) {
    }

    public function execute(
        Principal $principal,
        ?string $state = null,
        ?string $format = null,
        int $page = 1,
        int $perPage = 20,
        string $search = '',
        ?string $category = null,
        ?string $renderer = null,
    ): CertificateTemplateListing {
        $this->guard->require($principal, Capability::MANAGE);

        if ($state !== null && !\in_array($state, CertificateTemplateFields::STATES, true)) {
            throw ValidationException::forField('state', 'Unknown template state.');
        }

        if ($format !== null && !\in_array($format, CertificateTemplateFields::FORMATS, true)) {
            throw ValidationException::forField('format', 'Unknown template format.');
        }

        if ($page < 1) {
            throw ValidationException::forField('page', 'Must be 1 or more.');
        }

        if ($perPage < 1 || $perPage > $this->policy->maxPerPage) {
            throw ValidationException::forField(
                'per_page',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                sprintf('Must be between 1 and %d.', $this->policy->maxPerPage),
            );
        }

        if (mb_strlen($search) > 100 || mb_strlen($category ?? '') > 100 || mb_strlen($renderer ?? '') > 30) {
            throw ValidationException::forField('search', 'The filter length limit was exceeded.');
        }
        $rows = $this->templates->search($state, $format, $perPage, ($page - 1) * $perPage, $search, $category, $renderer);
        $counts = $this->templates->countsFor(array_map(
            static fn (array $row): int => (int) $row['id'],
            $rows,
        ));

        $firstPages = $this->thumbnail === null ? [] : $this->templates->firstPagesFor(array_values(array_map(
            static fn (array $row): int => (int) $row['id'],
            array_filter($rows, static fn (array $row): bool => ($row['format'] ?? '') === 'elements'),
        )));
        $items = [];

        foreach ($rows as $row) {
            $count = $counts[(int) $row['id']] ?? ['pages' => 0, 'elements' => 0];
            $row['thumbnail'] = $this->thumbnail?->render($row, $firstPages[(int) $row['id']] ?? null);
            $items[] = $this->views->summary($row, $count['pages'], $count['elements']);
        }

        return new CertificateTemplateListing(
            $items,
            $this->templates->countMatching($state, $format, $search, $category, $renderer),
            $page,
            $perPage,
        );
    }
}
