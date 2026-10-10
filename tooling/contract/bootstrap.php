<?php

/**
 * @package     VidyaLMS
 * @subpackage  Tooling
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 *
 * Loads the database port contract without an autoloader, for the adapter
 * smoke scripts that run inside a booted CMS.
 *
 * Usage:
 *   require_once __DIR__ . '/../../tooling/contract/bootstrap.php';
 *   use Shondalai\VidyaLMS\Tooling\Contract\DatabasePortContract;
 *
 *   foreach (DatabasePortContract::failures($database) as $failure) { ... }
 *
 * The core's classes (Query, Aggregate, Predicate) come from wherever the
 * host already autoloads them; this bootstrap loads only the contract.
 */

declare(strict_types=1);

require_once __DIR__ . '/RollbackSignal.php';
require_once __DIR__ . '/DatabasePortContract.php';
require_once __DIR__ . '/AssessmentFoundationContract.php';
require_once __DIR__ . '/ProSchemaContract.php';
require_once __DIR__ . '/HttpPortContract.php';
require_once __DIR__ . '/SecretsPortContract.php';
require_once __DIR__ . '/OrganisationSeatContract.php';
// The provider before the contract that registers it: there is no autoloader
// here, and the contract names the class in a constant initialiser.
require_once __DIR__ . '/SmokeOrganisationRosterImporter.php';
require_once __DIR__ . '/OrganisationImportContract.php';
require_once __DIR__ . '/OrganisationIdentityContract.php';
require_once __DIR__ . '/AutomationEngineContract.php';
require_once __DIR__ . '/AiFreeBuildContract.php';
// The two providers before the contract that registers them, for the same
// reason the roster importer is loaded before its own contract: there is no
// autoloader here and the contract names both classes.
require_once __DIR__ . '/SmokeTwoNamedRecipients.php';
require_once __DIR__ . '/SmokeAttachingDelivery.php';
require_once __DIR__ . '/GradebookReportingContract.php';
require_once __DIR__ . '/OrganisationCatalogueContract.php';
