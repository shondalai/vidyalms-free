<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;

/**
 * Moves, resizes or reconfigures one element.
 *
 * A drag is a patch of posx and posy alone and a settings change is a patch
 * of the payload alone; both arrive here, because the alternative is a
 * designer that has to know which of two routes a given gesture belongs to.
 *
 * The payload is replaced wholesale rather than merged, so removing a field
 * is expressible, and it is rewrapped at the version given. A payload above
 * the version the provider declares is refused rather than stored and read
 * back as something the evaluator cannot understand.
 *
 * Requires manage.
 */
final class UpdateCertificateTemplateElement
{
    public function __construct(
        private readonly CertificateTemplateRepository $templates,
        private readonly CertificateTemplateAggregate $aggregate,
        private readonly CertificateTemplateViews $views,
        private readonly CertificateElementRule $rule,
        private readonly AccessGuard $guard,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return CertificateTemplateMutationResult<CertificateTemplateElementView>
     */
    public function execute(Principal $principal, int $elementId, array $body): CertificateTemplateMutationResult
    {
        $this->guard->require($principal, Capability::MANAGE);

        $routedElement = $this->aggregate->requireElement($elementId);
        $routedPage = $this->aggregate->requirePage($routedElement->pageId);
        $precondition = new CertificateTemplateFields($body);
        $expectedRevision = $precondition->expectedRevision();
        $precondition->check();

        return $this->aggregate->mutate(
            $routedPage->templateId,
            $expectedRevision,
            function (
                array $template,
                array $pages,
                int $_revision,
            ) use (
                $body,
                $elementId,
            ): CertificateTemplateElementView {
                $stored = $this->aggregate->claimedElement($pages, $elementId);
                $page = $this->aggregate->claimedPage($pages, $stored->pageId);
                $fields = new CertificateTemplateFields($body);
                $fields->requireMutation([
                    'name',
                    'data',
                    'data_version',
                    'sequence',
                    'posx',
                    'posy',
                    'width',
                    'height',
                    'refpoint',
                    'alignment',
                ]);
                $element = $this->rule->read($fields, $template, $page, $stored);

                $fields->check();
                $this->templates->updateElement($element);

                if ($fields->has('sequence') && $element->sequence !== $stored->sequence) {
                    $this->templates->reorderElements(
                        $page->id,
                        self::moved($page->elements, $stored->id, $element->sequence),
                    );
                }

                return $this->views->element($this->aggregate->requireElement($elementId));
            },
        );
    }

    /**
     * @param list<CertificateTemplateElement> $elements
     *
     * @return list<int>
     */
    private static function moved(array $elements, int $elementId, int $position): array
    {
        $ids = [];

        foreach ($elements as $element) {
            if ($element->id !== $elementId) {
                $ids[] = $element->id;
            }
        }

        $index = max(0, min($position - 1, \count($ids)));

        array_splice($ids, $index, 0, [$elementId]);

        return $ids;
    }
}
