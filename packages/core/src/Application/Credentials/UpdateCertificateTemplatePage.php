<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;

/**
 * Updates one page of paper.
 *
 * A page is addressed by its own id and the revision lives on the template,
 * so the write walks back to the root before it decides anything: the
 * concurrency token, the lock and the audit trail are all the aggregate's and
 * none of them are the page's.
 *
 * Requires manage.
 */
final class UpdateCertificateTemplatePage
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
    public function execute(Principal $principal, int $pageId, array $body): CertificateTemplateMutationResult
    {
        $this->guard->require($principal, Capability::MANAGE);

        // This lookup determines which root must be claimed. Membership and
        // the page fields are resolved again after that claim.
        $routedPage = $this->aggregate->requirePage($pageId);
        $precondition = new CertificateTemplateFields($body);
        $expectedRevision = $precondition->expectedRevision();
        $precondition->check();

        return $this->aggregate->mutate(
            $routedPage->templateId,
            $expectedRevision,
            function (array $_template, array $pages, int $_revision) use ($body, $pageId): CertificateTemplatePageView {
                $page = $this->aggregate->claimedPage($pages, $pageId);
                $fields = new CertificateTemplateFields($body);
                $fields->requireMutation([
                    'sequence',
                    'width',
                    'height',
                    'orientation',
                    'margin_top',
                    'margin_right',
                    'margin_bottom',
                    'margin_left',
                    'background',
                ]);
                $sequence = $fields->optionalInt('sequence', 1);
                $paper = CertificateTemplatePaper::read($fields, $page);

                $fields->check();
                $this->templates->updatePage($paper);

                if ($sequence !== null && $sequence !== $page->sequence) {
                    $this->templates->reorderPages(
                        $page->templateId,
                        self::moved($pages, $page->id, $sequence),
                    );
                }

                return $this->views->page($this->aggregate->requirePage($pageId));
            },
        );
    }

    /**
     * The page ids with one of them moved to the position asked for.
     *
     * @param list<CertificateTemplatePage> $pages
     *
     * @return list<int>
     */
    private static function moved(array $pages, int $pageId, int $position): array
    {
        $ids = [];

        foreach ($pages as $page) {
            if ($page->id !== $pageId) {
                $ids[] = $page->id;
            }
        }

        $index = max(0, min($position - 1, \count($ids)));

        array_splice($ids, $index, 0, [$pageId]);

        return $ids;
    }
}
