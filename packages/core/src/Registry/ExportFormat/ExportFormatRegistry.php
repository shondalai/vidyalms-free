<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ExportFormat;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * The formats a tabular report can be written in.
 *
 * **Miss direction: absent from the negotiated set.** So the question a screen
 * asks is {@see offered()} rather than {@see get()}, and a key nobody
 * registered is simply not offered. A caller that explicitly asked for one is
 * refused by the pipeline with the list of what the site can write, which is
 * the amended direction: an export format is a named request parameter, and
 * not the `Accept` header a credential is negotiated through.
 *
 * @extends AbstractProviderRegistry<ExportFormatInterface>
 */
final class ExportFormatRegistry extends AbstractProviderRegistry
{
    public const EXTENSION_POINT = 'export_format';

    protected function providerInterface(): string
    {
        return ExportFormatInterface::class;
    }

    /**
     * Every registered format, in the order a picker should draw them.
     *
     * Group then order then key, so two packages that both register into one
     * group land in a stable place rather than in boot order.
     *
     * @return list<ExportFormatInterface>
     */
    public function offered(): array
    {
        $formats = $this->all();

        usort($formats, static function (ExportFormatInterface $a, ExportFormatInterface $b): int {
            return [$a->group(), $a->order(), $a->key()] <=> [$b->group(), $b->order(), $b->key()];
        });

        return $formats;
    }
}
