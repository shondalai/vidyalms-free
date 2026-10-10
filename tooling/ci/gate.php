<?php

/**
 * The conformance greps 10-coding-standards.md publishes, run as one command.
 *
 * Every check here exists because a rule that only a document states is a rule
 * the next slice breaks without noticing. Each one is cheap, each one names the
 * exact file and line it refuses, and each one has a stated exemption list in
 * this file rather than in a reviewer's memory.
 *
 * Usage:
 *   php tooling/ci/gate.php                 all checks
 *   php tooling/ci/gate.php --list          the check names
 *   php tooling/ci/gate.php --check=dashes  one check
 *   php tooling/ci/gate.php --root=DIR      read a tree other than this one,
 *                                           which is what makes the checks
 *                                           themselves testable
 *
 * Exit code is 0 when every requested check passes and 1 when any fails, so it
 * is usable as a build step. Nothing here writes a file or touches a database.
 *
 * The dash characters this file hunts for are written as escapes on purpose:
 * a scanner that carries its own quarry as a literal reports itself.
 */

declare(strict_types=1);

const ROOT = __DIR__ . '/../..';

/**
 * The tree the checks read, which is the repository unless --root says
 * otherwise. It is settable so the checks themselves are testable: a fixture
 * tree carrying one deliberate violation each is the only way to prove a check
 * still refuses what it claims to, and the alternative is a reviewer running
 * the evasion by hand once and trusting it afterwards.
 */
function gateRoot(?string $set = null): string
{
    static $root = ROOT;

    if ($set !== null) {
        $root = $set;
    }

    return $root;
}

/**
 * Paths no check ever reads, named by a path segment so they are skipped
 * wherever they occur. The array holds exactly these, and nothing else:
 * dependency trees nobody here wrote (vendor, node_modules), build output
 * derived from a source this gate already covers (dist, .stage, the two
 * adapters' app bundles under media/app and assets/app, out), caches
 * (.git, .phpstan-cache), captured run output (test-results), and two
 * scratch trees (local-backups, tmp).
 *
 * `build` is deliberately absent: each adapter's own build/ directory is
 * authored PHP and is scanned like any other source. The repository root's
 * build/ is packaging output and is excluded by EXCLUDED_ROOT_TREES instead,
 * which is a root-relative rule rather than a segment one.
 *
 * Derived output is excluded rather than fixed because fixing it is rebuilding
 * it: a dash inside packages/ui/dist is a dash in packages/ui/src that this
 * gate already refuses, and a dash inside a vendored third-party sourcemap is
 * not ours to remove.
 */
const EXCLUDED_SEGMENTS = [
    '/vendor/',
    '/node_modules/',
    '/dist/',
    '/.stage/',
    '/.git/',
    '/.phpstan-cache/',
    '/media/app/',
    '/assets/app/',
    '/test-results/',
    '/local-backups/',
    '/tmp/',
    '/out/',
];

/**
 * Repository-root trees that are derived, named from the root rather than by
 * segment so an adapter's authored build/ is still scanned. The root build/
 * holds packaging logs and rendered certificate proofs, both of which are
 * output of a source this gate already reads.
 */
const EXCLUDED_ROOT_TREES = [
    'build/',
    /*
     * The mobile app's Gradle output, which is derived exactly as the root
     * build/ is and is named the same way for the same reason: `build` cannot
     * be a segment rule, because each adapter's own build/ directory is
     * authored PHP that this gate has to read.
     *
     * What is inside it is Android's merged resource tables, which carry the
     * en dash in six hundred translated strings of the platform's own date and
     * time pickers. None of it is ours to remove and rewriting it would be
     * rebuilding it, which is the rule this list already states.
     */
    'apps/mobile/android/app/build/',
    /*
     * A design prototype that is its own repository: it carries its own .git,
     * its own deploy configuration and its own build output, and it was
     * written by a separate stream as a reference for the screens, not as a
     * part of the product. Its prose is that stream's to keep, exactly as
     * vidyalms-ux-prototype outside this tree is, and the .git segment rule
     * above cannot reach it because only the nested repository's metadata
     * lives under a .git segment while its authored files do not.
     */
    'docs/design/admin-dashboard/',
];

/**
 * Where the language and copy rule applies: everything a person wrote, which
 * is the whole repository minus the exclusions above.
 *
 * It used to name packages, tooling and docs, which left openapi.yaml and
 * README.md, the two files at the root that every package reads, outside the
 * only gate that covers them, along with apps/ and services/. The check's own
 * docblock claimed "any authored file" while three named roots was what it
 * meant, so the scan now matches the claim instead.
 */
const DASH_ROOTS = [
    '.',
];

/**
 * The authored PHP sources. The adapters' vendored copies of these trees are
 * byte-identical by construction and are checked as copies, not as sources.
 */
const PHP_SOURCE_ROOTS = [
    'packages/core/src',
    'packages/pro-core/src',
];

/**
 * Every authored PHP tree the OrgScope minting rule applies to.
 *
 * OrgScope's own docblock says the build fails on "new OrgScope", on any
 * OrgScope:: call and on an aliased import outside three files, and amendment
 * A1 says exactly three factories in exactly three files. Neither was true of
 * the four host packages, of business-core when it lands, or of anything a
 * third party ships, because the check walked two package roots: an adapter
 * could mint a scope and the build stayed silent.
 *
 * The vendored core copies are excluded because they are byte-identical
 * duplicates of packages/core/src and are checked as copies, not as sources.
 * A package outside this repository is outside this gate's reach, which is
 * what makes the private constructor and the witness-typed factories the real
 * control and this the visible one.
 */
const ORG_SCOPE_ROOTS = [
    'packages/core/src',
    'packages/pro-core/src',
    'packages/adapter-joomla/com_vidyalms',
    'packages/adapter-joomla/plugins',
    'packages/adapter-joomla/pkg_script.php',
    'packages/adapter-wordpress/vidyalms/src',
    'packages/pro-joomla/plugins',
    'packages/pro-joomla/pkg_script.php',
    'packages/pro-wordpress/vidyalms-pro/src',
];

/**
 * The tables whose rows belong to exactly one organisation.
 *
 * It mirrors OrgScopedReader::SCOPED_TABLES, and the mirror is asserted by a
 * test in the core rather than trusted, because a table added to one list and
 * not the other is a table the gate stops watching.
 */
const ORG_SCOPED_TABLES = [
    'organisations',
    'org_memberships',
    'org_membership_events',
    'org_role_assignments',
    'org_units',
    'org_course_releases',
    'org_catalogue_entries',
    'org_access_termination_jobs',
    'invitations',
    'org_invitation_claims',
    'seat_pools',
    'seat_assignments',
    'seat_events',
    'org_notification_secrets',
    'admin_access_log',
    'org_connections',
    'org_identity_links',
    'org_domains',
    'org_join_rules',
    'compliance_requirements',
    'compliance_assignments',
    'assignment_exceptions',
    'org_ledger_redactions',
];

/**
 * The same question asked of every package rather than of the core alone.
 *
 * The constant above mirrors a core constant, so a scoped table declared by a
 * package outside the core was invisible to this scan: it could not be added
 * to either core list without a core edit, and reads of it were not watched. A
 * package now declares its own tenancy beside its own schema, in
 * `packages/{name}/schema/org-tenancy.php`, and this is where the two halves
 * meet.
 *
 * @return list<string>
 */
function orgScopedTables(): array
{
    static $tables = null;

    if ($tables !== null) {
        return $tables;
    }

    $tables = ORG_SCOPED_TABLES;

    foreach (glob(dirname(__DIR__, 2) . '/packages/*/schema/org-tenancy.php') ?: [] as $file) {
        /** @var mixed $declaration */
        $declaration = require $file;

        if (!is_array($declaration) || !is_array($declaration['scoped'] ?? null)) {
            continue;
        }

        foreach (array_keys($declaration['scoped']) as $table) {
            if (is_string($table) && !in_array($table, $tables, true)) {
                $tables[] = $table;
            }
        }
    }

    sort($tables);

    return $tables;
}

/**
 * Where an organisation-scoped read may start.
 *
 * The reader and the two stores beside it are the persistence layer for these
 * tables, so naming one there is what they are for.
 */
const ORG_READ_HOME = 'packages/core/src/Infrastructure/Persistence/Organisation/';

/**
 * Every authored PHP tree the organisation READS rule applies to.
 *
 * It used to be PHP_SOURCE_ROOTS, which is two package roots, so the four host
 * packages were outside the scan entirely: both adapters read org_connections
 * through the identity store with no tenant predicate, by design and by
 * necessity, and neither read was recorded on ORG_READ_WITNESSED because no
 * check looked there. The OrgScope minting rule was widened past two roots for
 * exactly this reason and the comment above ORG_SCOPE_ROOTS says so; the reads
 * rule was not widened with it. It is the same list, for the same reason: a
 * new adapter read is a signed decision rather than an unchecked one.
 */
const ORG_READ_ROOTS = ORG_SCOPE_ROOTS;

/**
 * Every authored PHP tree the model-bridge containment rule applies to.
 *
 * It used to be PHP_SOURCE_ROOTS, which is two package roots, and this is the
 * third time the same two-root gap has been found: the OrgScope minting rule
 * was widened past it, then the organisation READS rule was widened with the
 * comment above saying so, and the model-bridge arm was not widened with
 * either. It was not hypothetical. `packages/pro-joomla` ships a working
 * `ModelBridgeInterface` implementation and its build harness constructs a
 * `BridgeRequest`, which is the exact construction this check refuses inside
 * the scanned roots, and both passed because the tree was not scanned. ADR 0023
 * states the rule as "nothing outside packages/core/src/Application/Ai
 * constructs a BridgeRequest", which was false in the shipped tree.
 *
 * The two host build trees are on the list as well as the installed ones,
 * because a harness is where a construction is most convenient to write and
 * least likely to be noticed. The one that legitimately needs it is exempt by
 * name in `checkModelBridge()`, which is a signed decision rather than a
 * silence.
 */
const MODEL_BRIDGE_ROOTS = [
    ...ORG_SCOPE_ROOTS,
    'packages/pro-joomla/build',
    'packages/pro-wordpress/build',
];

/**
 * Host files whose organisation reads predate this rule reaching them.
 *
 * The list is **shrink only** and it is a location, not a licence, exactly as
 * ORG_READ_EXEMPT is. Widening the walk to the host packages is what makes a
 * NEW adapter read a signed decision; these are the reads that were already
 * there when the walk arrived, and each of them belongs to the adapter owner
 * to route or to witness. Two shapes are on it: an installer and a privacy hook
 * that compose a table name from a variable, which this scan cannot read at
 * all, and one kernel factory read of an organisation-owned table that has not
 * been through the reader yet.
 *
 * Nothing outside this list is skipped, so an adapter added tomorrow is checked
 * the way the core is.
 */
const ORG_READ_HOST_UNREVIEWED = [
    'packages/adapter-joomla/com_vidyalms/admin/src/Service/KernelFactory.php',
    'packages/adapter-joomla/plugins/plg_privacy_vidyalms/src/Extension/VidyaLms.php',
    'packages/adapter-joomla/pkg_script.php',
];

/**
 * The files that still name an organisation table directly, each with the
 * wave that moves it.
 *
 * The list is **shrink only**. It exists because routing every one of these
 * through the reader in one wave means rewriting the seat lifecycle, the
 * invitation ceremony and the privacy contributor at the same time as three
 * other agents are editing them, and a merge conflict inside a tenancy
 * predicate is the worst place in this repository to have one.
 *
 * Being on it is not a licence. Every read in an exempt file is still checked
 * for its predicate below, so an exempt file that stops naming
 * organisation_id fails the build exactly as an unexempt one would. What the
 * exemption buys is the location, not the guarantee.
 */
const ORG_READ_EXEMPT = [
    // R3, seats and invitations. ManageOrganisationSeats and SeatLifecycle
    // came off the list in that wave: every organisation table either of them
    // reads is now started at the scoped reader, and the enrolment query the
    // reclaim decision used to make is gone with the assignment states that
    // replaced it.
    'packages/core/src/Application/Organisation/ManageOrganisationInvitations.php',
    'packages/core/src/Application/Organisation/EncryptedInvitationDelivery.php',
    'packages/core/src/Application/Organisation/InvitationProof.php',
    // Stays, and not for want of trying: jobAuthority() reads the job row to
    // recover the tenant that mints the scope, so it is the one read in the
    // area that cannot be narrowed by a scope it is the source of.
    'packages/core/src/Application/Organisation/OrganisationAccessTermination.php',
    'packages/core/src/Application/Organisation/OrganisationTerminationTask.php',
    // R2, the entitlement and catalogue boundary.
    'packages/core/src/Application/Organisation/CourseReleasePolicy.php',
    'packages/core/src/Application/Organisation/OrgEntitlementBoundary.php',
    'packages/core/src/Application/Organisation/OrgEntitlementGrantContext.php',
    'packages/core/src/Application/Organisation/RequireCurrentOrganisationAccess.php',
    // R5, rostering, governance and privacy.
    'packages/core/src/Application/Organisation/ManageOrganisationLearning.php',
    'packages/core/src/Application/Organisation/ManageOrganisationMembers.php',
    'packages/core/src/Application/Organisation/ManageOrganisationUnits.php',
    'packages/core/src/Application/Organisation/OrganisationPrivacy.php',
    'packages/core/src/Provider/Privacy/CoreOrganisationsPrivacy.php',
    // The Pro identity contributor, on exactly the same terms as the free
    // organisations one directly above it and for the same single reason: a
    // subject access request spans every organisation a person belongs to, so
    // its read is anchored on the person and there is no tenant for it to
    // carry. It reaches org_identity_links to map the subject to the identity
    // links whose SCIM sign-in name index rows it exports and erases.
    'packages/pro-core/src/Provider/Privacy/ProIdentityPrivacy.php',
    // The two remaining free escalation audiences, which resolve from an
    // OrgScope and narrow on it in the same statement. They are providers
    // rather than persistence, and a resolver that could not name its own two
    // tables would need a repository method per audience. OrgAdminsAudience
    // left this list in R3: it starts its read at OrgScopedReader, which now
    // takes the tenant a stamped occurrence carries as well as a resolved
    // scope, so it no longer names a table at all.
    'packages/core/src/Provider/Notification/Audience/OrgUnitManagersAudience.php',
    'packages/core/src/Provider/Notification/Audience/LineManagerAudience.php',
    // R11b, and the one entry that is not waiting for a wave to move it. The
    // recertification reset asks which OTHER organisations hold a live
    // compliance record on the learning it is about to destroy, because
    // `enrolments` is keyed on (user, course) and carries no organisation, so
    // one tenant's window used to strip a completion a second tenant still
    // read as compliant. The question is 'not this organisation' by
    // construction and the scoped reader answers 'this organisation and no
    // other', which is the opposite one. The read still names
    // a.organisation_id in its own statement, so the predicate half of this
    // rule holds and only the location is exempt.
    'packages/pro-core/src/Provider/ScheduledTask/ComplianceRecertificationTask.php',
];

/**
 * What counts as narrowing a read, inside the statement that starts it.
 *
 * organisation_id is the predicate itself. constrainUnit adds the closure
 * join on top of a predicate the scope already carries. The two subject
 * columns are the access log's own anchors, which are what an anchored audit
 * query narrows on.
 */
const ORG_READ_PREDICATES = [
    'organisation_id',
    'organisationId',
    'orgId',
    'subject_org_id',
    'actor_org_id',
];

/**
 * The calls that can carry a tenant predicate.
 *
 * The predicate has to be an argument of one of these rather than a token
 * anywhere in the statement, which is what stops a select() list or an
 * orderBy() naming organisation_id from reading as a narrowing.
 */
const ORG_NARROWING_CALLS = [
    'where',
    'whereIn',
    'whereAnyOf',
    'whereNull',
    'whereNotNull',
    'join',
    'leftJoin',
    'compare',
];

/**
 * The reads that deliberately carry no tenant predicate, in two categories.
 *
 * **The reads that mint the tenant rather than consume it.** There are three
 * of them and they are the three OrgScope factories' own inputs, which is why
 * this list and the org-scope check's witness list name the same ceremonies.
 * An invitation token and a mailbox proof code are the authority by
 * construction, and anchoring their lookup on an organisation the caller
 * supplied would let a caller aim a valid token at the wrong tenant; a
 * durable cleanup job carries the tenant it recorded when it was authorised,
 * and reading it by the caller's tenant would be reading it by the wrong one.
 *
 * **The reads anchored on the data subject instead.** A subject access
 * request spans every organisation a person belongs to, so the privacy
 * contributor's query builder is narrowed on the person by
 * subjectGroups() and on the organisation by exportFor(), and the two
 * anchors are two correct answers to two different questions. The predicate
 * is therefore applied by the caller and cannot appear in the statement that
 * starts the read. It is named here rather than passing by accident: the
 * older rule matched "organisation_id" anywhere in the statement, so these
 * reads satisfied it by naming the column in a projection list.
 *
 * Each entry names the file and the tables inside it that may be unanchored,
 * so an unanchored read of a *different* table in the same file still fails.
 */
const ORG_READ_WITNESSED = [
    'packages/core/src/Application/Organisation/InvitationProof.php' => ['org_invitation_claims'],
    'packages/core/src/Application/Organisation/ManageOrganisationInvitations.php' => ['invitations'],
    'packages/core/src/Application/Organisation/OrganisationAccessTermination.php' => ['org_access_termination_jobs'],
    'packages/core/src/Application/Organisation/OrganisationTerminationTask.php' => ['org_access_termination_jobs'],
    // The sealed invitation payload's own lookup, which is category one: the
    // row records the organisation the ceremony then narrows every other read
    // on, so anchoring this one on a tenant the caller supplied would be
    // asking the answer to supply the question.
    'packages/core/src/Application/Organisation/EncryptedInvitationDelivery.php' => ['org_notification_secrets'],
    'packages/core/src/Provider/Privacy/CoreOrganisationsPrivacy.php' => [
        'org_memberships',
        'org_role_assignments',
        'invitations',
        'seat_assignments',
        'seat_events',
        'org_membership_events',
        // The catalogue entry's author, on exactly the terms the six above it
        // sit on: the query builder is narrowed on the person by
        // subjectGroups() and on the organisation by exportFor(), which are two
        // correct answers to two different questions, so the predicate is
        // applied by the caller and cannot appear in the statement that starts
        // the read.
        'org_catalogue_entries',
    ],
    // The Pro identity contributor's own subject-anchored read, category two.
    // `org_identity_links` is how a person reaches the SCIM sign-in name index
    // rows that name them: the index row itself holds a digest and a link id
    // and no user id, so the link table is the only path from the subject to
    // the row. The query is narrowed on the person by subjectGroups() and the
    // erasure walks the same person's links, and the organisation direction is
    // declined outright by the base class, so there is no tenant question for
    // this read to answer.
    'packages/pro-core/src/Provider/Privacy/ProIdentityPrivacy.php' => ['org_identity_links'],
    // The persistence home. It used to be skipped entirely, so the exemption
    // list could shrink by relocation: a scope-free read moved in here stopped
    // being checked at all. Every one below is deliberate and each is
    // enumerated so it is reviewed rather than invisible.
    //
    // The access log's own store reads it site-wide for the retention sweep,
    // the chain verification and the site manager's own view, which is what a
    // hash chain over every organisation's rows has to do.
    'packages/core/src/Infrastructure/Persistence/Organisation/AdminAccessLogStore.php' => ['admin_access_log'],
    // Two reads and both have a caller with no scope. assignmentsWithoutReference
    // is the upgrade backfill, which has no tenant to narrow on and would skip
    // every organisation it guessed wrong; claimed() is keyed on the
    // idempotency key alone because the unique index that enforces the claim
    // is, and it refuses a key that belongs to another organisation rather
    // than answering with its row.
    'packages/core/src/Infrastructure/Persistence/Organisation/OrganisationLearningStore.php' => [
        'seat_assignments',
        'seat_events',
    ],
    // The lookup that RESOLVES a tenant cannot be narrowed by one: this is the
    // read every scope is minted from.
    'packages/core/src/Infrastructure/Persistence/Organisation/OrganisationStore.php' => ['organisations'],
    // The identity home's three unanchored reads, all category one: they mint
    // a tenant or answer a question that is global by construction.
    //
    // org_identity_links: the pair IS the identity, so finding a person by
    // (issuer, subject) cannot be narrowed by an organisation the caller
    // supplied without letting a caller aim a valid assertion at the wrong
    // tenant. Every other read of this table in the file starts at the reader.
    //
    // org_connections: "which organisation, if any, holds this issuer
    // verified" is the question the global unique index exists to answer, and
    // a read narrowed to one organisation could never see the collision it is
    // there to detect.
    //
    // org_domains: the re-check sweep asks across every organisation at once
    // and recovers the tenant per row, exactly as the four shipped sweeps do,
    // and its write names the organisation the row carried.
    'packages/core/src/Infrastructure/Persistence/Organisation/OrgIdentityStore.php' => [
        'org_identity_links',
        'org_connections',
        'org_domains',
    ],
    // The four sweeps ask across every organisation at once and then recover
    // the tenant per row through OrganisationSweepAuthority, which is the
    // witness the scope is minted from. A sweep narrowed on a tenant would be
    // a sweep that only ever ran for one.
    'packages/core/src/Infrastructure/Persistence/Organisation/SweptRecordStore.php' => [
        'seat_assignments',
        'seat_pools',
        'invitations',
        'org_invitation_claims',
        // The term sweep's own due list, on the same terms as the four beside
        // it: it asks across every organisation at once, because a membership
        // whose agreed end date has passed is due whichever tenant it belongs
        // to, and the scope is minted from the organisation the swept row
        // named. Anchoring it on a tenant the caller supplied would be asking
        // the answer to supply the question.
        'org_memberships',
        // The organisation row a swept membership recovers its authority from,
        // which is the same mint-the-tenant read one table up.
        'organisations',
        // The auto-granting catalogue entries, on the same terms as the sweeps
        // above: an entry that grants by itself is due whichever tenant owns
        // it, the scope is minted from the organisation the row names, and
        // every read the sweep makes afterwards starts at the scoped reader
        // with that scope. The column had a writer, an index and a reader and
        // nothing that acted on it until this read existed.
        'org_catalogue_entries',
    ],
    // The daily compliance walk, on exactly the sweep's own terms: it asks
    // every organisation at once and recovers the tenant per row, and its write
    // names the organisation the row carried as well as the row id. A walk
    // narrowed on a tenant would be a walk that only ever ran for one.
    'packages/core/src/Infrastructure/Persistence/Organisation/ComplianceStore.php' => [
        'compliance_assignments',
        'compliance_requirements',
    ],
    // The retention sweep's own read. The sweep prunes a site-wide table and
    // needs every organisation's policy at once, and it reads a policy and an id
    // and nothing else, so a task running unattended cannot become a second,
    // quieter way to enumerate a site's tenants.
    'packages/core/src/Infrastructure/Persistence/Organisation/OrganisationRetentionPolicies.php' => [
        'organisations',
    ],
    // How many organisations a delivery's recipients span, which is the one read
    // behind both delivery refusals. A read narrowed to one tenant could never
    // see the crossing it exists to detect, which is the same shape the issuer
    // collision read above takes.
    'packages/core/src/Infrastructure/Persistence/Organisation/OrganisationAudienceSpan.php' => [
        'org_memberships',
    ],
];

/**
 * The two files whose table argument is deliberately a parameter.
 *
 * The scan refuses a table name it cannot read, which is right everywhere
 * else: a constant or a variable holding a table name is how a read leaves
 * the gate with the build silent. These two take the table FROM their caller
 * by design, which is what makes them one reader and one authority lookup
 * rather than one per table, and both apply the tenant rule themselves.
 */
/**
 * The one file in the core allowed to spell a protocol word in code.
 *
 * It is the stored vocabulary of org_connections.protocol and nothing else: an
 * operator looking at a connection row has to see what kind of thing it is,
 * and a free build that could only store its own word would make every paid
 * connection row read as the host's own. The class carries the four words as
 * data, names none of them in a class name, a method or a behaviour, and its
 * docblock says it is the boundary.
 *
 * A second entry means a second place the core knows what a protocol is, so
 * this list is expected to stay at one.
 */
const PROTOCOL_WORD_EXEMPT = [
    'packages/core/src/Domain/Organisation/Identity/ConnectionProtocol.php',
    // The free role mapper and the context it reads. Translating a foreign
    // role word is the one job in the core that has to name the foreign
    // vocabularies, because the design's own rule is that the full URI form
    // matches first and the deprecated simple name matches only as a warned
    // fallback. A mapper that could not spell the vocabulary it maps could not
    // do the mapping, and moving it behind a paid package would put the free
    // build's roster importers back to guessing.
    'packages/core/src/Provider/Organisation/StandardOrgRoleMapper.php',
    'packages/core/src/Registry/Context/RoleMappingContext.php',
    // A credential media type, which is a different family of thing from an
    // identity protocol: it names the wire format an issued credential is
    // serialised in, the core neither signs nor verifies one, and the word
    // appears only in a media-type table beside the image and document types.
    'packages/core/src/Registry/Certificate/RenderedArtefact.php',
    // One sentence in a refusal message, explaining to an operator why a
    // particular kind of imported content has no free equivalent. It is prose
    // that happens to live in a string rather than in a comment, and the
    // importer neither reads nor writes anything of that kind.
    'packages/core/src/Provider/Importer/Shika/ShikaImporter.php',
];

/**
 * The words the core may not name in code.
 *
 * Five protocols and eight vendors. The protocols are the ones this design is
 * written against and the vendors are the identity providers it names; a
 * learning product this core genuinely integrates with, such as a course
 * format it imports from, is deliberately absent, because those are real
 * importers with real class names and refusing them would refuse the product.
 *
 * The rule is about code, not about prose. A docblock explaining that an
 * absent group claim from a particular directory is a truncation rather than a
 * denial is exactly the kind of comment this repository wants, and the scan
 * never reads one: it walks identifiers and string literals only, both of
 * which the tokeniser hands over with comments already removed.
 *
 * @var list<string>
 */
const PROTOCOL_WORDS = [
    // The protocols. Written as fragments so this file does not itself carry a
    // word it refuses, which would make the check report its own source.
    'sa' . 'ml',
    'oi' . 'dc',
    'sc' . 'im',
    'l' . 'ti',
    'j' . 'wt',
    // The identity provider vendors.
    'ok' . 'ta',
    'en' . 'tra',
    'ad' . 'fs',
    'shibbo' . 'leth',
    'one' . 'login',
    'key' . 'cloak',
    'aut' . 'h0',
    'ping' . 'federate',
];

const ORG_READ_DYNAMIC = [
    'packages/core/src/Infrastructure/Persistence/Organisation/OrgScopedReader.php',
    'packages/core/src/Infrastructure/Persistence/Organisation/SweptRecordStore.php',
];

$argumentNames = ['--list'];
$requested = null;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--list') {
        foreach (array_keys(checks()) as $name) {
            echo $name, "\n";
        }

        exit(0);
    }

    if (str_starts_with($argument, '--check=')) {
        $requested = substr($argument, 8);

        continue;
    }

    if (str_starts_with($argument, '--root=')) {
        $tree = realpath(substr($argument, 7));

        if ($tree === false) {
            fwrite(STDERR, "No tree at " . substr($argument, 7) . ".\n");

            exit(2);
        }

        gateRoot($tree);

        continue;
    }

    fwrite(STDERR, "Unknown argument {$argument}. Known: --list, --check=<name>, --root=<dir>.\n");

    exit(1);
}

$checks = checks();

if ($requested !== null && !isset($checks[$requested])) {
    fwrite(STDERR, "Unknown check \"{$requested}\". Run --list for the names.\n");

    exit(1);
}

$failed = 0;

foreach ($checks as $name => $check) {
    if ($requested !== null && $name !== $requested) {
        continue;
    }

    /** @var list<string> $violations */
    $violations = $check['run']();

    if ($violations === []) {
        echo '[OK]   ', $name, ': ', $check['passes'], "\n";

        continue;
    }

    $failed++;
    echo '[FAIL] ', $name, ': ', count($violations), " problem(s)\n";
    echo '       ', $check['rule'], "\n";

    foreach ($violations as $violation) {
        echo '       ', $violation, "\n";
    }
}

exit($failed === 0 ? 0 : 1);

/**
 * @return array<string, array{rule: string, passes: string, run: callable(): list<string>}>
 */
function checks(): array
{
    return [
        'dashes' => [
            'rule' => 'No em-dash or en-dash in any authored file. Use a comma, a colon, a full stop, '
                . 'the word the sentence needs, or a plain hyphen for a table placeholder.',
            'passes' => 'no em-dash or en-dash in any authored source or document',
            'run' => checkDashes(...),
        ],
        'vendor-names' => [
            'rule' => 'packages/core may not name a model vendor or a host AI component: the core reaches a '
                . 'model only through a registered ModelBridge, and naming one here would make the free '
                . 'build depend on a package it does not ship.',
            'passes' => 'packages/core names no model vendor and no host AI component',
            'run' => checkVendorNames(...),
        ],
        'org-scope' => [
            'rule' => 'An OrgScope is minted only by its own three published factories, so every organisation '
                . 'query is narrowed by a scope that an authorisation produced.',
            'passes' => 'OrgScope is minted only through its four witnessed factories',
            'run' => checkOrgScope(...),
        ],
        'org-reads' => [
            'rule' => 'A query over an organisation-owned table starts at OrgScopedReader::from(), which injects the '
                . 'tenant predicate from a scope an authorisation produced. The named legacy files are exempt from '
                . 'the location rule and not from the predicate: every one of their reads must still name '
                . 'organisation_id or constrain the unit, in the same statement.',
            'passes' => 'every organisation-scoped read carries its tenant predicate',
            'run' => checkOrgReads(...),
        ],
        'model-bridge' => [
            'rule' => 'ModelBridgeInterface is named only by its own registry seam, by the bridges that '
                . 'implement it, and by Application/Ai, where AiBridgeCaller is the single holder. Nothing '
                . 'else may hold a bridge, because a bridge holds the one transport that can leave the site. '
                . 'The holder itself may only be BUILT inside Application/Ai: AiBridgeCaller::forRun and '
                . 'AiCallEnvelope::create take publicly constructible collaborators, so a package that '
                . 'assembled one reached a bridge without the file ever naming the seam.',
            'passes' => 'ModelBridgeInterface is named, and its holder built, only inside its own seam and single holder',
            'run' => checkModelBridge(...),
        ],
        'protocol-words' => [
            'rule' => 'packages/core may not name an identity protocol or an identity provider vendor in code: '
                . 'the core never terminates an identity protocol, never holds a certificate, a secret or a '
                . 'token, and never calls a directory. Every one of those words belongs on the far side of the '
                . 'IdentityMapper seam, in a package that is not installed on a free build.',
            'passes' => 'packages/core names no identity protocol and no identity provider vendor in code',
            'run' => checkProtocolWords(...),
        ],
        'manage-groups' => [
            'rule' => 'MANAGE_GROUPS is an entry probe on the delegated surface and never a tenancy answer. '
                . 'It is named only by the capability list and the organisation scope resolver, which '
                . 'evaluates the tenant predicate immediately after it, and it is never passed to a '
                . 'require() that carries no organisation resource.',
            'passes' => 'MANAGE_GROUPS never stands alone as an authorisation',
            'run' => checkManageGroups(...),
        ],
    ];
}

/**
 * @return list<string>
 */
function checkDashes(): array
{
    $emDash = "\u{2014}";
    $enDash = "\u{2013}";
    $violations = [];

    foreach (DASH_ROOTS as $root) {
        foreach (walk($root) as $relative => $path) {
            $contents = readText($path);

            if ($contents === null) {
                continue;
            }

            if (!str_contains($contents, $emDash) && !str_contains($contents, $enDash)) {
                continue;
            }

            foreach (explode("\n", $contents) as $number => $line) {
                if (!str_contains($line, $emDash) && !str_contains($line, $enDash)) {
                    continue;
                }

                $violations[] = sprintf('%s:%d: %s', $relative, $number + 1, excerpt($line));
            }
        }
    }

    return $violations;
}

/**
 * @return list<string>
 */
function checkVendorNames(): array
{
    // Written as fragments so this file does not itself contain a name it
    // refuses, which would make the check report its own source for ever.
    $forbidden = [
        'Ai' . 'ServiceClient',
        'com_' . 'aicore',
        'Bed' . 'rock',
        'Open' . 'AI',
        'Anthro' . 'pic',
    ];

    return matchesIn(['packages/core'], static function (string $line) use ($forbidden): ?string {
        foreach ($forbidden as $name) {
            if (stripos($line, $name) !== false) {
                return $name;
            }
        }

        return null;
    });
}

/**
 * @return list<string>
 */
function checkOrgScope(): array
{
    /**
     * The class itself plus one file per published factory. Every entry is a
     * witness: the resolver mints from an authorisation, the invitation path
     * mints from fresh mailbox proof, the termination path mints from the
     * tenant its own stored job recorded, and the sweep authority mints from
     * the tenant and the actor the row being swept recorded. Adding a sixth
     * file means adding a sixth way to reach organisation data, which is the
     * decision this list makes visible.
     *
     * The sweep authority is one file for all four free organisation sweeps
     * on purpose: a task that minted its own scope would be a fifth way per
     * task, and the four of them would then each decide separately what a
     * cron acts as.
     */
    $witnesses = [
        'packages/core/src/Application/Organisation/OrgScope.php',
        'packages/core/src/Application/Organisation/OrgScopeResolver.php',
        'packages/core/src/Application/Organisation/ManageOrganisationInvitations.php',
        'packages/core/src/Application/Organisation/OrganisationAccessTermination.php',
        'packages/core/src/Application/Organisation/OrganisationSweepAuthority.php',
    ];

    // Resolved names rather than the four characters "OrgScope". A one-line
    // aliased import ("use ...\OrgScope as Tenant;" and then
    // "Tenant::fromTerminationJob(...)") passed the line scan this replaced,
    // which is a fifth way to mint the tenancy witness with nothing naming it.
    return tokenMatchesIn(
        ORG_SCOPE_ROOTS,
        static function (array $file) use ($witnesses): array {
            $target = 'Shondalai\\VidyaLMS\\Core\\Application\\Organisation\\OrgScope';
            $witness = in_array($file['relative'], $witnesses, true);
            $found = [];

            foreach ($file['aliases'] as $local => $fqn) {
                if ($fqn !== $target || $local === 'OrgScope' || $witness) {
                    continue;
                }

                $found[] = [$file['aliasLines'][$local] ?? 1, 'OrgScope imported under the alias ' . $local];
            }

            foreach ($file['names'] as $name) {
                if ($name['resolved'] !== $target) {
                    continue;
                }

                if ($name['construction']) {
                    $found[] = [$name['line'], 'new OrgScope'];

                    continue;
                }

                if ($name['static'] && !$witness) {
                    $found[] = [$name['line'], 'OrgScope::'];
                }
            }

            foreach ($file['strings'] as $literal) {
                if ($witness || preg_match('/(^|\\\\)OrgScope$/', $literal['text']) !== 1) {
                    continue;
                }

                $found[] = [$literal['line'], 'OrgScope named as a string, which reflection and class_exists reach'];
            }

            return $found;
        },
    );
}

/**
 * @return list<string>
 */
function checkOrgReads(): array
{
    $violations = [];

    foreach (ORG_READ_ROOTS as $root) {
        foreach (walk($root) as $relative => $path) {
            if (!str_ends_with($relative, '.php')) {
                continue;
            }

            $contents = readText($path);

            if ($contents === null || in_array($relative, ORG_READ_HOST_UNREVIEWED, true)) {
                continue;
            }

            // The persistence home is exempt from the LOCATION rule and no
            // longer from the predicate one. Skipping the directory entirely
            // meant the exemption list could shrink by relocation: a
            // scope-free read moved into the home stopped being checked at
            // all, and two of them were, both defensible and neither
            // enumerated anywhere. Naming these tables here is what the home
            // is for; reading one without a tenant is a decision, and a
            // decision belongs on ORG_READ_WITNESSED where somebody signed it.
            $exempt = str_starts_with($relative, ORG_READ_HOME) || in_array($relative, ORG_READ_EXEMPT, true);

            foreach (orgTableReads($contents) as $read) {
                if ($read['unreadable']) {
                    if (in_array($relative, ORG_READ_DYNAMIC, true)) {
                        // The reader and the sweep authority take the table
                        // from their caller by design, and both apply the
                        // tenant rule themselves.
                        continue;
                    }

                    // Refused rather than skipped. A table named by an
                    // expression this scan cannot resolve is a read the gate
                    // cannot see, and a check that quietly drops what it
                    // cannot read reports clean about exactly the code that
                    // evaded it.
                    $violations[] = sprintf(
                        '%s:%d: a table name this scan cannot read; name the table with a string literal '
                        . 'or a string class constant so the tenant rule can be checked',
                        $relative,
                        $read['line'],
                    );

                    continue;
                }

                if (!$exempt && !$read['joined']) {
                    $violations[] = sprintf(
                        '%s:%d: %s is read outside OrgScopedReader and this file is not on the shrinking exemption list',
                        $relative,
                        $read['line'],
                        $read['table'],
                    );

                    continue;
                }

                if ($read['narrowed'] || in_array($read['table'], ORG_READ_WITNESSED[$relative] ?? [], true)) {
                    continue;
                }

                $violations[] = sprintf(
                    '%s:%d: %s is read with no tenant predicate in the statement',
                    $relative,
                    $read['line'],
                    $read['table'],
                );
            }
        }
    }

    return $violations;
}

/**
 * Every read of an organisation table in one file, with whether the statement
 * it starts also narrows on the tenant.
 *
 * It tokenises rather than reading lines because a query is routinely written
 * across six lines and the predicate is rarely on the same one as the table
 * name. The statement ends at the first semicolon outside brackets, which is
 * where a fluent chain ends.
 *
 * Three things the first draft did not see, each of them a one-line refactor
 * away from taking a read outside the gate with the build silent: a table
 * named by a class constant, a table named by a variable, and a table
 * joined rather than selected from. A table argument the tokeniser cannot
 * read now **fails** rather than disappearing, on the same terms
 * SchemaSource::assertScanCovers refuses a schema shape it cannot read: a
 * scanner that cannot see something must say so.
 *
 * @return list<array{line: int, table: string, narrowed: bool}>
 */
function orgTableReads(string $code): array
{
    /** @var list<PhpToken> $tokens */
    $tokens = array_values(array_filter(
        PhpToken::tokenize($code),
        static fn (PhpToken $token): bool => $token->id !== T_WHITESPACE
            && $token->id !== T_COMMENT
            && $token->id !== T_DOC_COMMENT,
    ));
    $count = count($tokens);
    $constants = phpStringConstants($tokens, $count);
    // Whether this file mentions an organisation table anywhere at all. A
    // table argument the scan cannot read only matters where one could be
    // hiding: the evasion is a constant or a variable holding one of these
    // names, and the name has to be written somewhere in the file for that.
    // Without this the refusal fires on every repository that names a table
    // through a property, which is most of them and none of this rule.
    $mayHide = false;

    foreach (orgScopedTables() as $table) {
        if (str_contains($code, $table)) {
            $mayHide = true;

            break;
        }
    }

    $found = [];

    for ($i = 0; $i < $count; $i++) {
        $isStatic = ($tokens[$i - 1]->id ?? 0) === T_DOUBLE_COLON;
        $isMethod = ($tokens[$i - 1]->id ?? 0) === T_OBJECT_OPERATOR;

        if (
            $tokens[$i]->id !== T_STRING
            || ($tokens[$i + 1]->text ?? '') !== '('
            || !in_array($tokens[$i]->text, ['from', 'fromHostTable', 'join', 'leftJoin'], true)
            || (!$isStatic && !$isMethod)
        ) {
            continue;
        }

        // A read that already starts at the reader is the compliant shape and
        // is not inspected further: the reader is what injects the predicate,
        // so demanding a typed one beside it would be asking for the line the
        // reader exists to remove.
        if ($isStatic && ($tokens[$i - 2]->text ?? '') === 'OrgScopedReader') {
            continue;
        }

        // A join names its table where from() names its own, so the same
        // resolution answers both and the base table is no longer the only
        // one inspected.
        $name = tableArgument($tokens, $i + 2, $constants);

        if ($name === null) {
            if ($mayHide) {
                $found[] = [
                    'line' => $tokens[$i]->line,
                    'table' => 'a table name this scan cannot read',
                    'narrowed' => false,
                    'unreadable' => true,
                ];
            }

            continue;
        }

        if (!in_array($name, orgScopedTables(), true)) {
            continue;
        }

        $joined = in_array($tokens[$i]->text, ['join', 'leftJoin'], true);
        $found[] = [
            'line' => $tokens[$i]->line,
            'table' => $name,
            // A join's own ON columns are a predicate, and they are read first
            // because a per-row join key is exactly how a label is fetched
            // without widening anything.
            'narrowed' => ($joined && namesTenantColumn($tokens, $i + 1, $count))
                || statementNarrowsOnTenant($tokens, $i, $count),
            'unreadable' => false,
            // The location rule is about where a read **starts**. A join is
            // part of a statement that already started somewhere this scan
            // saw, so it is held to the predicate and not to the location;
            // holding it to both would refuse a left join that names an
            // organisation only to print its name beside a learner's own row.
            'joined' => $joined,
        ];
    }

    return $found;
}

/**
 * The table one from() or join() names, resolving a class constant and a
 * simple local assignment, or null when the scan cannot tell.
 *
 * @param list<PhpToken>        $tokens
 * @param array<string, string> $constants
 */
function tableArgument(array $tokens, int $at, array $constants): ?string
{
    $token = $tokens[$at] ?? null;

    if ($token === null) {
        return null;
    }

    // OrgScopedReader::from($scope, 'org_units', 'u') puts the scope first and
    // the table second, which is the shape the whole rule is steering code
    // towards, so a leading variable means "look at the next argument" rather
    // than "this is unreadable".
    if ($token->id === T_VARIABLE && ($tokens[$at + 1]->text ?? '') === ',') {
        return tableArgument($tokens, $at + 2, $constants);
    }

    if ($token->id === T_CONSTANT_ENCAPSED_STRING) {
        return trim($token->text, "'\"");
    }

    // self::TABLE, static::TABLE, ClassName::TABLE: one file's own constant,
    // which is the spelling the persistence classes actually use.
    if (
        ($tokens[$at + 1]->id ?? 0) === T_DOUBLE_COLON
        && ($tokens[$at + 2]->id ?? 0) === T_STRING
    ) {
        return $constants[$tokens[$at + 2]->text] ?? null;
    }

    return null;
}

/**
 * Every string constant a file declares, by name.
 *
 * Only the name is keyed, not the class, because a file naming two constants
 * with one name and two values is not a shape this repository has and the
 * conservative reading of a collision is the one that keeps checking.
 *
 * @param list<PhpToken> $tokens
 *
 * @return array<string, string>
 */
function phpStringConstants(array $tokens, int $count): array
{
    $constants = [];

    for ($i = 0; $i < $count - 3; $i++) {
        if (
            $tokens[$i]->id !== T_CONST
            || ($tokens[$i + 1]->id ?? 0) !== T_STRING
            || ($tokens[$i + 2]->text ?? '') !== '='
            || ($tokens[$i + 3]->id ?? 0) !== T_CONSTANT_ENCAPSED_STRING
        ) {
            continue;
        }

        $constants[$tokens[$i + 1]->text] = trim($tokens[$i + 3]->text, "'\"");
    }

    return $constants;
}

/**
 * Whether the statement starting at a from() call narrows on the tenant.
 *
 * The predicate has to be an **argument of a narrowing call**, which is the
 * half the first draft left out: it matched the token anywhere between the
 * from() and the semicolon, so naming organisation_id in a select() list or
 * an orderBy() satisfied it. An exempt file reading every membership row on
 * the site with no where clause at all therefore passed, and the exemption
 * list's stated guarantee is precisely that the predicate is still checked.
 *
 * @param list<PhpToken> $tokens
 */
function statementNarrowsOnTenant(array $tokens, int $start, int $count): bool
{
    $depth = 0;

    for ($i = $start; $i < $count; $i++) {
        $text = $tokens[$i]->text;

        if ($text === '(' || $text === '[') {
            $depth++;

            continue;
        }

        if ($text === ')' || $text === ']') {
            $depth--;

            continue;
        }

        if ($text === ';' && $depth <= 0) {
            return false;
        }

        if ($tokens[$i]->id !== T_STRING) {
            continue;
        }

        // constrainUnit() carries the closure join on top of a predicate the
        // scope already holds, so the call itself is the narrowing.
        if ($text === 'constrainUnit') {
            return true;
        }

        if (!in_array($text, ORG_NARROWING_CALLS, true) || ($tokens[$i + 1]->text ?? '') !== '(') {
            continue;
        }

        if (namesTenantColumn($tokens, $i + 1, $count)) {
            return true;
        }
    }

    return false;
}

/**
 * Whether the argument list opening at $open names a tenant column.
 *
 * It reads only that call's own arguments, so a where() on an unrelated
 * column followed by a select() naming organisation_id is not read as a
 * narrowing. An alias prefix is stripped, because "m.organisation_id" and
 * "organisation_id" are the same predicate.
 *
 * @param list<PhpToken> $tokens
 */
function namesTenantColumn(array $tokens, int $open, int $count): bool
{
    $depth = 0;

    for ($i = $open; $i < $count; $i++) {
        $text = $tokens[$i]->text;

        if ($text === '(' || $text === '[') {
            $depth++;

            continue;
        }

        if ($text === ')' || $text === ']') {
            $depth--;

            if ($depth <= 0) {
                return false;
            }

            continue;
        }

        $candidate = match ($tokens[$i]->id) {
            T_CONSTANT_ENCAPSED_STRING => trim($text, "'\""),
            // The predicate vocabulary names two variable spellings as well as
            // the column, because the organisation's own table is narrowed on
            // its primary key with the tenant in the value.
            T_VARIABLE => ltrim($text, '$'),
            default => $text,
        };
        $bare = str_contains($candidate, '.') ? substr($candidate, strrpos($candidate, '.') + 1) : $candidate;

        if (in_array($bare, ORG_READ_PREDICATES, true) || in_array($candidate, ORG_READ_PREDICATES, true)) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<string>
 */
function checkProtocolWords(): array
{
    $forbidden = array_flip(PROTOCOL_WORDS);

    return tokenMatchesIn(['packages/core/src', 'packages/core/schema'], static function (array $file) use ($forbidden): array {
        $relative = str_replace('\\', '/', (string) $file['relative']);

        if (in_array($relative, PROTOCOL_WORD_EXEMPT, true)) {
            return [];
        }

        $hits = [];
        /** @var list<array{line: int, text: string}> $identifiers */
        $identifiers = $file['identifiers'];
        /** @var list<array{line: int, text: string}> $strings */
        $strings = $file['strings'];

        foreach ([['identifier', $identifiers], ['string', $strings]] as [$kind, $tokens]) {
            foreach ($tokens as $token) {
                foreach (protocolSegments($token['text']) as $segment) {
                    if (isset($forbidden[$segment])) {
                        $hits[] = [$token['line'], sprintf('%s names "%s" in code', $kind, $segment)];
                    }
                }
            }
        }

        return $hits;
    });
}

/**
 * One identifier or string literal split into the words it is made of.
 *
 * Splitting rather than substring matching, in both directions. A substring
 * scan would refuse "multiply" for containing a protocol name and would miss a
 * class called after one in camel case, so the text is broken at every case
 * change and at every character that is not a letter or a digit, and each
 * piece is compared whole.
 *
 * @return list<string>
 */
function protocolSegments(string $text): array
{
    $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $text) ?? $text;
    $pieces = preg_split('/[^A-Za-z0-9]+/', $spaced) ?: [];
    $segments = [];

    foreach ($pieces as $piece) {
        if ($piece !== '') {
            $segments[] = strtolower($piece);
        }
    }

    return $segments;
}

/**
 * @return list<string>
 */
function checkModelBridge(): array
{
    /**
     * The seam itself, its registry, and the one shipped file that seats every
     * registry the core has. Registries names the type because it holds the
     * property; nothing else may.
     */
    $exempt = [
        'packages/core/src/Registry/Ai/ModelBridgeInterface.php',
        'packages/core/src/Registry/Ai/ModelBridgeRegistry.php',
        'packages/core/src/Registry/Registries.php',
        // The host package's own offline bridge harness. It constructs a
        // BridgeRequest deliberately, in order to drive a bridge with no
        // Joomla, no com_aicore, no database and no network, which is the only
        // way that bridge is testable at all. It is an exemption by name rather
        // than a tree left unscanned, which is the difference the org-reads
        // rule already draws: a NEW construction anywhere in these trees is a
        // gate failure, and this one is a signed decision.
        'packages/pro-joomla/build/ai-bridge-test.php',
    ];

    /**
     * The one application tree allowed to hold a bridge, which is the rule as
     * the design states it: nothing outside packages/core/src/Application/Ai
     * references the seam or constructs a BridgeRequest. AiBridgeCaller lives
     * there and is the single holder; the rest of that tree is the ledger and
     * narrower it collaborates with.
     */
    $holder = 'packages/core/src/Application/Ai/';

    /**
     * The other half of the same rule, and the half the seam grep cannot
     * carry. A BridgeRequest is what actually crosses the boundary, its
     * constructor is private and its one named factory takes a RedactedPayload
     * and nothing else, so a caller that can build one is a caller that has
     * chosen its own segments. Naming the type is fine and unavoidable: every
     * bridge takes one as a parameter and returns against it. **Calling a
     * static on it is the construction**, so that is what is refused, which
     * leaves an implementation free to type-hint the class it is handed.
     */
    $construction = ['BridgeRequest::', 'new BridgeRequest'];
    $requestHome = 'packages/core/src/Registry/Ai/BridgeRequest.php';

    /**
     * The third half, added after a review built a working caller from a
     * vendor package without the file ever containing the string above.
     *
     * `AiBridgeCaller::forRun()` takes seven collaborators and every one of
     * them is publicly constructible, so the single-holder rule held only for
     * code that chose to obey it. Constructing the holder, or the envelope it
     * reads its route chain from, is refused outside the holder tree; naming
     * either type is not, so a task may still type-hint the caller it is
     * handed.
     */
    $holderConstruction = ['AiBridgeCaller::', 'new AiBridgeCaller', 'AiCallEnvelope::', 'new AiCallEnvelope'];

    return matchesIn(
        MODEL_BRIDGE_ROOTS,
        static function (
            string $line,
            string $relative
        ) use ($exempt, $holder, $construction, $requestHome, $holderConstruction): ?string {
            if (str_starts_with($relative, $holder)) {
                return null;
            }

            // The exemption is read before the construction arms and not only
            // before the naming one. A file on the list is a signed decision
            // about that file, and the harness that is on it is on it precisely
            // because it constructs.
            if (in_array($relative, $exempt, true)) {
                return null;
            }

            foreach ($holderConstruction as $needle) {
                if (str_contains($line, $needle)) {
                    return 'bridge holder construction';
                }
            }

            if ($relative !== $requestHome) {
                foreach ($construction as $needle) {
                    if (str_contains($line, $needle)) {
                        return 'BridgeRequest construction';
                    }
                }
            }

            if (!str_contains($line, 'ModelBridgeInterface')) {
                return null;
            }

            if (in_array($relative, $exempt, true)) {
                return null;
            }

            // A bridge is not a class holding a bridge; it is one. A provider
            // implementing the seam has to name it to implement it, and refusing
            // that would refuse the free provider the seam's own rule requires.
            // Only the implements clause and the import that serves it are
            // allowed, so a class that merely resolves one still fails.
            if (isBridgeDeclaration($line)) {
                return null;
            }

            return 'ModelBridgeInterface';
        },
    );
}

/**
 * Whether this line is a class declaring itself a bridge, or the import that
 * declaration needs.
 *
 * Both forms are matched because PSR-12 splits a long implements list across
 * lines, so the seam name routinely sits on a line of its own directly under
 * the class name. That line is still part of the declaration and is allowed
 * exactly as the single-line form is; anything else naming the type is not.
 */
function isBridgeDeclaration(string $line): bool
{
    $trimmed = trim($line);

    return $trimmed === 'use Shondalai\VidyaLMS\Core\Registry\Ai\ModelBridgeInterface;'
        || $trimmed === 'ModelBridgeInterface,'
        || $trimmed === 'ModelBridgeInterface'
        || str_contains($trimmed, 'implements ModelBridgeInterface');
}

/**
 * @return list<string>
 */
function checkManageGroups(): array
{
    $named = [
        'packages/core/src/Application/Shared/Capability.php',
        'packages/core/src/Application/Organisation/OrgScopeResolver.php',
    ];

    // Tokens rather than lines, for two reasons the line scan got wrong in
    // opposite directions. Wrapping the call across three lines, which the
    // phpcs line limit encourages, put "->require(" and "MANAGE_GROUPS" on
    // different lines and the demand disappeared. And a docblock sentence
    // saying a use case deliberately does not consult MANAGE_GROUPS failed the
    // gate, because prose counted as a use. The argument walk crosses
    // newlines and comments and strings are dropped before anything is read.
    return tokenMatchesIn(PHP_SOURCE_ROOTS, static function (array $file) use ($named): array {
        $found = [];
        $witness = in_array($file['relative'], $named, true);

        foreach ($file['calls'] as $call) {
            if (!in_array($call['method'], ['require', 'requireAny'], true)) {
                continue;
            }

            if (!in_array('MANAGE_GROUPS', $call['arguments'], true)) {
                continue;
            }

            $found[] = [$call['line'], 'MANAGE_GROUPS required with no organisation resource'];
        }

        if ($witness) {
            return $found;
        }

        foreach ($file['identifiers'] as $identifier) {
            if ($identifier['text'] !== 'MANAGE_GROUPS') {
                continue;
            }

            $found[] = [$identifier['line'], 'MANAGE_GROUPS named outside the resolver'];
        }

        return $found;
    });
}

/**
 * The same walk as matchesIn(), over a parsed file rather than its lines.
 *
 * A rule about what the code does, rather than about how a line is spelled,
 * cannot be enforced by reading lines: an import alias renames the class, a
 * wrapped call splits the argument from the method, and a docblock mentioning
 * a constant is prose and not a use. The matcher receives the shape below and
 * returns [line, reason] pairs.
 *
 * @param list<string>                                                  $roots
 * @param callable(array<string, mixed>): list<array{0: int, 1: string}> $matcher
 *
 * @return list<string>
 */
function tokenMatchesIn(array $roots, callable $matcher): array
{
    $violations = [];

    foreach ($roots as $root) {
        foreach (walk($root) as $relative => $path) {
            if (!str_ends_with($relative, '.php')) {
                continue;
            }

            $contents = readText($path);

            if ($contents === null) {
                continue;
            }

            $file = parsePhp($contents);
            $file['relative'] = $relative;

            foreach ($matcher($file) as [$line, $reason]) {
                $violations[] = sprintf('%s:%d: %s', $relative, $line, $reason);
            }
        }
    }

    return $violations;
}

/**
 * One PHP file reduced to the facts these checks ask about.
 *
 * Returns the import aliases with the line each was declared on, every name
 * token with its fully resolved class name and whether it is a construction or
 * a static reference, every method call with its method name and the bare
 * identifiers inside its argument list, every identifier token outside a
 * comment or a string, and every string literal. Comments and docblocks are
 * never part of any of them.
 *
 * @return array<string, mixed>
 */
function parsePhp(string $code): array
{
    $tokens = PhpToken::tokenize($code);
    /** @var list<PhpToken> $significant */
    $significant = [];

    foreach ($tokens as $token) {
        if ($token->id === T_WHITESPACE || $token->id === T_COMMENT || $token->id === T_DOC_COMMENT) {
            continue;
        }

        $significant[] = $token;
    }

    $namespace = '';
    $aliases = [];
    $aliasLines = [];
    $names = [];
    $identifiers = [];
    $strings = [];
    $calls = [];
    $count = count($significant);

    for ($i = 0; $i < $count; $i++) {
        $token = $significant[$i];

        if ($token->id === T_NAMESPACE) {
            $parts = '';

            for ($j = $i + 1; $j < $count && $significant[$j]->text !== ';' && $significant[$j]->text !== '{'; $j++) {
                $parts .= $significant[$j]->text;
            }

            $namespace = trim($parts, '\\');

            continue;
        }

        if ($token->id === T_USE && ($significant[$i + 1]->id ?? 0) !== T_FUNCTION) {
            $imported = '';
            $alias = null;
            $sawAs = false;

            for ($j = $i + 1; $j < $count && $significant[$j]->text !== ';'; $j++) {
                if ($significant[$j]->id === T_AS) {
                    $sawAs = true;

                    continue;
                }

                if ($sawAs) {
                    $alias = $significant[$j]->text;

                    continue;
                }

                $imported .= $significant[$j]->text;
            }

            $imported = trim($imported, '\\');

            if ($imported !== '' && !str_contains($imported, '{') && !str_contains($imported, '(')) {
                $segments = explode('\\', $imported);
                $local = $alias ?? end($segments);
                $aliases[$local] = $imported;
                $aliasLines[$local] = $token->line;
            }

            continue;
        }

        if ($token->id === T_CONSTANT_ENCAPSED_STRING) {
            $strings[] = [
                'line' => $token->line,
                'text' => stripcslashes(substr($token->text, 1, -1)),
            ];

            continue;
        }

        if ($token->id === T_STRING) {
            $identifiers[] = ['line' => $token->line, 'text' => $token->text];
        }

        if (!in_array($token->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            continue;
        }

        $previous = $significant[$i - 1] ?? null;
        $next = $significant[$i + 1] ?? null;

        // A method call: "->name(" or "?->name(". Its arguments are collected
        // to the matching close parenthesis, so wrapping the call over three
        // lines changes nothing.
        if (
            $previous !== null
            && in_array($previous->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)
            && $next !== null
            && $next->text === '('
        ) {
            $calls[] = [
                'line' => $token->line,
                'method' => $token->text,
                'arguments' => argumentIdentifiers($significant, $i + 1, $count),
            ];
        }

        if ($previous !== null && $previous->id === T_DOUBLE_COLON) {
            continue;
        }

        if ($previous !== null && in_array($previous->id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            continue;
        }

        $names[] = [
            'line' => $token->line,
            'resolved' => resolveName($token, $namespace, $aliases),
            'construction' => $previous !== null && $previous->id === T_NEW,
            'static' => $next !== null && $next->id === T_DOUBLE_COLON,
        ];
    }

    return [
        'namespace' => $namespace,
        'aliases' => $aliases,
        'aliasLines' => $aliasLines,
        'names' => $names,
        'identifiers' => $identifiers,
        'strings' => $strings,
        'calls' => $calls,
    ];
}

/**
 * The bare identifiers inside one argument list, from its open parenthesis to
 * the parenthesis that closes it.
 *
 * @param list<PhpToken> $tokens
 *
 * @return list<string>
 */
function argumentIdentifiers(array $tokens, int $open, int $count): array
{
    $depth = 0;
    $found = [];

    for ($i = $open; $i < $count; $i++) {
        $text = $tokens[$i]->text;

        if ($text === '(') {
            $depth++;

            continue;
        }

        if ($text === ')') {
            $depth--;

            if ($depth === 0) {
                return $found;
            }

            continue;
        }

        if ($tokens[$i]->id === T_STRING) {
            $found[] = $text;
        }
    }

    return $found;
}

/**
 * A name token's fully qualified class name, through the file's own imports.
 *
 * @param array<string, string> $aliases
 */
function resolveName(PhpToken $token, string $namespace, array $aliases): string
{
    $text = $token->text;

    if ($token->id === T_NAME_FULLY_QUALIFIED) {
        return ltrim($text, '\\');
    }

    $segments = explode('\\', $text);
    $first = $segments[0];

    if (isset($aliases[$first])) {
        $segments[0] = $aliases[$first];

        return implode('\\', $segments);
    }

    return ($namespace === '' ? '' : $namespace . '\\') . $text;
}

/**
 * Every line of every PHP file under the given roots that the matcher names.
 *
 * @param list<string>                              $roots
 * @param callable(string, string): (string|null)   $matcher
 *
 * @return list<string>
 */
function matchesIn(array $roots, callable $matcher): array
{
    $violations = [];

    foreach ($roots as $root) {
        foreach (walk($root) as $relative => $path) {
            if (!str_ends_with($relative, '.php')) {
                continue;
            }

            $contents = readText($path);

            if ($contents === null) {
                continue;
            }

            foreach (explode("\n", $contents) as $number => $line) {
                $reason = $matcher($line, $relative);

                if ($reason === null) {
                    continue;
                }

                $violations[] = sprintf('%s:%d: %s', $relative, $number + 1, $reason);
            }
        }
    }

    return $violations;
}

/**
 * Every file under a repository-relative directory, keyed by its relative path,
 * with the excluded trees skipped.
 *
 * @return iterable<string, string>
 */
function walk(string $root): iterable
{
    $base = realpath(gateRoot());
    $start = realpath(gateRoot() . '/' . $root);

    if ($base === false || $start === false) {
        return;
    }

    $base = str_replace('\\', '/', $base);

    // A root may name one file. Two of the OrgScope roots are installer
    // scripts that sit beside a package rather than inside a source tree, and
    // an entry the walker silently skipped would be a root on the list that
    // reads nothing, which is the shape of gap this check was widened to close.
    if (is_file($start)) {
        $path = str_replace('\\', '/', $start);
        $relative = substr($path, strlen($base) + 1);

        if (!excluded($relative)) {
            yield $relative => $path;
        }

        return;
    }
    // Excluded directories are pruned rather than filtered per file, so a
    // node_modules tree is never descended into. Walking the repository root
    // without this reads a quarter of a million files nobody asked about.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($start, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $entry) use ($base): bool {
                $path = str_replace('\\', '/', $entry->getPathname());
                $relative = substr($path, strlen($base) + 1);

                return !excluded($relative . ($entry->isDir() ? '/' : ''));
            },
        ),
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());
        $relative = substr($path, strlen($base) + 1);

        if (excluded($relative)) {
            continue;
        }

        yield $relative => $path;
    }
}

/**
 * Whether a repository-relative path lies inside an excluded tree.
 */
function excluded(string $relative): bool
{
    foreach (EXCLUDED_SEGMENTS as $segment) {
        if (str_contains('/' . $relative, $segment)) {
            return true;
        }
    }

    foreach (EXCLUDED_ROOT_TREES as $tree) {
        if (str_starts_with($relative, $tree)) {
            return true;
        }
    }

    return false;
}

/**
 * The file's text, or null when it is binary or unreadable. Binary files are
 * skipped rather than reported: a byte sequence inside a font or an image is
 * not prose.
 */
function readText(string $path): ?string
{
    $contents = @file_get_contents($path);

    if ($contents === false || $contents === '') {
        return null;
    }

    if (str_contains($contents, "\0")) {
        return null;
    }

    return $contents;
}

function excerpt(string $line): string
{
    $trimmed = trim($line);

    return mb_strlen($trimmed) > 120 ? mb_substr($trimmed, 0, 117) . '...' : $trimmed;
}
