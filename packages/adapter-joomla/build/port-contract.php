<?php

/**
 * @package     VidyaLMS
 * @subpackage  Build
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 *
 * Runs the shared database port contract and the Joomla-native course
 * category contract on a real Joomla site and a real MySQL connection.
 *
 * The core proves the same assertions against its SQLite harness in
 * tests/Integration/Port/DatabasePortContractTest.php. That is the only place
 * they can run without a CMS, and it is exactly why it is not enough: an
 * aggregate, a CASE, a rate that answers NULL on an empty domain and a
 * "nulls last" ordering MySQL has no syntax for are all things this adapter
 * compiles itself, and SQLite is forgiving where MySQL is not.
 *
 * Usage (from any directory):
 *   php build/port-contract.php [--joomla=<joomla root>]
 *
 * It writes nothing that survives: every row goes in inside a transaction the
 * contract deliberately rolls back, and the run then checks that the rollback
 * actually happened rather than assuming it. It creates no table and drops
 * none, and a SchemaSeal over the site's table list says so out loud.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "port-contract.php runs on the command line only.\n");
    exit(1);
}

// No built-in site: --joomla or VIDYALMS_JOOMLA_SITE names it, because this
// script works against whatever site it is pointed at.
$joomlaRoot = rtrim(str_replace('\\', '/', (string) getenv('VIDYALMS_JOOMLA_SITE')), '/');

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--joomla=')) {
        $joomlaRoot = rtrim(str_replace('\\', '/', substr($argument, 9)), '/');
    } elseif ($argument === '--help' || $argument === '-h') {
        echo "Usage: php build/port-contract.php [--joomla=<root>]\n";
        exit(0);
    } else {
        fwrite(STDERR, 'Unknown argument: ' . $argument . "\n");
        exit(1);
    }
}

if (!is_file($joomlaRoot . '/configuration.php')) {
    fwrite(STDERR, 'Not a Joomla root (pass --joomla=<joomla root> or set VIDYALMS_JOOMLA_SITE): ' . $joomlaRoot . "\n");
    exit(1);
}

// Uri::root() reads the server environment and the console has no request, so
// the site address is spelled out exactly as smoke.php does.
$_SERVER['HTTP_HOST']      ??= 'localhost';
$_SERVER['REQUEST_URI']    ??= '/' . basename($joomlaRoot) . '/';
$_SERVER['SCRIPT_NAME']    ??= '/' . basename($joomlaRoot) . '/index.php';
$_SERVER['PHP_SELF']       ??= $_SERVER['SCRIPT_NAME'];
$_SERVER['REQUEST_METHOD'] ??= 'GET';

\define('_JEXEC', 1);
\define('JPATH_BASE', $joomlaRoot);

require_once __DIR__ . '/../../../tooling/fixtures/bootstrap.php';
require_once JPATH_BASE . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\CMS\Categories\CategoryServiceInterface;
use Joomla\Console\Application as ConsoleApplication;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryPort;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryState;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Http\HttpPort;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Port\Secrets\SecretsPort;
use Shondalai\VidyaLMS\Tooling\Contract\DatabasePortContract;
use Shondalai\VidyaLMS\Tooling\Contract\HttpPortContract;
use Shondalai\VidyaLMS\Tooling\Contract\SecretsPortContract;
use Shondalai\VidyaLMS\Tooling\Fixtures\SchemaSeal;
use VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaCourseCategories;

$container = Factory::getContainer();
$container->alias('session', 'session.cli')
    ->alias(\Joomla\CMS\Session\Session::class, 'session.cli')
    ->alias(\Joomla\Session\Session::class, 'session.cli')
    ->alias(\Joomla\Session\SessionInterface::class, 'session.cli');

$application = $container->get(ConsoleApplication::class);

// doExecute() is what normally registers the extension namespaces; this script
// dispatches no console command, so the PSR-4 map is loaded by hand.
$application->createExtensionNamespaceMap();

// The contract bootstrap loads by require and not by autoload, and several of
// its files extend a core class (SmokeOrganisationRosterImporter extends
// Registry\Importer\AbstractImporter). Requiring them before the line above
// resolves the parent through an autoloader that does not know lib_vidyalms_core
// yet, which is a fatal "class not found" before the first contract runs.
require_once __DIR__ . '/../../../tooling/contract/bootstrap.php';

Factory::$application = $application;

/** @var DatabaseInterface $db */
$db = $container->get(DatabaseInterface::class);

$seal = new SchemaSeal('joomla port contract', static fn (): array => $db->getTableList());
$seal->verifyOnShutdown();

// Booting the component is what registers the ports on the global container.
$component = $application->bootComponent('com_vidyalms');

$database = null;

if ($container->has(DatabasePort::class)) {
    $database = $container->get(DatabasePort::class);
} elseif ($container->has(Ports::class)) {
    $database = $container->get(Ports::class)->database;
}

if (!$database instanceof DatabasePort) {
    fwrite(STDERR, "The VidyaLMS database port is not registered on this site.\n");
    exit(1);
}

$categories = $container->has(CourseCategoryPort::class)
    ? $container->get(CourseCategoryPort::class)
    : null;

echo "VidyaLMS database port contract, Joomla\n";
echo '  site:           ' . $joomlaRoot . "\n";
echo '  implementation: ' . \get_class($database) . "\n\n";

$databaseFailures = DatabasePortContract::failures($database);

foreach ($databaseFailures as $failure) {
    echo '  [FAIL] ' . $failure . "\n";
}

if ($databaseFailures === []) {
    echo "  [PASS] JoomlaDatabase satisfies the database port contract.\n";
}

echo "\nVidyaLMS course category port contract, Joomla\n";

$categoryFailures = [];

if (!$categories instanceof JoomlaCourseCategories) {
    $categoryFailures[] = 'The native course category port is not registered.';
} else {
    $categoryFailures = courseCategoryFailures(
        $db,
        new JoomlaCourseCategories(
            $db,
            static fn (string $action, string $asset): bool =>
                ($action === 'core.manage' && $asset === 'com_vidyalms')
                || ($action === 'core.edit' && str_starts_with($asset, 'com_vidyalms.category.')),
        ),
        new JoomlaCourseCategories($db, static fn (string $action, string $asset): bool => false),
        $component,
    );
}

foreach ($categoryFailures as $failure) {
    echo '  [FAIL] ' . $failure . "\n";
}

if ($categoryFailures === []) {
    echo "  [PASS] JoomlaCourseCategories satisfies the native category contract.\n";
    echo "  [PASS] Native category destinations follow Joomla ACL.\n";
    echo "  [PASS] Misleading native item-count links remain suppressed.\n";
}

// The two ports the commerce wave added. They shipped with no conformance
// contract at all, so each adapter decided for itself what an allowlist entry
// means, how a timeout is clamped and what a transport failure is called. The
// checks make no outbound call and read no configured secret: every assertion
// is about a refusal, and a kit that needed the network would be a kit nobody
// could run on the site that most needs it.
echo "\nVidyaLMS HTTP and secrets port contracts, Joomla\n";

$http = $container->has(Ports::class) ? $container->get(Ports::class)->http : null;
$secrets = $container->has(Ports::class) ? $container->get(Ports::class)->secrets : null;

$httpFailures = $http instanceof HttpPort
    ? HttpPortContract::failures($http)
    : ['The VidyaLMS HTTP port is not registered on this site.'];
$secretsFailures = $secrets instanceof SecretsPort
    ? SecretsPortContract::failures($secrets)
    : ['The VidyaLMS secrets port is not registered on this site.'];

foreach ([...$httpFailures, ...$secretsFailures] as $failure) {
    echo '  [FAIL] ' . $failure . "\n";
}

if ($httpFailures === []) {
    echo '  [PASS] ' . \get_class($http) . " satisfies the HTTP port contract.\n";
}

if ($secretsFailures === []) {
    echo '  [PASS] ' . \get_class($secrets) . " satisfies the secrets port contract.\n";
}

$failures = [...$databaseFailures, ...$categoryFailures, ...$httpFailures, ...$secretsFailures];

echo "\n" . ($failures === []
    ? "All four contracts hold on MySQL.\n"
    : sprintf("%d expectation(s) broken.\n", \count($failures)));

exit($failures === [] ? 0 : 1);

/**
 * @return list<string>
 */
function courseCategoryFailures(
    DatabaseInterface $db,
    CourseCategoryPort $categories,
    CourseCategoryPort $deniedCategories,
    object $component,
): array {
    $failures = [];
    $expect = static function (bool $condition, string $message) use (&$failures): void {
        if (!$condition) {
            $failures[] = $message;
        }
    };

    $token = strtolower(bin2hex(random_bytes(6)));
    $marker = 'VLMS category contract ' . $token;
    $now = gmdate('Y-m-d H:i:s');
    $started = false;

    try {
        $db->transactionStart();
        $started = true;

        $maxQuery = $db->createQuery()
            ->select('COALESCE(MAX(' . $db->quoteName('rgt') . '), 0)')
            ->from($db->quoteName('#__categories'));
        $edge = (int) $db->setQuery($maxQuery)->loadResult() + 1;

        $parentTitle = $marker . ' 20 Parent';
        $parentId = insertCategoryFixture($db, [
            'parent_id' => 1,
            'lft' => $edge,
            'rgt' => $edge + 3,
            'level' => 1,
            'path' => 'vlms-contract-' . $token . '-parent',
            'title' => $parentTitle,
            'alias' => 'vlms-contract-' . $token . '-parent',
            'published' => 1,
            'created_time' => $now,
            'modified_time' => $now,
        ]);
        $childTitle = $marker . ' 21 Child';
        $childId = insertCategoryFixture($db, [
            'parent_id' => $parentId,
            'lft' => $edge + 1,
            'rgt' => $edge + 2,
            'level' => 2,
            'path' => 'vlms-contract-' . $token . '-parent/child',
            'title' => $childTitle,
            'alias' => 'vlms-contract-' . $token . '-child',
            'published' => 0,
            'created_time' => $now,
            'modified_time' => $now,
        ]);
        $twinTitle = $marker . ' 30 Twin';
        $archivedId = insertCategoryFixture($db, [
            'parent_id' => 1,
            'lft' => $edge + 4,
            'rgt' => $edge + 5,
            'level' => 1,
            'path' => 'vlms-contract-' . $token . '-archived',
            'title' => $twinTitle,
            'alias' => 'vlms-contract-' . $token . '-archived',
            'published' => 2,
            'created_time' => $now,
            'modified_time' => $now,
        ]);
        $trashedId = insertCategoryFixture($db, [
            'parent_id' => 1,
            'lft' => $edge + 6,
            'rgt' => $edge + 7,
            'level' => 1,
            'path' => 'vlms-contract-' . $token . '-trashed',
            'title' => $twinTitle,
            'alias' => 'vlms-contract-' . $token . '-trashed',
            'published' => -2,
            'created_time' => $now,
            'modified_time' => $now,
        ]);
        $literalId = insertCategoryFixture($db, [
            'parent_id' => 1,
            'lft' => $edge + 8,
            'rgt' => $edge + 9,
            'level' => 1,
            'path' => 'vlms-contract-' . $token . '-literal',
            'title' => $marker . ' %_ Literal',
            'alias' => 'vlms-contract-' . $token . '-literal',
            'published' => 1,
            'created_time' => $now,
            'modified_time' => $now,
        ]);
        $cycleATitle = $marker . ' 40 Cycle A';
        $cycleAId = insertCategoryFixture($db, [
            'parent_id' => 1,
            'lft' => $edge + 10,
            'rgt' => $edge + 11,
            'level' => 1,
            'path' => 'vlms-contract-' . $token . '-cycle-a',
            'title' => $cycleATitle,
            'alias' => 'vlms-contract-' . $token . '-cycle-a',
            'published' => 1,
            'created_time' => $now,
            'modified_time' => $now,
        ]);
        $cycleBTitle = $marker . ' 41 Cycle B';
        $cycleBId = insertCategoryFixture($db, [
            'parent_id' => $cycleAId,
            'lft' => $edge + 12,
            'rgt' => $edge + 13,
            'level' => 2,
            'path' => 'vlms-contract-' . $token . '-cycle-a/cycle-b',
            'title' => $cycleBTitle,
            'alias' => 'vlms-contract-' . $token . '-cycle-b',
            'published' => 1,
            'created_time' => $now,
            'modified_time' => $now,
        ]);

        $cycleQuery = $db->createQuery()
            ->update($db->quoteName('#__categories'))
            ->set($db->quoteName('parent_id') . ' = :parent')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':parent', $cycleBId, ParameterType::INTEGER)
            ->bind(':id', $cycleAId, ParameterType::INTEGER);
        $db->setQuery($cycleQuery)->execute();

        $ids = [$parentId, $childId, $archivedId, $trashedId, $literalId, $cycleAId, $cycleBId];
        sort($ids, SORT_NUMERIC);

        $all = $categories->page($marker, 'id', 'asc', 1, 20);
        $actualIds = array_map(static fn ($category): int => $category->id, $all->items);
        $expect($all->total === 7, 'Own-name search must report the exact total before paging.');
        $expect($actualIds === $ids, 'ID ascending order must be stable and performed by the host query.');
        $expect($all->page === 1 && $all->perPage === 20, 'The category page must echo page and per_page exactly.');

        $secondPage = $categories->page($marker, 'id', 'asc', 2, 3);
        $secondIds = array_map(static fn ($category): int => $category->id, $secondPage->items);
        $expect($secondPage->total === 7, 'A later category page must retain the unpaged total.');
        $expect($secondIds === array_slice($ids, 3, 3), 'The category query must cut the requested server-side page.');

        $descending = $categories->page($marker, 'id', 'desc', 1, 20);
        $descendingIds = array_map(static fn ($category): int => $category->id, $descending->items);
        $expect($descendingIds === array_reverse($ids), 'Descending ID order must be performed by the host query.');

        $twinsAscending = $categories->page($twinTitle, 'name', 'asc', 1, 10);
        $twinsDescending = $categories->page($twinTitle, 'name', 'desc', 1, 10);
        $expect(
            array_map(static fn ($category): int => $category->id, $twinsAscending->items)
                === [$archivedId, $trashedId],
            'Equal names must use ID as the ascending stable tie break.',
        );
        $expect(
            array_map(static fn ($category): int => $category->id, $twinsDescending->items)
                === [$trashedId, $archivedId],
            'Equal names must use ID as the descending stable tie break.',
        );

        $parentOnly = $categories->page($parentTitle, 'name', 'asc', 1, 10);
        $expect(
            $parentOnly->total === 1 && ($parentOnly->items[0]->id ?? 0) === $parentId,
            'Search must match a category own name, not its hydrated ancestry label.',
        );
        $literal = $categories->page('%_', 'name', 'asc', 1, 10);
        $expect(
            $literal->total === 1 && ($literal->items[0]->id ?? 0) === $literalId,
            'Percent and underscore in a search term must remain literal characters.',
        );

        $byId = [];

        foreach ($all->items as $category) {
            $byId[$category->id] = $category;
        }

        $expect(
            isset($byId[$parentId]) && $byId[$parentId]->parentId === null,
            'Joomla root parent 1 must normalize to null.',
        );
        $expect(
            ($byId[$childId]->parentId ?? null) === $parentId,
            'A non-root Joomla category parent must remain its exact integer id.',
        );
        $expect(
            ($byId[$childId]->label ?? '') === $parentTitle . ' / ' . $childTitle,
            'A child label must contain its complete ancestry separated by " / ".',
        );
        $expect(
            ($byId[$parentId]->state ?? null) === CourseCategoryState::Published
                && ($byId[$childId]->state ?? null) === CourseCategoryState::Unpublished
                && ($byId[$archivedId]->state ?? null) === CourseCategoryState::Archived
                && ($byId[$trashedId]->state ?? null) === CourseCategoryState::Trashed,
            'Joomla category states 1, 0, 2 and -2 must map to all four neutral states.',
        );

        $foundChild = $categories->findById($childId);
        $expect(
            $foundChild !== null && $foundChild->label === $parentTitle . ' / ' . $childTitle,
            'Exact category lookup must hydrate the same full label as a page.',
        );
        $expect(
            $categories->findById($trashedId)?->state === CourseCategoryState::Trashed,
            'Exact category lookup must include trashed rows.',
        );
        $expect($categories->findById(-1) === null, 'A missing category id must return null.');

        $cycle = $categories->findById($cycleAId);
        $cycleLabel = $cycle?->label ?? '';
        $expect(
            substr_count($cycleLabel, $cycleATitle) === 1 && substr_count($cycleLabel, $cycleBTitle) === 1,
            'A malformed parent cycle must terminate without duplicating either category in the label.',
        );

        $expect(
            categoryUrlMatches($all->managementUrl, [
                'option' => 'com_categories',
                'view' => 'categories',
                'extension' => 'com_vidyalms',
            ]),
            'The page must expose Joomla native category management as its management URL.',
        );
        $editUrl = $byId[$childId]->editUrl ?? '';
        $expect(
            categoryUrlMatches($editUrl, [
                'option' => 'com_categories',
                'task' => 'category.edit',
                'id' => (string) $childId,
                'extension' => 'com_vidyalms',
            ]),
            'Each Joomla category must expose its exact native edit URL.',
        );
        $deniedPage = $deniedCategories->page($marker, 'id', 'asc', 1, 20);
        $deniedChild = $deniedCategories->findById($childId);
        $expect(
            $deniedPage->managementUrl === null,
            'A caller without core.manage must not receive the native category manager URL.',
        );
        $expect(
            $deniedChild !== null && $deniedChild->editUrl === null,
            'A caller without native category edit access must not receive a row edit URL.',
        );

        if (!$component instanceof CategoryServiceInterface) {
            $failures[] = 'The Joomla component must implement CategoryServiceInterface.';
        } else {
            $tree = $component->getCategory();
            $treeOptions = $tree->getOptions();
            $expect($tree->getExtension() === 'com_vidyalms', 'The Joomla category tree must target com_vidyalms.');
            $expect(
                ($treeOptions['table'] ?? null) === '#__vidyalms_courses'
                    && ($treeOptions['field'] ?? null) === 'category_id'
                    && ($treeOptions['statefield'] ?? null) === 'state',
                'The Joomla category tree must describe the real VidyaLMS course columns.',
            );
            $expect(
                ($treeOptions['countItems'] ?? null) === 0,
                'The generic numeric-state category counter must remain disabled for string course states.',
            );

            $counted = (object) ['id' => $childId];
            $component->countItems([$counted], '');
            $expect(
                !property_exists($counted, 'count_published')
                    && !property_exists($counted, 'count_unpublished')
                    && !property_exists($counted, 'count_archived')
                    && !property_exists($counted, 'count_trashed'),
                'Native category item-count properties must stay absent until their links target a filtered course list.',
            );
        }
    } catch (Throwable $exception) {
        $failures[] = 'The native category contract raised ' . get_class($exception) . ': ' . $exception->getMessage();
    } finally {
        if ($started) {
            $db->transactionRollback();
        }
    }

    $pattern = '%' . addcslashes($marker, '\\%_') . '%';
    $remainingQuery = $db->createQuery()
        ->select('COUNT(*)')
        ->from($db->quoteName('#__categories'))
        ->where($db->quoteName('title') . " LIKE :marker ESCAPE '\\\\'")
        ->bind(':marker', $pattern, ParameterType::STRING);
    $remaining = (int) $db->setQuery($remainingQuery)->loadResult();
    $expect($remaining === 0, 'The category contract transaction must roll back every native category fixture.');

    return $failures;
}

/**
 * @param array<string, int|string|null> $values
 */
function insertCategoryFixture(DatabaseInterface $db, array $values): int
{
    $row = (object) array_replace([
        'asset_id' => 0,
        'parent_id' => 1,
        'lft' => 0,
        'rgt' => 0,
        'level' => 1,
        'path' => '',
        'extension' => 'com_vidyalms',
        'title' => '',
        'alias' => '',
        'note' => '',
        'description' => '',
        'published' => 1,
        'checked_out' => null,
        'checked_out_time' => null,
        'access' => 1,
        'params' => '{}',
        'metadesc' => '',
        'metakey' => '',
        'metadata' => '{}',
        'created_user_id' => 0,
        'created_time' => gmdate('Y-m-d H:i:s'),
        'modified_user_id' => 0,
        'modified_time' => gmdate('Y-m-d H:i:s'),
        'hits' => 0,
        'language' => '*',
        'version' => 1,
    ], $values);

    $db->insertObject('#__categories', $row, 'id');

    return (int) $row->id;
}

/**
 * @param array<string, string> $expectedQuery
 */
function categoryUrlMatches(string $url, array $expectedQuery): bool
{
    $parts = parse_url($url);

    if (!\is_array($parts) || !str_ends_with((string) ($parts['path'] ?? ''), '/administrator/index.php')) {
        return false;
    }

    parse_str((string) ($parts['query'] ?? ''), $query);

    foreach ($expectedQuery as $key => $value) {
        if ((string) ($query[$key] ?? '') !== $value) {
            return false;
        }
    }

    return true;
}
