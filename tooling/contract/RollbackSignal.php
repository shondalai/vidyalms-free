<?php

/**
 * @package     VidyaLMS
 * @subpackage  Tooling
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 */

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Tooling\Contract;

/**
 * Thrown to make DatabasePort::transactional() roll back a contract run.
 * Nothing catches it except the run itself, so a contract executed against a
 * live site leaves the site exactly as it found it.
 */
final class RollbackSignal extends \RuntimeException
{
}
