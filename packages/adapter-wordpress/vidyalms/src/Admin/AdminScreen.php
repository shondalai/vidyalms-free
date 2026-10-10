<?php

declare(strict_types=1);

/**
 * VidyaLMS: a typed wp-admin screen contributed to the shared admin app.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Admin;

defined('ABSPATH') || exit;

/**
 * Immutable host metadata for one screen rendered by the admin SPA.
 *
 * The descriptor deliberately contains no callback or service object. It is
 * safe to pass through a WordPress filter and leaves rendering with the free
 * host adapter, so an add-on contributes data rather than taking ownership of
 * the wp-admin lifecycle.
 */
final class AdminScreen
{
    public function __construct(
        public readonly string $key,
        public readonly string $slug,
        public readonly string $pageTitle,
        public readonly string $menuTitle,
        public readonly string $capability,
        public readonly int $position,
    ) {
        if (!preg_match('/^[a-z][a-z0-9-]*$/', $this->key)) {
            throw new \InvalidArgumentException('An admin screen key must be a lower-case kebab-case name.');
        }

        if (!preg_match('/^vidyalms(?:-[a-z0-9]+)*$/', $this->slug)) {
            throw new \InvalidArgumentException('An admin screen slug must live below the vidyalms namespace.');
        }

        if ($this->pageTitle === '' || $this->menuTitle === '' || $this->capability === '') {
            throw new \InvalidArgumentException('An admin screen must have titles and a capability.');
        }
    }
}
