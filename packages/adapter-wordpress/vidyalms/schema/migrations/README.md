# Data migrations

`dbDelta` reaches the shape `schema/schema.php` declares from any older shape it
finds. It cannot write a row, so anything a version has to say about rows that
already exist lives here, one file per version, named for the version it
upgrades to and returning a list of SQL statements with a `{prefix}`
placeholder. `Setup\Migrations` runs them in version order after `dbDelta`, and
only those above the version the site records.

**This directory is empty, and at the install floor that is the correct state.**

The commerce-only build is a fresh `1.0.0` baseline. Earlier unpublished markers,
including the native gateway/refund storage marker, are not upgrade paths.
The current compiled schema already includes the assessment and shop access columns.

`tooling/schema/floor.php` holds the version of the first release. No site can
hold anything older, because nothing older ever shipped, and the compiled
install creates the floor outright. A migration file for the floor would
therefore run on nothing: `Setup\Migrations::pending()` starts strictly above
the version the site already records, and a fresh activation records the floor.
The packager refuses a file below the floor for that reason, and refuses one
above `Setup\Activator::SCHEMA_VERSION` because `pending()` stops before it.

The first version above the floor that has anything to say about existing rows
adds `<version>.php` here. Write every statement so that running it twice is the
same as running it once: the recorded version only advances when the whole set
ran cleanly, so a run that dies half way is repeated from the top of that
version rather than skipped.

`php packages/adapter-wordpress/build/schema-lifecycle-test.php --synthetic`
proves the path still works without shipping anything: it writes a throwaway
migration one patch version above the floor, installs clean, upgrades, checks
that the column arrived and the version was recorded, and removes the file
again.
