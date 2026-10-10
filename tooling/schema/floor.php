<?php

/**
 * Shared lower bound for packaged schema versions, not the schema to install.
 *
 * Fresh installations compile each edition's current neutral schema and record
 * its declared version. The floor only bounds the retained update filenames;
 * a version above it must be reachable on both hosts of that edition.
 *
 * Native commerce storage has been removed from the fresh schema. This bound
 * does not promise migration or compatibility for retired native-commerce
 * installations. Financial history remains the external shop's responsibility.
 *
 * @return string
 */

declare(strict_types=1);

return '1.0.0';
