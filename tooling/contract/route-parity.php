<?php

/**
 * @package     VidyaLMS
 * @subpackage  Tooling
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 *
 * Route-to-contract parity.
 *
 * Boots the free kernel, enumerates every route it registers, and fails
 * unless that set is exactly the operations openapi.yaml declares for the
 * free build. The operations only a paid package serves are allow-listed in
 * the contract's own root "x-vidyalms-editions.pro" block.
 *
 * It also binds one parameter to one status: an operation that requires an
 * Idempotency-Key must declare the 400 an absent one answers. That is a
 * response-level check on a route-level gate, and it is here rather than in a
 * sibling script because a caller runs one command.
 *
 * Why this exists: the twenty-nine organisation path items matched the
 * thirty-nine registered organisation routes exactly, and nothing held them
 * there. Twenty credential endpoints once sat on the contract, in the
 * generated SDK and on no server, which is a 404 for a control the app drew
 * from the contract and a defect no unit test can see.
 *
 * Usage:
 *   php -d extension=pdo_sqlite -d extension=sqlite3 -d extension=sodium \
 *       tooling/contract/route-parity.php [--list] [--contract=FILE]
 *
 *   --list      print the registered and declared sets instead of only the
 *               differences, for building the Pro allow-list.
 *   --contract  read a contract other than the repository's openapi.yaml.
 *               It is what makes this check testable: point it at a copy
 *               with one operation removed and it must fail.
 *
 * It deliberately never loads packages/pro-core. A free build's gate that
 * needs a paid package to run is a gate the free build cannot run, and this
 * side pins three of the four directions: an entry the free kernel serves, an
 * entry no path item declares, and a free declaration nothing serves all fail
 * here. The fourth, an allow-listed operation nobody serves at all, cannot be
 * seen from here, so packages/pro-core/tests/Integration/ProRouteParityTest.php
 * owns it and this script fails while that file is missing rather than
 * reporting a claim it did not check.
 *
 * The kernel is booted with every free feature switched on, because a few
 * routes are configuration-gated (organisation invitations need a secret and
 * an https acceptance URL) and a build that serves fewer routes than it can
 * would otherwise report those as undeclared.
 *
 * It boots over the core's own SQLite harness (TestPorts), the same way the
 * parity fixtures under tooling/tests do, so it needs a development install of
 * packages/core and the pdo_sqlite and sqlite3 extensions. It is a build gate,
 * never something a site runs.
 */

declare(strict_types=1);

use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;
use Shondalai\VidyaLMS\Tooling\Contract\OpenApiPaths;

$root = \dirname(__DIR__, 2);

require $root . '/packages/core/vendor/autoload.php';
require __DIR__ . '/OpenApiPaths.php';

$options = array_slice($argv, 1);
$list = \in_array('--list', $options, true);

/** Every free feature on, so a configuration-gated route is not missed. */
const KERNEL_OPTIONS = [
    'verification_base_url' => 'https://example.test/verify',
    'host_settings_url' => 'https://example.test/administrator',
    'envelope_domain' => 'example.test',
    'self_enrolment' => true,
    'expiry_sweep_schedule' => 'hourly',
    'organisation_invitation_secret' => 'route-parity-secret-route-parity-secret',
    'organisation_invitation_url' => 'https://example.test/membership',
];

/** Where the separate provisioning surface lives, when anything serves it. */
const SCIM_PREFIX = '/scim/v2';

/**
 * @return list<string>
 */
function registeredOperations(ApiKernel $api): array
{
    $routes = (new ReflectionProperty($api, 'routes'))->getValue($api);

    if (!\is_array($routes)) {
        throw new RuntimeException('ApiKernel::$routes is not a list.');
    }

    $operations = [];

    foreach ($routes as $route) {
        $operations[] = strtoupper($route->method) . ' ' . $route->pattern;
    }

    sort($operations);

    return $operations;
}

/**
 * @param list<string> $operations
 */
function report(string $heading, array $operations): void
{
    echo $heading, ' (', \count($operations), ')', PHP_EOL;

    foreach ($operations as $operation) {
        echo '  ', $operation, PHP_EOL;
    }
}

$env = TestPorts::create();
$kernel = new CoreKernel($env->ports, KERNEL_OPTIONS);
$free = registeredOperations($kernel->apiKernel());

$contract = $root . '/openapi.yaml';

foreach ($options as $option) {
    if (str_starts_with($option, '--contract=')) {
        $contract = substr($option, \strlen('--contract='));
    }
}

if (!is_file($contract)) {
    fwrite(STDERR, 'No contract at ' . $contract . PHP_EOL);
    exit(2);
}

$declared = OpenApiPaths::operations($contract);
$proOnly = OpenApiPaths::proOperations($contract);
$businessOnly = OpenApiPaths::businessOperations($contract);

/**
 * Two paid tiers, one question.
 *
 * This side of the check only ever asks "does a free build serve it", and both
 * lists answer no, so they are compared as one set. The split matters to the
 * edition table and to the SDK's documentation, not here. Keeping them as one
 * union at this point is also what stops a Business operation reading as an
 * unserved free declaration, which is how the check would have failed the day
 * the first Business operation was declared.
 */
$pro = array_values(array_unique([...$proOnly, ...$businessOnly]));
sort($pro);

$bothTiers = array_values(array_intersect($proOnly, $businessOnly));
$declaredFree = array_values(array_diff($declared, $pro));

$failures = [];

$undeclared = array_values(array_diff($free, $declared));

if ($undeclared !== []) {
    $failures[] = 'Registered by the free kernel and declared nowhere in openapi.yaml';
    report('  undeclared routes', $undeclared);
}

/**
 * The SCIM surface, in the one direction this script can prove.
 *
 * SCIM is a second contract document because it cannot share this one's error
 * model, media type or paging (see openapi-scim.yaml's own header). Its
 * operations are deliberately NOT merged into $declared: the surface is served
 * by its own route table rather than mounted on ApiKernel, so this script
 * cannot see it at all, and merging the declarations in would turn every one of
 * them into a free declaration nothing serves, which is the failure this whole
 * check exists to report.
 *
 * That half is owned by pro-core, in
 * tests/Integration/ScimContractParityTest.php, which pins ScimRoutes::all()
 * against the SCIM contract in both directions.
 *
 * What is enforced here is the direction this script can see. The moment a
 * `/scim/v2` route appears on the free kernel, it must be declared in that
 * file, so nobody can mount the surface on the product kernel without the
 * contract moving with it.
 */
$scimContract = \dirname($contract) . '/openapi-scim.yaml';

if (is_file($scimContract)) {
    $scimDeclared = OpenApiPaths::operations($scimContract);
    $scimRegistered = array_values(array_filter(
        $free,
        static fn (string $operation): bool => str_contains($operation, ' ' . SCIM_PREFIX),
    ));
    $scimUndeclared = array_values(array_diff($scimRegistered, $scimDeclared));

    if ($scimUndeclared !== []) {
        $failures[] = 'Registered under ' . SCIM_PREFIX . ' and declared nowhere in openapi-scim.yaml';
        report('  undeclared SCIM routes', $scimUndeclared);
    }

    printf("scim contract declares %d operations, free build serves %d\n", \count($scimDeclared), \count($scimRegistered));
}

$unregistered = array_values(array_diff($declaredFree, $free));

if ($unregistered !== []) {
    $failures[] = 'Declared as free in openapi.yaml and registered by no free route';
    report('  unregistered operations', $unregistered);
}

$wronglyPro = array_values(array_intersect($pro, $free));

if ($wronglyPro !== []) {
    $failures[] = 'Allow-listed as Pro and served by the free build';
    report('  mislabelled operations', $wronglyPro);
}

/**
 * The parameter and the status it promises, kept together.
 *
 * RequiredIdempotencyKey says the write commits its claim inside its own
 * mutation, and IdempotentExecution refuses an absent or blank header with
 * 400. Thirty eight operations referenced the parameter and not one declared
 * the status, so on the eleven that carry no `default` either the refusal was
 * unreachable through the generated client: the header's own description
 * promised a code no operation named. Route parity compares method and path
 * only, so nothing here could see it.
 */
$unbound = [];

foreach (OpenApiPaths::operationContracts($contract) as $operation) {
    if (!\in_array('#/components/parameters/RequiredIdempotencyKey', $operation['parameters'], true)) {
        continue;
    }

    if (\in_array('400', $operation['responses'], true)) {
        continue;
    }

    $unbound[] = strtoupper($operation['method']) . ' ' . $operation['path'] . ' (' . $operation['operationId'] . ')';
}

sort($unbound);

if ($unbound !== []) {
    $failures[] = 'Requires an Idempotency-Key and declares no 400, which is what a missing one answers';
    report('  unbound operations', $unbound);
}

$phantomPro = array_values(array_diff($pro, $declared));

if ($phantomPro !== []) {
    $failures[] = 'Allow-listed as paid and declared by no path item';
    report('  phantom allow-list entries', $phantomPro);
}

/**
 * One operation, one tier. An entry on both lists would make the edition table
 * unanswerable and would let a Business operation be sold as Pro.
 */
if ($bothTiers !== []) {
    $failures[] = 'Allow-listed under both pro and business, so no tier owns it';
    report('  operations claimed twice', $bothTiers);
}

/**
 * The one direction this script cannot check, and the reason it must not
 * pretend otherwise.
 *
 * An operation that is allow-listed, declared by a path item and served by
 * nobody passes every arm above: it is excluded from $declaredFree before the
 * comparison, so it is neither unregistered nor wrongly free. Adding a genuinely
 * free but unimplemented operation to the allow-list therefore silences exactly
 * the failure this check exists for, which was proved by inserting a fabricated
 * path item and its two operations into a copy of the contract and into the
 * list: this script printed OK and exited 0.
 *
 * Proving the Pro half needs ProKernel, and a free build's gate that loads a
 * paid package is a gate the free build cannot run. So the claim is owned by a
 * pro-core test, and this refuses to pass while that test is missing. A tree
 * with no packages/pro-core has nothing to pin and says so.
 */
const PRO_WITNESS = 'packages/pro-core/tests/Integration/ProRouteParityTest.php';
const PRO_WITNESS_MARKERS = [
    'OpenApiPaths::paidOperations',
    'OpenApiPaths::businessOperations',
    'ProKernel::attach',
];

if (is_dir($root . '/packages/pro-core')) {
    $witness = $root . '/' . PRO_WITNESS;
    $witnessSource = is_file($witness) ? (string) file_get_contents($witness) : '';

    if ($witnessSource === '') {
        $failures[] = 'The Pro half of the allow-list is proved by nothing: ' . PRO_WITNESS . ' is missing';
    } else {
        foreach (PRO_WITNESS_MARKERS as $marker) {
            if (!str_contains($witnessSource, $marker)) {
                $failures[] = PRO_WITNESS . ' no longer asserts the allow-list against ' . $marker;
            }
        }
    }
} else {
    echo 'no packages/pro-core in this tree, so the allow-list has no Pro half to pin', PHP_EOL;
}

if ($list) {
    report('free routes', $free);
    report('declared free operations', $declaredFree);
    report('allow-listed Pro operations', $proOnly);
    report('allow-listed Business operations', $businessOnly);
}

printf(
    "free routes %d, declared %d (free %d, pro %d, business %d)\n",
    \count($free),
    \count($declared),
    \count($declaredFree),
    \count($proOnly),
    \count($businessOnly),
);

if ($failures !== []) {
    echo PHP_EOL;

    foreach ($failures as $failure) {
        echo 'FAIL: ', $failure, PHP_EOL;
    }

    exit(1);
}

echo 'OK: every free route is declared and every free declaration is served.', PHP_EOL;
exit(0);
