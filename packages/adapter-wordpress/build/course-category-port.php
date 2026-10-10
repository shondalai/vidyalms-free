<?php

/**
 * VidyaLMS: the native WordPress course-category port contract.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 *
 * Usage (from any directory):
 *   php build/course-category-port.php [--wp=<wordpress root>] [--source]
 *
 * The fixture names carry a random token, every created term is removed on
 * exit, and no option, post or LMS table is changed.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "course-category-port.php runs on the command line only.\n");
    exit(1);
}

// No built-in site: --wp or VIDYALMS_WORDPRESS_SITE names it, because this
// script works against whatever site it is pointed at.
$wpRoot = rtrim(str_replace('\\', '/', (string) getenv('VIDYALMS_WORDPRESS_SITE')), '/');
$sourceMode = false;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--wp=')) {
        $wpRoot = rtrim(str_replace('\\', '/', substr($argument, 5)), '/');
    } elseif ($argument === '--source') {
        $sourceMode = true;
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php build/course-category-port.php [--wp=<root>] [--source]\n";
        exit(0);
    } else {
        fwrite(STDERR, 'Unknown argument: ' . $argument . "\n");
        exit(1);
    }
}

if (!is_file($wpRoot . '/wp-load.php')) {
    fwrite(STDERR, 'Not a WordPress root (pass --wp=<wordpress root> or set VIDYALMS_WORDPRESS_SITE): ' . $wpRoot . "\n");
    exit(1);
}

$_SERVER['HTTP_HOST'] ??= 'localhost';
$_SERVER['REQUEST_URI'] ??= '/' . basename($wpRoot) . '/';
$_SERVER['SCRIPT_NAME'] ??= '/' . basename($wpRoot) . '/index.php';
$_SERVER['PHP_SELF'] ??= $_SERVER['SCRIPT_NAME'];
$_SERVER['REQUEST_METHOD'] ??= 'GET';

define('WP_USE_THEMES', false);

require_once $wpRoot . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

// A coordinated multi-host release is installed only after both adapters are
// ready. Source mode lets this port run against the real WordPress tables in
// the meantime without replacing the active plugin with a partial package.
if ($sourceMode) {
    $packages = dirname(__DIR__, 2);
    $coreFiles = array(
        'CourseCategoryState.php',
        'CourseCategory.php',
        'CourseCategoryPage.php',
        'CourseCategoryPort.php',
    );

    foreach ($coreFiles as $coreFile) {
        require_once $packages . '/core/src/Port/CourseCategories/' . $coreFile;
    }

    require_once __DIR__ . '/../vidyalms/src/Port/WpCourseCategories.php';
    require_once __DIR__ . '/../vidyalms/src/Admin/Menu.php';
}

use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategory;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryState;
use Shondalai\VidyaLMS\WordPress\Admin\Menu;
use Shondalai\VidyaLMS\WordPress\Content\CourseCpt;
use Shondalai\VidyaLMS\WordPress\Plugin;
use Shondalai\VidyaLMS\WordPress\Port\WpCourseCategories;

if (!function_exists('vidyalms_kernel')) {
    fwrite(STDERR, "The vidyalms plugin is not active on this site.\n");
    exit(1);
}

if (!taxonomy_exists(CourseCpt::CATEGORY_TAXONOMY)) {
    CourseCpt::registerPostType();
    CourseCpt::registerTaxonomy();
}

$failures = array();
$created = array();
$originalUserId = get_current_user_id();
$token = 'vlms-' . strtolower(bin2hex(random_bytes(5)));

/** @param bool $condition */
$expect = static function ($condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

/** @return int */
$insert = static function (string $name, int $parent = 0) use (&$created): int {
    $result = wp_insert_term($name, CourseCpt::CATEGORY_TAXONOMY, array('parent' => $parent));

    if (is_wp_error($result)) {
        throw new RuntimeException('Could not create category fixture: ' . $result->get_error_message());
    }

    $id = (int) ($result['term_id'] ?? 0);

    if ($id < 1) {
        throw new RuntimeException('WordPress returned no id for a category fixture.');
    }

    $created[] = $id;

    return $id;
};

$cleanup = static function () use (&$created, $originalUserId): void {
    foreach (array_reverse($created) as $id) {
        wp_delete_term($id, CourseCpt::CATEGORY_TAXONOMY);
    }

    wp_set_current_user($originalUserId);
    $created = array();
};

register_shutdown_function($cleanup);

try {
    $administrators = get_users(array(
        'role' => 'administrator',
        'number' => 1,
        'fields' => 'ids',
    ));

    if (isset($administrators[0])) {
        wp_set_current_user((int) $administrators[0]);
    }

    $managerUserId = get_current_user_id();
    $expect(current_user_can('vidyalms_manage'), 'The contract needs a user with native taxonomy-management access.');

    $rootName = $token . ' Root';
    $childName = $token . ' Child';
    $leafName = $token . ' 100%_Literal';
    $otherParentName = $token . ' Other parent';
    $stableName = $token . ' Stable';

    $rootId = $insert($rootName);
    $childId = $insert($childName, $rootId);
    $leafId = $insert($leafName, $childId);
    $alphaId = $insert($token . ' Alpha');
    $omegaId = $insert($token . ' Omega');
    $otherParentId = $insert($otherParentName);
    $stableOneId = $insert($stableName, $rootId);
    $stableTwoId = $insert($stableName, $otherParentId);

    if ($sourceMode) {
        /** @var wpdb $wpdb */
        global $wpdb;
        $port = new WpCourseCategories($wpdb);
    } else {
        $ports = Plugin::ports();
        $port = $ports->courseCategories;
        $expect($port instanceof WpCourseCategories, 'Plugin::ports() did not expose WpCourseCategories.');
    }

    $all = $port->page('  ' . $token . '  ', 'id', 'asc', 1, 100);
    $expectedIds = $created;
    sort($expectedIds, SORT_NUMERIC);
    $actualIds = array_map(static fn (CourseCategory $category): int => $category->id, $all->items);

    $expect($all->total === count($created), 'Trimmed search did not return the exact fixture total.');
    $expect($actualIds === $expectedIds, 'ID ascending order was not stable and exact.');
    $expect($all->page === 1 && $all->perPage === 100, 'The page did not echo its requested bounds.');

    $descending = $port->page($token, 'id', 'desc', 1, 100);
    $expect(
        array_map(static fn (CourseCategory $category): int => $category->id, $descending->items)
            === array_reverse($expectedIds),
        'ID descending order was not stable and exact.',
    );

    $firstPage = $port->page($token, 'id', 'asc', 1, 3);
    $secondPage = $port->page($token, 'id', 'asc', 2, 3);
    $lastPage = $port->page($token, 'id', 'asc', 3, 3);
    $pastEnd = $port->page($token, 'id', 'asc', 4, 3);
    $pagedIds = array_map(
        static fn (CourseCategory $category): int => $category->id,
        array_merge($firstPage->items, $secondPage->items, $lastPage->items),
    );

    $expect($pagedIds === $expectedIds, 'Page boundaries skipped or duplicated a category.');
    $expect($firstPage->total === count($created), 'The first page lost the unpaged total.');
    $expect($secondPage->total === count($created), 'The second page lost the unpaged total.');
    $expect($pastEnd->items === array(), 'A page beyond the result set was not empty.');
    $expect($pastEnd->total === count($created), 'A page beyond the result set lost the total.');

    $literal = $port->page($token . ' 100%_Literal', 'name', 'asc', 1, 20);
    $expect(
        $literal->total === 1 && isset($literal->items[0]) && $literal->items[0]->id === $leafId,
        'Percent and underscore were treated as LIKE syntax instead of literal text.',
    );

    $stableAscending = $port->page($stableName, 'name', 'asc', 1, 20);
    $stableDescending = $port->page($stableName, 'name', 'desc', 1, 20);
    $stableIds = array($stableOneId, $stableTwoId);
    sort($stableIds, SORT_NUMERIC);
    $expect(
        array_map(static fn (CourseCategory $category): int => $category->id, $stableAscending->items)
            === $stableIds,
        'Equal names did not use an ascending ID tie break.',
    );
    $expect(
        array_map(static fn (CourseCategory $category): int => $category->id, $stableDescending->items)
            === array_reverse($stableIds),
        'Equal names did not use a descending ID tie break.',
    );

    $root = $port->findById($rootId);
    $child = $port->findById($childId);
    $leaf = $port->findById($leafId);
    $expect($root?->parentId === null, 'A native root parent of zero was not normalized to null.');
    $expect($root?->label === $rootName, 'The root label was not its own name.');
    $expect($child?->label === $rootName . ' / ' . $childName, 'The child label lost its ancestry.');
    $expect(
        $leaf?->label === $rootName . ' / ' . $childName . ' / ' . $leafName,
        'The leaf label was not the complete human-readable path.',
    );
    $expect($leaf?->state === CourseCategoryState::Published, 'An extant term was not normalized to published.');
    $expect($leaf?->name === $leafName, 'Exact by-id lookup changed the native term name.');
    $expect($port->findById(PHP_INT_MAX) === null, 'An absent term did not resolve to null.');

    $expect($leaf?->editUrl !== null, 'A taxonomy manager did not receive the row edit URL.');

    if ($leaf?->editUrl !== null) {
        $editQuery = array();
        parse_str((string) parse_url($leaf->editUrl, PHP_URL_QUERY), $editQuery);
        $expect(
            ($editQuery['taxonomy'] ?? null) === CourseCpt::CATEGORY_TAXONOMY
                && ($editQuery['post_type'] ?? null) === CourseCpt::POST_TYPE
                && (int) ($editQuery['tag_ID'] ?? 0) === $leafId,
            'The row edit URL did not target the native term screen.',
        );
    }

    $expect($all->managementUrl !== null, 'A taxonomy manager did not receive the collection URL.');
    $managementQuery = array();
    parse_str((string) parse_url($all->managementUrl, PHP_URL_QUERY), $managementQuery);
    $expect(
        ($managementQuery['taxonomy'] ?? null) === CourseCpt::CATEGORY_TAXONOMY
            && ($managementQuery['post_type'] ?? null) === CourseCpt::POST_TYPE,
        'The collection URL did not target the course-category taxonomy and post type.',
    );

    wp_set_current_user(0);
    $deniedPage = $port->page($token, 'id', 'asc', 1, 20);
    $deniedLeaf = $port->findById($leafId);
    $expect(
        $deniedPage->managementUrl === null,
        'A caller without vidyalms_manage received the taxonomy manager URL.',
    );
    $expect(
        $deniedLeaf !== null && $deniedLeaf->editUrl === null,
        'A caller without vidyalms_manage received a term edit URL.',
    );
    wp_set_current_user($managerUserId);

    $taxonomy = get_taxonomy(CourseCpt::CATEGORY_TAXONOMY);
    $expect(
        $taxonomy instanceof WP_Taxonomy
            && in_array(CourseCpt::POST_TYPE, $taxonomy->object_type, true),
        'The native taxonomy is not attached to the VidyaLMS course post type.',
    );

    $GLOBALS['menu'] ??= array();
    $GLOBALS['submenu'] ??= array();
    $GLOBALS['_registered_pages'] ??= array();
    $GLOBALS['_parent_pages'] ??= array();
    // CLI requests do not boot the admin interface's meta-capability filter.
    $workspaceMenu = new Menu();
    add_filter('map_meta_cap', [$workspaceMenu, 'mapAccess'], 10, 3);
    $workspaceMenu->register();
    $expect(
        empty($GLOBALS['submenu'][Menu::SLUG_ROOT]),
        'The WordPress menu duplicates the VidyaLMS workspace navigation.',
    );
    $categoryEntries = array_values(array_filter(
        $GLOBALS['submenu'][Menu::SLUG_PAGES] ?? array(),
        static fn (array $entry): bool => ($entry[2] ?? null) === 'vidyalms-categories',
    ));
    $expect(count($categoryEntries) === 1, 'The workspace Categories page is missing or duplicated.');
    $expect(
        ($categoryEntries[0][1] ?? null) === Menu::CAP_ACCESS,
        'The workspace Categories page does not retain its access capability.',
    );
    remove_filter('map_meta_cap', [$workspaceMenu, 'mapAccess'], 10);

    // Keep these referenced so an accidental fixture omission is visible to
    // static review as well as the count assertions above.
    $expect($alphaId > 0 && $omegaId > 0, 'The pagination fixtures were not created.');
} catch (Throwable $failure) {
    $failures[] = $failure->getMessage();
}

$cleanup();

echo "VidyaLMS course-category port contract, WordPress\n";
echo '  site:           ' . $wpRoot . "\n";
echo '  implementation: ' . WpCourseCategories::class . "\n\n";
echo '  loaded from:    ' . ($sourceMode ? 'repository source' : 'installed plugin') . "\n\n";

foreach ($failures as $failure) {
    echo '  [FAIL] ' . $failure . "\n";
}

if ($failures === array()) {
    echo "  [PASS] native taxonomy paging, search, hierarchy, links and menu hold.\n";
}

echo "\n" . ($failures === array()
    ? "The WordPress category contract holds.\n"
    : sprintf("%d expectation(s) broken.\n", count($failures)));

exit($failures === array() ? 0 : 1);
