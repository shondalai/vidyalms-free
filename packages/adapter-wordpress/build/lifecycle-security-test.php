<?php

/** Offline regression probes for WordPress lifecycle and native shell boundaries. */
declare(strict_types=1);

namespace Psr\Log {
    // Deliberately PSR/log 1: narrower message parameters would fatal at load.
    interface LoggerInterface {
        public function emergency($message, array $context = []);
        public function alert($message, array $context = []);
        public function critical($message, array $context = []);
        public function error($message, array $context = []);
        public function warning($message, array $context = []);
        public function notice($message, array $context = []);
        public function info($message, array $context = []);
        public function debug($message, array $context = []);
        public function log($level, $message, array $context = []);
    }
}

namespace {
    use Shondalai\VidyaLMS\WordPress\Plugin;
    use Shondalai\VidyaLMS\WordPress\Content\CourseCpt;
    use Shondalai\VidyaLMS\WordPress\Content\Templates;
    use Shondalai\VidyaLMS\WordPress\Port\WpDatabase;
    use Shondalai\VidyaLMS\WordPress\Port\WpLogger;
    use Shondalai\VidyaLMS\WordPress\Port\WpMailer;
    use Shondalai\VidyaLMS\WordPress\Setup\Activator;
    use Shondalai\VidyaLMS\WordPress\Setup\PrivateFiles;
    use Shondalai\VidyaLMS\WordPress\Setup\SchemaLock;
    use Shondalai\VidyaLMS\WordPress\Setup\SchemaShape;
    use Shondalai\VidyaLMS\WordPress\Setup\Sites;
    use Shondalai\VidyaLMS\WordPress\Setup\Uninstaller;
    use Shondalai\VidyaLMS\WordPress\Files\PrivateStorageOwnership;
    use Shondalai\VidyaLMS\WordPress\Setup\Settings;
    use Shondalai\VidyaLMS\Core\Port\Database\UniqueConstraintViolation;
    use Shondalai\VidyaLMS\Core\Port\Mail\MailMessage;
    use Shondalai\VidyaLMS\Core\Tests\Support\FakeUsers;
    use Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator;

    require dirname(__DIR__, 2) . '/core/vendor/autoload.php';
    define('ABSPATH', __DIR__ . '/');
    define('VIDYALMS_PLUGIN_FILE', dirname(__DIR__) . '/vidyalms/vidyalms.php');
    define('VIDYALMS_PRO_PLUGIN_FILE', dirname(__DIR__, 2) . '/pro-wordpress/vidyalms-pro/vidyalms-pro.php');
    define('ARRAY_A', 'ARRAY_A');
    spl_autoload_register(static function (string $class): void {
        $proPrefix = 'Shondalai\\VidyaLMS\\Pro\\WordPress\\';
        if (str_starts_with($class, $proPrefix)) {
            require dirname(__DIR__, 2) . '/pro-wordpress/vidyalms-pro/src/' . str_replace('\\', '/', substr($class, strlen($proPrefix))) . '.php';
            return;
        }
        $prefix = 'Shondalai\\VidyaLMS\\WordPress\\';
        if (str_starts_with($class, $prefix)) {
            require dirname(__DIR__) . '/vidyalms/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        }
    });

    $hooks = $options = $deletedPosts = $deletedTerms = $scheduled = [];
    $site = 1; $sites = [1]; $stack = []; $allowed = false; $networkAdmin = false;
    $count = 0; $noCache = 0;
    $temporary = str_replace('\\', '/', sys_get_temp_dir()) . '/vidyalms-lifecycle-' . bin2hex(random_bytes(6));
    mkdir($temporary);
    define('VIDYALMS_PRIVATE_DIR', $temporary . '/custom');
    mkdir(VIDYALMS_PRIVATE_DIR);

    function check(bool $pass, string $message): void {
        global $count;
        if (!$pass) { throw new RuntimeException($message); }
        ++$count;
    }
    function add_action($name, $callback, $priority = 10, $args = 1): void { $GLOBALS['hooks'][$name][] = $callback; }
    function add_filter($name, $callback, $priority = 10, $args = 1): void { add_action($name, $callback); }
    function remove_action($name, $callback, $priority = 10): void {
        $GLOBALS['hooks'][$name] = array_filter($GLOBALS['hooks'][$name] ?? [], static fn ($entry) => $entry !== $callback);
    }
    function do_action($name, ...$args): void { foreach ($GLOBALS['hooks'][$name] ?? [] as $callback) { $callback(...$args); } }
    function apply_filters($name, $value, ...$args) { return $value; }
    function get_option($key, $default = false) { return $GLOBALS['options'][$GLOBALS['site']][$key] ?? $default; }
    function update_option($key, $value, $autoload = null): bool { $GLOBALS['options'][$GLOBALS['site']][$key] = $value; return true; }
    function add_option($key, $value, $deprecated = '', $autoload = null): bool {
        if (get_option($key) !== false) { return false; }
        return update_option($key, $value, $autoload);
    }
    function delete_option($key): bool { unset($GLOBALS['options'][$GLOBALS['site']][$key]); return true; }
    function current_user_can($capability): bool { return $GLOBALS['allowed']; }
    function is_network_admin(): bool { return $GLOBALS['networkAdmin']; }
    function is_admin(): bool { return false; }
    function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : stripslashes($value); }
    function is_multisite(): bool { return true; }
    function get_current_network_id(): int { return 1; }
    function get_current_blog_id(): int { return $GLOBALS['site']; }
    function get_site($id): object { return (object) ['blog_id' => $id, 'network_id' => 1]; }
    function get_network_option($id, $key, $default = false) { return ['vidyalms/vidyalms.php' => 1, 'vidyalms-pro/vidyalms-pro.php' => 1]; }
    function plugin_basename($path): string { return str_contains($path, '/vidyalms-pro/') ? 'vidyalms-pro/vidyalms-pro.php' : 'vidyalms/vidyalms.php'; }
    function get_sites($args): array { return array_slice($GLOBALS['sites'], $args['offset'], $args['number']); }
    function switch_to_blog($id): void {
        $GLOBALS['stack'][] = $GLOBALS['site']; $GLOBALS['site'] = $id;
        $GLOBALS['wpdb']->prefix = 'wp_' . $id . '_'; do_action('switch_blog');
    }
    function restore_current_blog(): void {
        $GLOBALS['site'] = array_pop($GLOBALS['stack']);
        $GLOBALS['wpdb']->prefix = 'wp_' . $GLOBALS['site'] . '_'; do_action('switch_blog');
    }
    function __($text, $domain = ''): string { return $text; }
    function _x($text, $context, $domain = ''): string { return $text; }
    function sanitize_title($text): string { return $text; }
    function sanitize_text_field($text): string { return $text; }
    function wp_strip_all_tags($text): string { return strip_tags($text); }
    function is_singular($type = ''): bool { return true; }
    function get_queried_object_id(): int { return 123; }
    function register_post_type($type, $args): void { $GLOBALS['cpt'] = $args; }
    function register_taxonomy($type, $posts, $args): void {}
    function taxonomy_exists($type): bool { return false; }
    function get_terms($args): array { return $GLOBALS['terms'][$GLOBALS['site']] ?? []; }
    function wp_delete_term($id, $taxonomy): bool {
        $GLOBALS['deletedTerms'][] = [$GLOBALS['site'], $id]; $GLOBALS['terms'][$GLOBALS['site']] = []; return true;
    }
    function wp_delete_post($id, $force): bool {
        $GLOBALS['deletedPosts'][] = [$GLOBALS['site'], $id]; $GLOBALS['posts'][$GLOBALS['site']] = []; return true;
    }
    function is_wp_error($value): bool { return false; }
    function wp_roles(): object { return (object) ['roles' => []]; }
    function remove_role($name): void {}
    function wp_clear_scheduled_hook($name): void { $GLOBALS['scheduled'][] = [$GLOBALS['site'], $name]; }
    function wp_unschedule_hook($name): void { wp_clear_scheduled_hook($name); }
    function wp_upload_dir($time = null, $create = true): array {
        return ['basedir' => $GLOBALS['temporary'] . '/site-' . $GLOBALS['site'], 'error' => false];
    }
    function wp_delete_file($path): void { unlink($path); }
    function nocache_headers(): void { ++$GLOBALS['noCache']; }
    function get_query_var($name, $default = '') { return $default; }
    function home_url($path = ''): string { return 'https://example.test/' . ltrim($path, '/'); }
    function add_query_arg($key, $value, $url = null): string {
        if (is_array($key)) { $url = $value; $args = $key; } else { $args = [$key => $value]; }
        $parts = explode('?', $url, 2); parse_str($parts[1] ?? '', $query);
        return $parts[0] . '?' . http_build_query(array_merge($query, $args), '', '&', PHP_QUERY_RFC3986);
    }
    function get_user_option($option) { return ''; }
    function wp_mail(...$args): bool {
        global $mailerObject, $mailMode, $sentSender;
        if ($mailMode === 'short') { return true; }
        do_action('phpmailer_init', $mailerObject);
        $sentSender = $mailerObject->Sender;
        if ($mailMode === 'throw') { throw new RuntimeException('SMTP failure'); }
        return $mailMode !== 'false';
    }
    class WP_Query {
        public function __construct(private bool $search = false, private bool $feed = false, private bool $singular = false) {}
        public function is_search(): bool { return $this->search; }
        public function is_feed(): bool { return $this->feed; }
        public function is_singular(): bool { return $this->singular; }
    }
    class WP_Post {
        public function __construct(public string $post_type) {}
    }
    class wpdb {
        public string $prefix = 'wp_1_', $dbname = 'test', $posts = 'wp_posts', $options = 'wp_options', $last_error = '';
        public array $queries = [], $columnRows = [], $fileRows = [], $fileTables = [];
        public bool $lockAvailable = true, $failWrite = false;
        public string $server = '10.11.8-MariaDB';
        public function prepare($sql, ...$args): string {
            if (isset($args[0]) && is_array($args[0])) { $args = $args[0]; }
            foreach ($args as $arg) { $sql = preg_replace_callback('/%[sid]/', static fn ($match) => $match[0] === '%i' ? '`' . $arg . '`' : "'" . $arg . "'", $sql, 1); }
            return $sql;
        }
        public function get_var($sql) {
            $this->queries[] = $sql;
            if (str_contains($sql, 'GET_LOCK')) { return $this->lockAvailable ? '1' : '0'; }
            if (str_contains($sql, 'SHOW TABLES') && str_contains($sql, 'submission_files')) { return $this->prefix . 'vidyalms_submission_files'; }
            foreach ($this->fileTables as $table => $rows) {
                if (str_contains($sql, 'SHOW TABLES') && str_contains($sql, 'vidyalms_' . $table . "'")) { return $this->prefix . 'vidyalms_' . $table; }
            }
            return null;
        }
        public function get_col($sql): array {
            $this->queries[] = $sql;
            if (str_contains($sql, 'SELECT option_name')) {
                foreach (['snapshot', 'erased', 'paid'] as $family) {
                    if (str_contains($sql, 'vidyalms_woocommerce_' . $family . '_')) {
                        return array_values(array_filter(array_keys($GLOBALS['options'][$GLOBALS['site']] ?? []), static fn ($key) => str_starts_with($key, 'vidyalms_woocommerce_' . $family . '_')));
                    }
                }
            }
            return str_contains($sql, 'SELECT ID') ? ($GLOBALS['posts'][$GLOBALS['site']] ?? []) : [];
        }
        public function get_results($sql, $format = ''): array {
            $this->queries[] = $sql;
            foreach ($this->fileTables as $table => $rows) {
                if (str_contains($sql, 'vidyalms_' . $table . '`')) { return $rows; }
            }
            return str_contains($sql, 'SHOW COLUMNS') ? $this->columnRows : $this->fileRows;
        }
        public function query($sql): int|false { $this->queries[] = $sql; return $this->failWrite ? false : 1; }
        public function suppress_errors($value = true): bool { return false; }
        public function db_server_info(): string { return $this->server; }
        public function esc_like($value): string { return $value; }
    }
    $wpdb = new wpdb();

    try {
        $logger = new WpLogger();
        $seen = null;
        add_action(WpLogger::ACTION, static function ($level, $message) use (&$seen): void { $seen = [$level, $message]; });
        $logger->info(new class implements Stringable { public function __toString(): string { return 'Hello {name}'; } }, ['name' => 'learner']);
        check($seen === ['info', 'Hello learner'], 'PSR/log 1 interface and stringable interpolation');
        $logger->log('info', 12);
        check($seen === ['info', '12'], 'PSR/log 1 accepts untyped message');

        $users = new FakeUsers(); $users->add(1);
        $mailer = new WpMailer(new StubTranslator(), $users);
        $message = new MailMessage(1, 'learner@example.test', 'test', subject: 'Test', bodyText: 'Text', bodyHtml: '<p>Text</p>', envelopeSender: 'bounce@example.test');
        foreach (['success', 'false', 'throw', 'short'] as $mailMode) {
            $mailerObject = (object) ['Sender' => 'site@example.test', 'AltBody' => 'original', 'Mailer' => 'smtp'];
            $sentSender = null; $failure = null;
            try { $mailer->send($message); } catch (RuntimeException $caught) { $failure = $caught; }
            check($mailerObject->Sender === 'site@example.test' && $mailerObject->AltBody === 'original', 'PHPMailer restored after ' . $mailMode);
            check(($hooks['phpmailer_init'] ?? []) === [], 'Listener removed after ' . $mailMode);
            check(($failure !== null) === in_array($mailMode, ['false', 'throw'], true), 'Mail failure propagation: ' . $mailMode);
            check($mailMode === 'short' || $sentSender === 'bounce@example.test', 'Envelope used for current message');
        }
        $mailMode = 'success';
        $mailer->send($message);
        $mailer->send(new MailMessage(1, 'learner@example.test', 'plain', subject: 'Plain', bodyText: 'Plain'));
        check($sentSender === 'site@example.test', 'Subsequent mail does not inherit the LMS envelope');

        $db = new WpDatabase($wpdb); $wpdb->failWrite = true;
        $statement = new ReflectionMethod($db, 'statement');
        foreach (["Duplicate entry 'x' for key 'courses.slug_uq'" => true, "Duplicate entry 'x' for key 'slug_uq'" => true,
            'Cannot add or update a child row: a foreign key constraint fails' => false, "Table 'duplicate' doesn't exist" => false] as $error => $duplicate) {
            $wpdb->last_error = $error;
            try { $statement->invoke($db, 'INSERT fixture', 'courses'); throw new LogicException('Write must fail'); }
            catch (RuntimeException $failure) { check(($failure instanceof UniqueConstraintViolation) === $duplicate, 'wpdb error classification: ' . $error); }
        }
        $wpdb->failWrite = false; $wpdb->last_error = '';

        CourseCpt::registerPostType();
        check(!$cpt['public'] && !$cpt['show_in_rest'] && $cpt['exclude_from_search'] && $cpt['publicly_queryable'], 'Shell discovery disabled, permalink retained');
        check(!$cpt['embeddable'], 'Course shells explicitly disable native embedding');
        $embed = ['title' => 'Private course title', 'author_name' => 'Private teacher', 'html' => '<iframe src="private"></iframe>'];
        check(CourseCpt::excludeOembed($embed, new WP_Post(CourseCpt::POST_TYPE)) === false, 'Native oEmbed cannot expose any course shell title, author or HTML');
        check(CourseCpt::excludeOembed($embed, new WP_Post('post')) === $embed, 'Ordinary WordPress embeds remain unchanged');
        check(CourseCpt::excludeOembed(false, new WP_Post('post')) === false, 'An earlier refused embed stays refused');
        foreach ([[true, false], [false, true], [true, true], [false, false]] as [$search, $feed]) {
            $where = CourseCpt::excludeDiscovery('WHERE 1=1', new WP_Query($search, $feed));
            check(str_contains($where, 'post_type <>'), 'Every non-singular front-end query excludes course shells');
        }
        check(CourseCpt::excludeDiscovery('WHERE 1=1', new WP_Query(false, false, true)) === 'WHERE 1=1', 'Guarded singular course permalinks retain their shell');
        $postType = new stdClass();
        check(CourseCpt::excludeSitemap(['post' => $postType, CourseCpt::POST_TYPE => new stdClass()]) === ['post' => $postType], 'Shell omitted from sitemap');
        check((CourseCpt::robots(['index' => true])['noindex'] ?? false) === true, 'Missing or broken course lookup fails closed for robots');
        Templates::certificateContext('');
        Templates::certificateContext('unknown');
        check(defined('DONOTCACHEPAGE') && DONOTCACHEPAGE && $noCache === 2, 'Verification refuses cache with and without a code or kernel');

        $sql = "CREATE TABLE wp_fixture (\n `id` int unsigned NOT NULL,\n `flag` tinyint(1) NOT NULL,\n `payload` json NULL,\n `count` int NOT NULL,\n PRIMARY KEY  (`id`)\n)";
        $wpdb->columnRows = [['Field' => 'id', 'Type' => 'int(10) unsigned'], ['Field' => 'flag', 'Type' => 'tinyint(1)'], ['Field' => 'payload', 'Type' => 'longtext'], ['Field' => 'count', 'Type' => 'int(10) unsigned']];
        $normal = SchemaShape::forComparison([$sql])[0];
        check(str_contains($normal, '`id` int(10) unsigned') && str_contains($normal, '`payload` longtext'), 'MariaDB width and JSON alias do not churn');
        check(str_contains($normal, '`count` int NOT NULL'), 'Real signedness change retained');
        $wpdb->server = '5.7.44';
        check(str_contains(SchemaShape::forComparison([$sql])[0], '`payload` json'), 'MySQL 5.7 JSON remains native');
        $wpdb->columnRows = [];
        check(SchemaShape::forComparison([$sql])[0] === $sql, 'New tables retain full authored types');

        $wpdb->queries = [];
        try { SchemaLock::run(static function (): void { throw new RuntimeException('failed upgrade'); }); } catch (RuntimeException) {}
        check(str_contains(end($wpdb->queries), 'RELEASE_LOCK'), 'Failure releases schema lock');
        $wpdb->lockAvailable = false; $ran = false;
        check(!SchemaLock::run(static function () use (&$ran): void { $ran = true; }) && !$ran, 'Concurrent schema work skipped');
        $wpdb->lockAvailable = true;

        Plugin::instance()->boot();
        check(isset($hooks['wp_initialize_site'], $hooks['switch_blog']), 'New-site and site-switch hooks registered');
        $wpdb->queries = [];
        Plugin::instance()->upgradeSchemaIfNeeded();
        check($wpdb->queries === [] && $options === [], 'Anonymous admin_init performs no schema or option writes');
        $proPlugin = \Shondalai\VidyaLMS\Pro\WordPress\Plugin::instance();
        $proPlugin->boot();
        $proPlugin->upgradeSchemaIfNeeded();
        check($wpdb->queries === [] && $options === [], 'Anonymous Pro admin_init performs no schema or option writes');
        check(!\Shondalai\VidyaLMS\Pro\WordPress\Setup\Schema::ensureCurrent()
            && !\Shondalai\VidyaLMS\Pro\WordPress\Setup\Schema::apply() && $wpdb->queries === [], 'Pro kernel and unauthorized apply never start DDL');
        \Shondalai\VidyaLMS\Pro\WordPress\Plugin::markAttached();
        Sites::on(2, static function (): void {});
        check(!\Shondalai\VidyaLMS\Pro\WordPress\Plugin::isAttached(), 'Pro state resets on blog switch');
        $sites = range(1, 205); $visited = [];
        Sites::each(static function () use (&$visited): void { $visited[] = get_current_blog_id(); });
        check($visited === $sites && $site === 1 && $stack === [], 'All sites processed over multiple bounded pages');
        $proVisited = [];
        \Shondalai\VidyaLMS\Pro\WordPress\Setup\Sites::each(static function () use (&$proVisited): void { $proVisited[] = get_current_blog_id(); });
        check($proVisited === $sites && $site === 1, 'Pro enumerates all sites and restores context independently');
        try { Sites::on(3, static function (): void { throw new RuntimeException('site failure'); }); } catch (RuntimeException) {}
        check($site === 1 && $stack === [], 'Failure restores original blog');
        $sites = [1, 2]; $allowed = true; $networkAdmin = true;
        foreach ($sites as $id) { $options[$id] = [Activator::OPTION_SCHEMA_VERSION => Activator::SCHEMA_VERSION, Activator::OPTION_PLUGIN_VERSION => Plugin::VERSION, Activator::OPTION_SEEDED_DATA_VERSION => Activator::SEEDED_DATA_VERSION]; }
        $wpdb->queries = [];
        Plugin::instance()->upgradeSchemaIfNeeded();
        check(count(array_filter($wpdb->queries, static fn ($query) => str_contains($query, 'GET_LOCK'))) === 1 && $site === 1, 'Network admin upgrades only the current site');
        Sites::on(2, static fn () => Plugin::instance()->upgradeSchemaIfNeeded());
        $locks = array_values(array_filter($wpdb->queries, static fn ($query) => str_contains($query, 'GET_LOCK')));
        check(count($locks) === 2 && $locks[0] !== $locks[1] && $site === 1, 'A later subsite request uses its own schema lock and restores context');

        $options[1][Uninstaller::DELETE_DATA_OPTION] = 'false';
        $options[2][Uninstaller::DELETE_DATA_OPTION] = '1';
        $options[1]['vidyalms_course_shells'] = [1 => 11]; $options[2]['vidyalms_course_shells'] = [1 => 21];
        foreach ([1, 2] as $id) {
            foreach (['snapshot', 'paid', 'erased'] as $family) {
                $options[$id]['vidyalms_woocommerce_' . $family . '_31'] = 1;
            }
            $options[$id]['vidyalms_woocommerce_paid_foreign'] = 'keep';
        }
        $posts = [1 => [11], 2 => [21, 22]]; $terms = [1 => [10], 2 => [20]];
        mkdir($temporary . '/site-1/vidyalms-private', 0777, true);
        mkdir($temporary . '/site-2/vidyalms-private', 0777, true);
        mkdir($temporary . '/site-1/vidyalms', 0777, true);
        mkdir($temporary . '/site-2/vidyalms', 0777, true);
        file_put_contents($temporary . '/site-1/vidyalms-private/keep.txt', 'keep');
        file_put_contents($temporary . '/site-2/vidyalms-private/delete.txt', 'delete');
        file_put_contents($temporary . '/site-1/vidyalms/keep.txt', 'keep');
        file_put_contents($temporary . '/site-2/vidyalms/delete.txt', 'delete');
        $root1 = PrivateStorageOwnership::resolveRoot($temporary . '/site-1', Settings::privateStorageDir());
        $root2 = '';
        Sites::on(2, static function () use (&$root2, $temporary): void {
            $root2 = PrivateStorageOwnership::resolveRoot($temporary . '/site-2', Settings::privateStorageDir());
        });
        mkdir($root1, 0777, true);
        check(PrivateStorageOwnership::claimIfEmpty($root1), 'Empty site root can establish ownership');
        file_put_contents($root1 . '/owned.txt', 'preserved site');
        mkdir($root2, 0777, true);
        Sites::on(2, static function () use ($root2): void {
            check(PrivateStorageOwnership::claimIfEmpty($root2), 'Second site establishes exclusive ownership');
        });
        mkdir($root2 . '/imports/moodle/digest', 0777, true);
        mkdir($root2 . '/questions/5', 0777, true);
        file_put_contents($root2 . '/owned.txt', 'owned');
        file_put_contents($root2 . '/unrelated.txt', 'keep');
        file_put_contents($root2 . '/imports/moodle/digest/attachment.pdf', 'Moodle resource');
        file_put_contents($root2 . '/questions/5/background.png', 'authored media');
        file_put_contents($root2 . '/imports/moodle/unattached.pdf', 'keep unmarked');
        file_put_contents(VIDYALMS_PRIVATE_DIR . '/unrelated.txt', 'keep');
        $wpdb->fileRows = [['id' => 1, 'file_path' => 'owned.txt']];
        $wpdb->fileTables = [
            'lesson_content' => [['id' => 1, 'file_path' => json_encode(['_v' => 1, 'files' => [['path' => 'imports/moodle/digest/attachment.pdf']], 'path' => 'unrelated.txt'])]],
            'question_definitions' => [['id' => 1, 'file_path' => json_encode(['canvas' => ['background' => ['storage_ref' => 'questions/5/background.png']], 'text' => 'unrelated.txt'])]],
        ];
        Uninstaller::uninstall();
        check($posts[1] === [11] && $deletedPosts === [[2, 21], [2, 22]], 'Posts deleted only for opted-in site');
        check($terms[1] === [10] && $deletedTerms === [[2, 20]], 'Terms deleted only for opted-in site');
        check(isset($options[1]['vidyalms_course_shells']) && !isset($options[2]['vidyalms_course_shells']), 'Preserved site retains settings and shell map');
        check(isset($options[1]['vidyalms_woocommerce_snapshot_31'], $options[2]['vidyalms_woocommerce_paid_foreign'])
            && !isset($options[2]['vidyalms_woocommerce_snapshot_31'], $options[2]['vidyalms_woocommerce_paid_31'], $options[2]['vidyalms_woocommerce_erased_31']), 'Only opted-in site owned numeric WooCommerce option families removed');
        check(is_file($temporary . '/site-1/vidyalms-private/keep.txt') && !is_dir($temporary . '/site-2/vidyalms-private'), 'Legacy private files follow each site preference');
        check(is_file($temporary . '/site-1/vidyalms/keep.txt') && !is_dir($temporary . '/site-2/vidyalms'), 'Public media follows each site preference');
        check(!is_dir($root2), 'Opted-in site removes its verified owned root');
        check(!file_exists($root2 . '/imports/moodle/digest/attachment.pdf') && !file_exists($root2 . '/questions/5/background.png'), 'Moodle resource and immutable question media references cleaned');
        check(!file_exists($root2 . '/imports/moodle/unattached.pdf'), 'Owned root removes unattached imports');
        check(is_file($root1 . '/owned.txt') && PrivateStorageOwnership::isOwned($root1), 'Non-opted-in site preserves its owned root and token');
        check(is_file(VIDYALMS_PRIVATE_DIR . '/unrelated.txt'), 'Shared external parent is never removed');
        check($site === 1 && count($scheduled) === 16, 'Uninstall stops scheduled work on every site and restores context');

        Sites::on(3, static function () use ($temporary): void {
            $root = PrivateStorageOwnership::resolveRoot($temporary . '/site-3', Settings::privateStorageDir());
            mkdir($root, 0777, true);
            check(PrivateStorageOwnership::claimIfEmpty($root), 'New site owns only its own empty root');
            foreach (['imports/moodle/unattached.pdf', 'lesson-files/authored.pdf', 'attempts/unattached.wav', 'submissions/old.txt'] as $path) {
                mkdir(dirname($root . '/' . $path), 0777, true);
                file_put_contents($root . '/' . $path, 'owned');
            }
            update_option(Uninstaller::DELETE_DATA_OPTION, '1');
            Uninstaller::uninstallSite();
            check(!is_dir($root) && get_option(PrivateStorageOwnership::TOKEN_OPTION) === false, 'Owned root removes authored and unattached files, then its ownership token');
        });
        check(is_file($root1 . '/owned.txt') && is_file(VIDYALMS_PRIVATE_DIR . '/unrelated.txt'), 'Owned-site deletion preserves sibling sites and shared parent data');

        Sites::on(4, static function () use ($temporary): void {
            $root = PrivateStorageOwnership::resolveRoot($temporary . '/site-4', Settings::privateStorageDir());
            mkdir($root, 0777, true);
            file_put_contents($root . '/owned.txt', 'preserve');
            $valid = json_decode(PrivateStorageOwnership::markerContentsForCurrentSite(), true);
            foreach (['blog_id' => 3, 'network_id' => 2, 'site_token' => str_repeat('0', 64), 'version' => 99] as $key => $value) {
                file_put_contents($root . '/' . PrivateStorageOwnership::MARKER, json_encode([$key => $value] + $valid));
                try { PrivateFiles::deleteForSite(); throw new LogicException('Foreign marker accepted'); }
                catch (RuntimeException) { check(is_file($root . '/owned.txt'), 'Foreign ' . $key . ' preserves all files'); }
            }
            file_put_contents($root . '/' . PrivateStorageOwnership::MARKER, '{malformed');
            try { PrivateFiles::deleteForSite(); throw new LogicException('Malformed marker accepted'); }
            catch (RuntimeException) { check(is_file($root . '/owned.txt'), 'Malformed marker preserves all files'); }
        });
        try { PrivateFiles::deleteTree($temporary . '/../unrelated'); throw new LogicException('Traversal accepted'); } catch (RuntimeException) { ++$count; }
    } finally {
        PrivateFiles::deleteTree($temporary);
    }
    echo "PASS: {$count} WordPress lifecycle/security checks (offline host doubles).\n";
}
