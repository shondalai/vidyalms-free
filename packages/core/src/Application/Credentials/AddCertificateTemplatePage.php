<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;

/**
 * Adds one sheet of paper to a template.
 *
 * Paper is a host column and not element data, because the layout service
 * reads it for every element on the page and no element implementation may
 * own it. Sizes are millimetres, which is what the renderers and every paper
 * standard agree on.
 *
 * Appended at the end unless the body names a sequence, and the whole set is
 * renumbered afterwards so the stored sequences stay 1 to N: a gap or a
 * duplicate makes the draw order the id order by accident.
 *
 * Requires manage.
 */
final class AddCertificateTemplatePage
{
    public function __construct(
        private readonly CertificateTemplateRepository $templates,
        private readonly CertificateTemplateAggregate $aggregate,
        private readonly CertificateTemplateViews $views,
        private readonly AccessGuard $guard,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return CertificateTemplateMutationResult<CertificateTemplatePageView>
     */
    public function execute(Principal $principal, int $templateId, array $body): CertificateTemplateMutationResult
    {
        $this->guard->require($principal, Capability::MANAGE);

        $precondition = new CertificateTemplateFields($body);
        $expectedRevision = $precondition->expectedRevision();
        $precondition->check();

        return $this->aggregate->mutate(
            $templateId,
            $expectedRevision,
            function (
                array $_template,
                array $pages,
                int $_revision,
            ) use (
                $body,
                $templateId,
            ): CertificateTemplatePageView {
                $fields = new CertificateTemplateFields($body);
                if (count($pages) >= CertificateStudio::MAX_PAGES) {
                    $fields->reject('pages', 'A certificate supports at most 8 pages.');
                }
                $sequence = $fields->optionalInt('sequence', 1);
                $position = $sequence ?? \count($pages) + 1;
                $paper = CertificateTemplatePaper::read(
                    $fields,
                    new CertificateTemplatePage(0, $templateId, $position),
                );

                $fields->check();

                $pageId = $this->templates->insertPage($paper);
                $this->templates->reorderPages($templateId, self::ordered($pages, $pageId, $position));

                return $this->views->page($this->aggregate->requirePage($pageId));
            },
        );
    }

    /**
     * The page ids in their new order, with the new page at the position the
     * body asked for.
     *
     * @param list<CertificateTemplatePage> $existing
     *
     * @return list<int>
     */
    private static function ordered(array $existing, int $newPageId, int $position): array
    {
        $ids = array_map(static fn (CertificateTemplatePage $page): int => $page->id, $existing);
        $index = max(0, min($position - 1, \count($ids)));

        array_splice($ids, $index, 0, [$newPageId]);

        return array_values($ids);
    }
}
