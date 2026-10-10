<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Importer;

/**
 * An importer that tells a screen which options it accepts.
 *
 * A capability interface rather than a method on ImporterInterface, for the
 * reason every capability in this codebase is one: an importer that has no
 * options should not have to say so, and an interface a third party has
 * already shipped against must not grow a method underneath them.
 *
 * The host asks `instanceof` and draws a control per declaration; an importer
 * that declares nothing gets the source controls and no more, which is exactly
 * what the three free importers got before this existed. The values come back
 * in the same free key/value options map `import()` has always been handed, so
 * nothing about how an option is read changes: this adds a way to find out
 * that one exists, and nothing else.
 *
 * The declaration is per importer and never per format, because the importer
 * is what will read the value. A QTI importer offering a dialect, a Moodle XML
 * importer offering one, and an exporter offering the STRICT and EXTENDED
 * profiles are three declarations, not one shared table the core would have to
 * hold an opinion about.
 */
interface ConfigurableImporterInterface extends ImporterInterface
{
    /**
     * The options this importer reads, in the order a screen should show them.
     *
     * Labels and help text arrive already translated, because the importer
     * holds the translator and the host does not know this importer's string
     * keys. An empty list is legal and means the same as not implementing this
     * interface at all.
     *
     * @return list<ImportOption>
     */
    public function importOptions(): array;
}
