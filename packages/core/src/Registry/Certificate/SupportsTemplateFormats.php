<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Certificate;

/**
 * The source grammars a renderer can actually consume.
 *
 * Default when absent: the renderer consumes the `html` format and nothing
 * else, which is the grammar CertificateRendererInterface itself describes,
 * since render() takes a body and returns markup. A renderer written against
 * the shipped interface alone therefore degrades to the format it was always
 * able to read, rather than being refused at publication for not answering a
 * question that did not exist when it was written.
 *
 * A renderer that reads the page and element graph declares `elements` here,
 * and one that reads both declares both.
 */
interface SupportsTemplateFormats
{
    /**
     * What a renderer that does not implement this interface is taken to
     * support.
     */
    public const DEFAULT_FORMATS = ['html'];

    /** @return list<string> */
    public function supportedTemplateFormats(): array;
}
