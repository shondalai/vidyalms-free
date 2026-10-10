<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Port;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Shondalai\VidyaLMS\Core\Port\Database\Sql\SqlDialect;

/**
 * What Joomla's SQL does differently, and nothing else.
 *
 * Compiling a Query is SqlQueryCompiler's, shared with the WordPress adapter
 * and the test harness: the condition compiler with its ORed group, the LIKE
 * escape, the conditional COUNT, the AVG rate and the nulls-last leading key
 * were the same code written out three times, and the port contract proved
 * they agreed only after the fact.
 *
 * The four decisions left here are genuinely Joomla's: quoteName does the
 * quoting, "#__" is the prefix the driver replaces, values are bound by name
 * on the query object, and MySQL needs a doubled backslash inside a string
 * literal for LIKE's escape character. LIMIT is not written into the
 * statement at all, because QueryInterface::setLimit() owns it.
 *
 * @since 1.0.0
 */
final class JoomlaSqlDialect implements SqlDialect
{
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/D';

    /** @var array<string, mixed> */
    private array $values = [];

    /** @var array<string, string> */
    private array $types = [];

    private int $index = 0;

    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly string $tablePrefix,
        private readonly string $hostPrefix,
    ) {
    }

    public function quoteIdentifier(string $identifier): string
    {
        if (preg_match(self::IDENTIFIER, $identifier) !== 1) {
            throw new \InvalidArgumentException(sprintf('Unsupported identifier "%s".', $identifier));
        }

        return $this->db->quoteName($identifier);
    }

    /**
     * A host table belongs to another extension ("#__cq_courses"), so it takes
     * Joomla's own prefix and none of ours. Only a Query::fromHostTable() read
     * reaches that branch; every write stays inside our own tables.
     */
    public function physicalTable(string $table, bool $hostTable): string
    {
        if (preg_match(self::IDENTIFIER, $table) !== 1) {
            throw new \InvalidArgumentException(sprintf('Unsupported table name "%s".', $table));
        }

        return $this->db->quoteName(($hostTable ? $this->hostPrefix : $this->tablePrefix) . $table);
    }

    public function placeholder(mixed $value): string
    {
        $key = ':c' . $this->index++;
        $normalised = self::normalise($value);

        $this->values[$key] = $normalised;
        $this->types[$key] = self::parameterType($normalised);

        return $key;
    }

    /**
     * A backslash is itself an escape inside a MySQL string literal, so the
     * one escape character LIKE is told about is written twice.
     */
    public function likeEscape(): string
    {
        return " ESCAPE '\\\\'";
    }

    /** QueryInterface::setLimit() writes the limit, so the statement does not. */
    public function limitClause(?int $limit, int $offset): string
    {
        return '';
    }

    /**
     * Binds array elements, never a loop variable: Joomla binds by reference
     * and a shared variable would collapse every placeholder onto one value.
     */
    public function bindTo(\Joomla\Database\QueryInterface $query): void
    {
        foreach ($this->types as $key => $type) {
            $query->bind($key, $this->values[$key], $type);
        }
    }

    private static function normalise(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (\is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }

    private static function parameterType(mixed $value): string
    {
        return match (true) {
            $value === null => ParameterType::NULL,
            \is_int($value) => ParameterType::INTEGER,
            default => ParameterType::STRING,
        };
    }
}
