<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Schema;

/**
 * The neutral column type vocabulary. Compilers map these to MySQL types for
 * Joomla install files and WordPress dbDelta statements, and to SQLite types
 * for the test harness.
 */
enum ColumnType: string
{
    case Identifier = 'pk';
    case Integer = 'int';
    case UnsignedInteger = 'uint';
    case BigInteger = 'bigint';
    case Boolean = 'bool';
    case Text = 'string';
    case LongText = 'text';
    case MediumText = 'mediumtext';
    case Json = 'json';
    case DateTime = 'datetime';
    case Date = 'date';
    case Decimal = 'decimal';
    case Float = 'float';
}
