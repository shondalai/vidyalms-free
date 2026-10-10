<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Reporting;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Context\FiltersByVisibility;
use Shondalai\VidyaLMS\Core\Registry\ExportFormat\DeclaresAvailability;
use Shondalai\VidyaLMS\Core\Registry\ExportFormat\ExportFormatRegistry;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\AttachesArtefact;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\ReportDeliveryRegistry;
use Shondalai\VidyaLMS\Core\Registry\Report\OrgScopedReportInterface;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportDefinitionInterface;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportRegistry;
use Shondalai\VidyaLMS\Core\Registry\Report\TabularReportInterface;

/**
 * Every registered report, with the filter schema each one publishes.
 *
 * Without it a manage screen cannot discover that a report exists, cannot
 * render its filter form and cannot know whether it has rows to export.
 * `parameterSchema()` had no caller anywhere: a third-party definition was
 * required to implement it and nothing ever read it, so the schema's three
 * stated purposes (render the form, validate a saved parameter set at save
 * time, give the export layer column types) were all unmet and the interface
 * asked packages for something it threw away.
 *
 * **Only what the caller may run.** Each definition declares its own required
 * capability and this filters on it, so the list is not a directory of what a
 * site has: a caller holding VIEW_REPORTS and nothing else must not learn
 * which paid report packages are installed by reading a list they cannot use.
 *
 * Organisation-only reports are listed on the organisation route and not here,
 * for the same reason the site read answers them with the miss shape: the two
 * surfaces answer about different scopes and mixing them would offer a screen
 * a report its route will not serve.
 */
final class ListReportDefinitions
{
    public function __construct(
        private readonly ReportRegistry $reports,
        private readonly AccessGuard $guard,
        private readonly TranslatorPort $translator,
        /**
         * The export formats and delivery providers this site has registered.
         *
         * They travel with the catalogue because the export control must offer
         * exactly what the registry answers with. Without them a screen holds
         * its own list of formats, which is a screen that has to be edited the
         * day a package registers `xlsx`, and that edit is the one the registry
         * exists to remove.
         *
         * Both are trailing and defaulted, so every caller written before this
         * gets an empty pair rather than a signature change.
         */
        private readonly ?ExportFormatRegistry $formats = null,
        private readonly ?ReportDeliveryRegistry $deliveries = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(Principal $principal, bool $organisationScoped = false): array
    {
        $this->guard->require($principal, Capability::VIEW_REPORTS);
        $reports = [];

        foreach ($this->reports->all() as $provider) {
            if (($provider instanceof OrgScopedReportInterface) !== $organisationScoped) {
                continue;
            }

            $capability = $provider->requiredCapability();

            if (!$this->guard->can($principal, $capability)) {
                continue;
            }

            $reports[] = [
                'key' => $provider->key(),
                'label' => $this->translator->translate($provider->label()),
                'capability' => $capability,
                'tabular' => $provider instanceof TabularReportInterface,
                'exportable' => $provider instanceof TabularReportInterface,
                // Whether a narrowed caller may run it at all, which is the
                // same probe the read and export routes make.
                'honours_visibility' => $provider instanceof FiltersByVisibility,
                'parameter_schema' => $provider instanceof ReportDefinitionInterface
                    ? $provider->parameterSchema()
                    : null,
            ];
        }

        usort($reports, static fn (array $a, array $b): int => strcmp((string) $a['key'], (string) $b['key']));

        return [
            'reports' => $reports,
            'total' => \count($reports),
            'formats' => $this->formats(),
            'deliveries' => $this->deliveries(),
        ];
    }

    /**
     * Every registered export format, in the group and order it publishes.
     *
     * @return list<array<string, mixed>>
     */
    private function formats(): array
    {
        if ($this->formats === null) {
            return [];
        }

        $formats = [];

        foreach ($this->formats->all() as $format) {
            $declares = $format instanceof DeclaresAvailability;

            $formats[] = [
                'key' => $format->key(),
                'label' => $this->translator->translate($format->label()),
                'group' => $format->group(),
                'extension' => $format->extension(),
                'mime_type' => $format->mimeType(),
                'order' => $format->order(),
                // Whether this host can actually write it. A format is
                // registered whether or not the runtime behind it was built,
                // so that the picker's answer does not depend on how the host
                // was compiled; without the flag the picker offered `pdf` on a
                // host with no PDF runtime and every run naming it failed.
                'available' => !$declares || $format->isAvailable(),
                'unavailable_reason' => $declares ? $format->unavailableReason() : null,
            ];
        }

        usort($formats, static function (array $a, array $b): int {
            $group = strcmp((string) $a['group'], (string) $b['group']);

            if ($group !== 0) {
                return $group;
            }

            return ((int) $a['order']) <=> ((int) $b['order'])
                ?: strcmp((string) $a['key'], (string) $b['key']);
        });

        return $formats;
    }

    /**
     * Every registered delivery provider, saying which of them attaches.
     *
     * The attaching flag is published rather than inferred, because the refusal
     * it drives (a row-per-person report crossing an organisation boundary as
     * an attachment) has to be visible to the screen that offers the choice and
     * not only to the use case that refuses it afterwards.
     *
     * @return list<array<string, mixed>>
     */
    private function deliveries(): array
    {
        if ($this->deliveries === null) {
            return [];
        }

        $deliveries = [];

        foreach ($this->deliveries->all() as $delivery) {
            $deliveries[] = [
                'key' => $delivery->key(),
                'label' => $this->translator->translate($delivery->label()),
                'attaches' => $delivery instanceof AttachesArtefact,
            ];
        }

        usort($deliveries, static fn (array $a, array $b): int => strcmp((string) $a['key'], (string) $b['key']));

        return $deliveries;
    }
}
