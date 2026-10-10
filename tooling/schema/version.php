<?php

/**
 * The schema version of packages/core/schema/tables.php, for the whole
 * repository.
 *
 * One number, one place. Both free packagers read it and refuse to build when
 * their host cannot show a site a way of reaching it:
 *
 *   Joomla     packages/adapter-joomla/com_vidyalms/admin/sql/updates/mysql/<version>.sql
 *   WordPress  Setup\Activator::SCHEMA_VERSION, plus the data half in
 *              packages/adapter-wordpress/vidyalms/schema/migrations/ when the
 *              version has anything to say about existing rows
 *
 * That check exists because a schema change with no migration on one of the two
 * hosts is a silent failure, and it is silent until the first site that
 * upgraded rather than installed clean writes to a column it never got.
 *
 * Fresh installs compile the current neutral schema and record this version.
 * tooling/schema/floor.php bounds retained update filenames; it is not a
 * historical schema snapshot. The packaging check compares both host declarations
 * and the paths they support. Native financial schemas have no compatibility
 * or upgrade path to this external-commerce baseline.
 *
 * The Joomla folder still holds a file named for the floor. It carries no
 * statements: Joomla stamps #__schemas with the highest name in that folder on
 * install, and Administrator\Service\SchemaVersion reads the same highest name
 * to write the neutral schema_version row, so the name has to be there even
 * though the install is what creates the tables. WordPress needs no such
 * marker, because Setup\Activator::SCHEMA_VERSION is the name it records.
 *
 * @return string
 */

declare(strict_types=1);

return '1.0.1';
