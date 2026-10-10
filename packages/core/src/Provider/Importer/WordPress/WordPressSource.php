<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\WordPress;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The reads every WordPress LMS importer makes of WordPress itself.
 *
 * All three products in this wave keep their courses in `posts` and their
 * settings in `postmeta`, so the four core tables below are read the same way
 * whichever product is being migrated. Each product's own tables live on its
 * own subclass, which declares them in a closed constant list of its own.
 *
 * ## Why the table names are constants and never arguments
 *
 * A host table name reaches the database as an identifier and not as a bound
 * value, which is why `Query::fromHostTable()` exists and why nothing here
 * takes a table name from an option, a source selector or a row. The Community
 * Quiz importer takes a prefix because two Community Quiz installs genuinely
 * can share one Joomla database under two prefixes; WordPress has no such
 * shape, because `$wpdb->prefix` is the install and the adapter already
 * supplies it. So the whole vocabulary is `TABLES` on this class and the
 * subclass's own `productTables()`, and `read()` refuses anything outside them
 * before a query is built. A caller cannot name a table this file does not
 * already know.
 *
 * ## Reads only
 *
 * `DatabasePort`'s write methods take a logical table name and have no host
 * form, so an importer physically cannot write a table another plugin owns.
 * Everything here selects.
 */
abstract class WordPressSource
{
    /** The WordPress core tables every product reader shares. */
    public const TABLE_POSTS = 'posts';
    public const TABLE_POSTMETA = 'postmeta';
    public const TABLE_USERMETA = 'usermeta';
    public const TABLE_OPTIONS = 'options';

    /**
     * The closed list. `table()` refuses everything else, including anything a
     * subclass forgot to declare.
     *
     * @var list<string>
     */
    private const TABLES = [self::TABLE_POSTS, self::TABLE_POSTMETA, self::TABLE_USERMETA, self::TABLE_OPTIONS];

    /**
     * The columns of `posts` every reader wants, listed once.
     *
     * Columns are listed explicitly rather than selected with a wildcard for
     * the reason the Community Quiz reader gives: this is a foreign schema and
     * the list is the contract with it. A column no importer reads is a column
     * WordPress may change without breaking a migration.
     *
     * @var list<string>
     */
    protected const POST_COLUMNS = [
        'ID',
        'post_author',
        'post_parent',
        'post_title',
        'post_name',
        'post_excerpt',
        'post_content',
        'post_status',
        'post_type',
        'menu_order',
        'post_date',
    ];

    /**
     * How many rows one meta read takes at a time. A course with two thousand
     * lessons asks for its lesson meta in one query per page rather than one
     * query per lesson, and the page keeps that query inside what any host's
     * placeholder limit takes.
     */
    protected const PAGE = 200;

    /** @var array<string, array<int, array<string, string>>> */
    private array $userMetaCache = [];

    final public function beginRun(): void
    {
        $this->userMetaCache = [];
    }

    public function __construct(
        protected readonly DatabasePort $database,
    ) {
    }

    /**
     * Every table this reader may name, core plus the product's own. It is
     * public because the honest refusal an absent product earns is written
     * from it.
     *
     * @return list<string>
     */
    final public function tables(): array
    {
        return array_values(array_unique([...self::TABLES, ...$this->productTables()]));
    }

    /**
     * The product's own tables, declared by the subclass as a constant list.
     *
     * @return list<string>
     */
    abstract protected function productTables(): array;

    /**
     * Whether this product is installed here at all, answered from its own
     * tables or options rather than from what the operator typed.
     */
    abstract public function isInstalled(): bool;

    /**
     * A query against one of the declared tables.
     *
     * @throws \LogicException when the name is not in the closed list, which
     *                         is a programming error rather than an operator's
     */
    final protected function read(string $table, string $alias = ''): Query
    {
        if (!\in_array($table, $this->tables(), true)) {
            throw new \LogicException(sprintf(
                '"%s" is not one of the tables %s declares, and a table name is never taken from input.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $table,
                static::class,
            ));
        }

        return Query::fromHostTable($table, $alias);
    }

    /**
     * Whether a declared table is really there. A product half removed leaves
     * its posts and drops its tables, so every product probe asks this rather
     * than assuming an installed plugin.
     */
    final protected function tableIsPresent(string $table): bool
    {
        try {
            $this->database->count($this->read($table)->select(['*'])->limit(1));

            return true;
        } catch (\LogicException $programmingError) {
            throw $programmingError;
        } catch (\Throwable) {
            // A missing table is a driver error on every host, and this is a
            // detection question rather than a run: the answer is "no".
            return false;
        }
    }

    /**
     * How many published-or-drafted posts of this type the site has, which is
     * the other half of a product probe: LearnDash's tables survive a
     * deactivated plugin, and its courses are what an operator means by
     * "LearnDash is on this site".
     */
    final protected function postCount(string $postType): int
    {
        try {
            return $this->database->count(
                $this->read(self::TABLE_POSTS)->select(['ID'])->where('post_type', '=', $postType),
            );
        } catch (\LogicException $programmingError) {
            throw $programmingError;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Posts of one type, newest last, with the columns every reader uses.
     *
     * @param list<string> $states post_status values, empty meaning every state
     *
     * @return list<array<string, mixed>>
     */
    final protected function posts(string $postType, array $states = [], int $limit = 0): array
    {
        $query = $this->read(self::TABLE_POSTS)
            ->select(self::POST_COLUMNS)
            ->where('post_type', '=', $postType)
            ->orderBy('menu_order')
            ->orderBy('ID');

        if ($states !== []) {
            $query->whereIn('post_status', $states);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $this->database->selectAll($query);
    }

    /**
     * The posts one meta key points at, in curriculum order.
     *
     * Two queries rather than one join, deliberately. The join reads exactly
     * the same and the pair reads the same on every host, but the pair keeps
     * every column unqualified, which keeps this file's select lists identical
     * to the ones the single table reads use and leaves nothing to get wrong
     * when a product turns out to store its parent link twice.
     *
     * @param list<string> $states
     *
     * @return list<array<string, mixed>>
     */
    final protected function postsWithMeta(string $postType, string $metaKey, string $metaValue, array $states = []): array
    {
        $ids = [];

        foreach (
            $this->database->selectAll(
                $this->read(self::TABLE_POSTMETA)
                ->select(['post_id'])
                ->where('meta_key', '=', $metaKey)
                ->where('meta_value', '=', $metaValue)
                ->orderBy('post_id'),
            ) as $row
        ) {
            $ids[] = self::id($row['post_id'] ?? null);
        }

        return $this->postsByIds($ids, $postType, $states);
    }

    /**
     * @param list<int>    $postIds
     * @param list<string> $states
     *
     * @return list<array<string, mixed>>
     */
    final protected function postsByIds(array $postIds, string $postType = '', array $states = []): array
    {
        $found = [];

        foreach ($this->pages($postIds) as $page) {
            $query = $this->read(self::TABLE_POSTS)
                ->select(self::POST_COLUMNS)
                ->whereIn('ID', $page)
                ->orderBy('menu_order')
                ->orderBy('ID');

            if ($postType !== '') {
                $query->where('post_type', '=', $postType);
            }

            if ($states !== []) {
                $query->whereIn('post_status', $states);
            }

            foreach ($this->database->selectAll($query) as $row) {
                $found[] = $row;
            }
        }

        // The paging is what makes a two thousand lesson course one query per
        // page rather than one per lesson, and it is also what would let page
        // two's first lesson sort before page one's last. The order is the
        // curriculum's, so it is settled here over the whole set.
        usort($found, static function (array $left, array $right): int {
            return [self::id($left['menu_order'] ?? null), self::id($left['ID'] ?? null)]
                <=> [self::id($right['menu_order'] ?? null), self::id($right['ID'] ?? null)];
        });

        return $found;
    }

    /**
     * One post by id, whatever its type.
     *
     * @return array<string, mixed>|null
     */
    final protected function post(int $postId): ?array
    {
        if ($postId < 1) {
            return null;
        }

        return $this->database->selectOne(
            $this->read(self::TABLE_POSTS)
                ->select(self::POST_COLUMNS)
                ->where('ID', '=', $postId),
        );
    }

    /**
     * The meta of many posts at once, as postId => key => first value.
     *
     * WordPress allows repeated keys and this keeps the first, because every
     * key these three products read is single valued. A reader needing every
     * value of a repeated key asks metaList() instead.
     *
     * @param list<int>    $postIds
     * @param list<string> $keys     empty meaning every key
     *
     * @return array<int, array<string, string>>
     */
    final protected function meta(array $postIds, array $keys = []): array
    {
        $found = [];

        foreach ($this->pages($postIds) as $page) {
            $query = $this->read(self::TABLE_POSTMETA)
                ->select(['post_id', 'meta_key', 'meta_value'])
                ->whereIn('post_id', $page)
                ->orderBy('meta_id');

            if ($keys !== []) {
                $query->whereIn('meta_key', $keys);
            }

            foreach ($this->database->selectAll($query) as $row) {
                $postId = (int) ($row['post_id'] ?? 0);
                $key = (string) ($row['meta_key'] ?? '');

                if ($postId < 1 || $key === '' || isset($found[$postId][$key])) {
                    continue;
                }

                $found[$postId][$key] = self::text($row['meta_value'] ?? null);
            }
        }

        return $found;
    }

    /**
     * Every value of one repeated meta key on one post, in insertion order.
     * LifterLMS stores a quiz question's choices this way.
     *
     * @return list<string>
     */
    final protected function metaList(int $postId, string $key): array
    {
        if ($postId < 1) {
            return [];
        }

        $values = [];

        foreach (
            $this->database->selectAll(
                $this->read(self::TABLE_POSTMETA)
                ->select(['meta_value'])
                ->where('post_id', '=', $postId)
                ->where('meta_key', '=', $key)
                ->orderBy('meta_id'),
            ) as $row
        ) {
            $values[] = self::text($row['meta_value'] ?? null);
        }

        return $values;
    }

    /**
     * One site option, or the empty string when it is not set.
     */
    final protected function option(string $name): string
    {
        $row = $this->database->selectOne(
            $this->read(self::TABLE_OPTIONS)->select(['option_value'])->where('option_name', '=', $name),
        );

        return $row === null ? '' : self::text($row['option_value'] ?? null);
    }

    /**
     * User meta rows whose key matches a prefix, as userId => key => value.
     * Both Tutor and LearnDash record per course facts under a key that
     * carries the course id in its name, which is a shape this product refuses
     * for its own data and has to read for theirs.
     *
     * @return array<int, array<string, string>>
     */
    final protected function userMetaLike(string $prefix): array
    {
        if (isset($this->userMetaCache[$prefix])) {
            return $this->userMetaCache[$prefix];
        }
        $found = [];

        foreach (
            $this->database->selectAll(
                $this->read(self::TABLE_USERMETA)
                ->select(['user_id', 'meta_key', 'meta_value'])
                ->whereStartsWith('meta_key', $prefix)
                ->orderBy('umeta_id'),
            ) as $row
        ) {
            $userId = (int) ($row['user_id'] ?? 0);
            $key = (string) ($row['meta_key'] ?? '');

            if ($userId < 1 || !str_starts_with($key, $prefix)) {
                continue;
            }

            $found[$userId][$key] = self::text($row['meta_value'] ?? null);
        }

        return $this->userMetaCache[$prefix] = $found;
    }

    /**
     * @param list<int> $ids
     *
     * @return list<list<int>>
     */
    final protected function pages(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        return $ids === [] ? [] : array_chunk($ids, self::PAGE);
    }

    /**
     * A scalar cell as a trimmed string. Everything read here is foreign data,
     * so nothing assumes a column's type.
     */
    final public static function text(mixed $value): string
    {
        return \is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * A cell as a positive integer, or zero.
     */
    final public static function id(mixed $value): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : 0;
    }

    /**
     * A serialised WordPress meta value as a flat map of its scalar entries.
     *
     * All three products keep at least one settings bag as a PHP serialised
     * array, so this lives here rather than on one of them.
     *
     * @return array<string, string>
     */
    final public static function properties(string $serialised): array
    {
        return self::flatten(self::decode($serialised));
    }

    /**
     * A serialised value as PHP data, with objects refused rather than
     * reconstructed.
     *
     * `allowed_classes => false` is the whole security of this file: a payload
     * naming a class that happens to exist in this process cannot cause that
     * class to be constructed or its `__wakeup` to run. What comes back for an
     * object is `__PHP_Incomplete_Class`, which `flatten()` reads as data.
     */
    final public static function decode(string $serialised): mixed
    {
        if (trim($serialised) === '') {
            return null;
        }

        // Malformed serialized source data must not emit warnings; object creation is disabled and the handler is restored.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
        set_error_handler(static fn (): bool => true);

        try {
            /** @var mixed $value */
            $value = unserialize($serialised, ['allowed_classes' => false]);
        } catch (\Throwable) {
            return null;
        } finally {
            restore_error_handler();
        }

        return $value === false && $serialised !== 'b:0;' ? null : $value;
    }

    /**
     * One `__PHP_Incomplete_Class` or array as a scalar map with the private
     * name mangling removed: a private property arrives keyed
     * "\0ClassName\0prop" and leaves keyed "prop".
     *
     * @return array<string, string>
     */
    final public static function flatten(mixed $value): array
    {
        if (\is_object($value)) {
            /** @var array<string, mixed> $value */
            $value = (array) $value;
        }

        if (!\is_array($value)) {
            return [];
        }

        $flat = [];

        /** @var mixed $entry */
        foreach ($value as $key => $entry) {
            if (!\is_scalar($entry)) {
                continue;
            }

            $name = (string) $key;
            $mangled = strrpos($name, "\0");

            if ($mangled !== false) {
                $name = substr($name, $mangled + 1);
            }

            if ($name === '' || $name === '__PHP_Incomplete_Class_Name') {
                continue;
            }

            $flat[$name] = trim((string) $entry);
        }

        return $flat;
    }
}
