<?php

/** Real course access policy with WordPress transport doubles; no site data. */
declare(strict_types=1);

use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;
use Shondalai\VidyaLMS\WordPress\Content\CourseCpt;
use Shondalai\VidyaLMS\WordPress\Plugin;

require dirname(__DIR__, 2) . '/core/vendor/autoload.php';
define('ABSPATH', __DIR__ . '/');
spl_autoload_register(static function (string $class): void {
    $prefix = 'Shondalai\\VidyaLMS\\WordPress\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/vidyalms/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
class WP_Post {
    public function __construct(public string $post_type, public string $post_name) {}
}
class NotFoundResponse extends RuntimeException {}
function is_singular($type = ''): bool { return $GLOBALS['post']->post_type === $type; }
function get_queried_object(): WP_Post { return $GLOBALS['post']; }
function get_current_user_id(): int { return $GLOBALS['userId']; }
function esc_html__($text, $domain = ''): string { return $text; }
function nocache_headers(): void { ++$GLOBALS['noCache']; }
function wp_die($message, $title, $args): never {
    if ($args['response'] !== 404 || str_contains($message . $title, 'Secret')) {
        throw new LogicException('Denial exposed course information or had the wrong status.');
    }
    throw new NotFoundResponse($message);
}
$env = TestPorts::create();
$kernel = new CoreKernel($env->ports);
$property = new ReflectionProperty(Plugin::class, 'kernel');
$property->setValue(Plugin::instance(), $kernel);
$now = '2026-10-05 00:00:00';
$ids = [];
foreach (['public', 'unlisted', 'private'] as $visibility) {
    $ids[$visibility] = $env->database->insert('courses', [
        'title' => 'Secret ' . $visibility, 'slug' => $visibility, 'visibility' => $visibility,
        'state' => 'published', 'created_by' => 1, 'created_at' => $now, 'updated_at' => $now,
    ]);
}
$env->database->insert('enrolments', [
    'course_id' => $ids['private'], 'user_id' => 7, 'entitlement_id' => 1, 'status' => 'active',
    'enrolled_at' => $now, 'created_at' => $now, 'updated_at' => $now,
]);
$checks = 0; $noCache = 0;
foreach ([['public', 0, false], ['unlisted', 0, false], ['private', 0, true], ['private', 8, true], ['private', 7, false], ['missing', 0, true]] as [$slug, $userId, $denied]) {
    $post = new WP_Post(CourseCpt::POST_TYPE, $slug);
    $actual = false;
    try { CourseCpt::guardSingular(); } catch (NotFoundResponse) { $actual = true; }
    if ($actual !== $denied) { throw new RuntimeException('Wrong access for ' . $slug . ' user ' . $userId); }
    ++$checks;
}
if ($noCache !== 3 || !defined('DONOTCACHEPAGE') || !DONOTCACHEPAGE) { throw new RuntimeException('Denied responses must not cache.'); }
++$checks;
$post = new WP_Post('post', 'ordinary');
CourseCpt::guardSingular();
++$checks;
$property->setValue(Plugin::instance(), (new ReflectionClass(CoreKernel::class))->newInstanceWithoutConstructor());
$post = new WP_Post(CourseCpt::POST_TYPE, 'public');
try { CourseCpt::guardSingular(); throw new LogicException('Unavailable policy was allowed'); }
catch (NotFoundResponse) { ++$checks; }
echo "PASS: {$checks} course disclosure checks (real core policy, isolated SQLite, WordPress transport doubles).\n";
