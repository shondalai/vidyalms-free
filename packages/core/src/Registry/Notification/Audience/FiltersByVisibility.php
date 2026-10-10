<?php

/**
 * The interface that used to live here, kept reachable under its old name.
 *
 * FiltersByVisibility moved to Registry\Context, beside the VisibilityScope it
 * is about, because four kinds of provider declare it and only one of them is
 * a notification concept. Moving it without this file would have turned a core
 * upgrade into a fatal on every site whose paid package declares it, at class
 * load, before any version guard could run.
 *
 * The alias lives here and not in the new file, and the direction matters. The
 * autoloader brings this file in only when something names the old path, and
 * naming the moved interface here is what pulls the real declaration in first.
 * Putting the call in the new file instead would alias the old name eagerly
 * and then run this file's own alias over it, and it would mix a declaration
 * with a side effect in one file, which PSR-1 forbids.
 *
 * This file declares nothing. Delete it when nothing outside this repository
 * can still be holding the old name.
 */

declare(strict_types=1);

class_alias(
    \Shondalai\VidyaLMS\Core\Registry\Context\FiltersByVisibility::class,
    'Shondalai\\VidyaLMS\\Core\\Registry\\Notification\\Audience\\FiltersByVisibility',
);
