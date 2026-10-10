<?php

/**
 * The neutral VidyaLMS schema, v1 (free build).
 *
 * Logical table names only; adapters prefix them ("#__vidyalms_courses" on
 * Joomla, "{$wpdb->prefix}vidyalms_courses" on WordPress). Every table gets
 * an integer primary key "id", "created_at" and "updated_at" automatically;
 * pass "timestamps" => false to opt out.
 *
 * Where a row belongs to a person, the CMS user id is a plain integer,
 * never a foreign key into CMS tables. JSON columns hold shape that varies
 * by provider (lesson content, question definitions); everything reports
 * filter on is a real column.
 *
 * Datetimes are stored as UTC "Y-m-d H:i:s". Booleans are 0/1.
 *
 * Column spec grammar: "[?]type[:args]" where "?" means nullable.
 * Types: uint, int, bigint, bool, string:N, text, mediumtext, json,
 * datetime, date, decimal:P,S, float. Array form adds a default:
 * ["string:20", "default" => "draft"].
 */

declare(strict_types=1);

return [
    'privacy_file_deletions' => [
        'columns' => [
            'path' => 'string:1024',
            'path_hash' => 'string:64',
            'user_id' => 'uint',
            'attempts' => ['uint', 'default' => 0],
        ],
        'indexes' => [
            ['columns' => ['path_hash'], 'unique' => true],
            ['columns' => ['user_id']],
        ],
    ],
    // ── Curriculum ────────────────────────────────────────────────────────
    'courses' => [
        'columns' => [
            'title' => 'string:255',
            'slug' => 'string:190',
            'summary' => '?text',
            'description' => '?mediumtext',
            'image' => '?string:1024',
            'category_id' => '?uint',
            'level' => '?string:50',
            'language' => '?string:20',
            'visibility' => ['string:20', 'default' => 'public'],
            'state' => ['string:20', 'default' => 'draft'],
            'price_mode' => ['string:20', 'default' => 'free'],
            'completion_rule' => ['string:30', 'default' => 'all_lessons'],
            'completion_threshold' => '?uint',
            'featured' => ['bool', 'default' => 0],
            'created_by' => 'uint',
            'published_at' => '?datetime',
            // Which registered enrolment rule decides self enrolment here.
            // Without it no registered rule is reachable at all from the free
            // self-enrol path, which consulted one hardcoded key.
            'enrolment_rule' => ['string:30', 'default' => 'open'],
            // linear or free. Read on every outline render and every start;
            // linear is ANDed into the availability evaluation at read time
            // and never written into anyone's stored rule, so flipping the
            // axis needs no migration and loses no authored restriction.
            'progression' => ['string:10', 'default' => 'free'],
            // The second step of the resolution chain for an authored
            // wall-clock instant: cohort timezone, then course, then site,
            // and never the PHP process timezone.
            'timezone' => '?string:64',
            // The course.starts_at schedule anchor resolves this, and the
            // catalogue filters on it.
            'starts_at' => '?datetime',
            // The late policy registry's free reach. Storing the key only on
            // the Pro cohorts table would leave the free "none" provider a
            // provider no free path can resolve. StoredShape-wrapped params
            // with the version mirrored beside them, because a late outcome
            // must be as replayable as attempt_grades.scoring_params is.
            'late_policy_key' => ['string:30', 'default' => 'none'],
            'late_policy_params' => '?json',
            'late_policy_params_version' => ['uint', 'default' => 1],
            // Which registered commerce source sells this course. Without it
            // no second source is reachable at all from GET /courses/{slug},
            // which resolved one hardcoded key: exactly the defect
            // enrolment_rule above already fixed for self enrolment. Indexed
            // because the catalogue filters on it. Defaulted to cms-shop so
            // every existing row keeps the behaviour it had, and read through
            // a has() guard so an uninstalled source answers a null price
            // with a stated reason rather than throwing.
            'commerce_source' => ['string:40', 'default' => 'cms-shop'],
        ],
        'indexes' => [
            ['columns' => ['slug'], 'unique' => true],
            ['columns' => ['state']],
            ['columns' => ['category_id']],
            ['columns' => ['starts_at']],
            ['columns' => ['commerce_source']],
        ],
    ],
    'modules' => [
        'columns' => [
            'course_id' => 'uint',
            'title' => 'string:255',
            'summary' => '?text',
            'position' => 'uint',
            'state' => ['string:20', 'default' => 'published'],
            // Null means inherit the course, which is the only sane default
            // for a column added to existing rows.
            'progression' => '?string:10',
        ],
        'indexes' => [
            ['columns' => ['course_id', 'position']],
        ],
    ],
    'lessons' => [
        'columns' => [
            'course_id' => 'uint',
            'module_id' => 'uint',
            'title' => 'string:255',
            'slug' => 'string:190',
            'type' => 'string:30',
            'summary' => '?text',
            'duration_seconds' => '?uint',
            'position' => 'uint',
            'state' => ['string:20', 'default' => 'published'],
            'free_preview' => ['bool', 'default' => 0],
            // The per-lesson half of the sequencing axis. Sequencing is
            // orthogonal to availability and is never a stored condition.
            'requires_previous' => ['bool', 'default' => 0],
        ],
        'indexes' => [
            ['columns' => ['course_id', 'slug'], 'unique' => true],
            ['columns' => ['module_id', 'position']],
            ['columns' => ['course_id']],
        ],
    ],
    'lesson_content' => [
        'columns' => [
            'lesson_id' => 'uint',
            'content' => 'json',
        ],
        'indexes' => [
            ['columns' => ['lesson_id'], 'unique' => true],
        ],
    ],
    'course_prerequisites' => [
        'columns' => [
            'course_id' => 'uint',
            'prerequisite_course_id' => 'uint',
        ],
        'indexes' => [
            ['columns' => ['course_id', 'prerequisite_course_id'], 'unique' => true],
        ],
    ],
    'course_instructors' => [
        'columns' => [
            'course_id' => 'uint',
            'user_id' => 'uint',
            'role' => ['string:20', 'default' => 'lead'],
        ],
        'indexes' => [
            ['columns' => ['course_id', 'user_id'], 'unique' => true],
            ['columns' => ['user_id']],
        ],
    ],

    // ── Assessment ────────────────────────────────────────────────────────
    'quizzes' => [
        'columns' => [
            'course_id' => 'uint',
            'lesson_id' => '?uint',
            'title' => 'string:255',
            'description' => '?text',
            'pass_mark' => ['uint', 'default' => 0],
            'max_attempts' => '?uint',
            'time_limit_seconds' => '?uint',
            'is_final' => ['bool', 'default' => 0],
            'show_results' => ['string:20', 'default' => 'after_submit'],
            // Presentation policy, resolved once at StartAttempt and frozen
            // onto the attempt. Shuffle is the one quiz policy that reaches
            // into an item, and it reaches the interaction primitive rather
            // than the question type.
            'shuffle_questions' => ['bool', 'default' => 0],
            'shuffle_options' => ['bool', 'default' => 0],
            'one_per_page' => ['bool', 'default' => 1],
            // The attempt behaviour this quiz runs under. Null is the free
            // default, deferred_feedback. It is a real column because the
            // key can name a provider the site no longer has, and that miss
            // has to be resolvable at StartAttempt: an unresolved behaviour
            // falls back to deferred_feedback and records the substitution
            // on the attempt rather than throwing.
            'behaviour' => '?string:30',
            // Navigation and submission are properties of a structural node
            // rather than global flags, and these are the quiz-level
            // defaults a section may narrow. In simultaneous mode response
            // processing cannot run until the part completes, so the
            // candidate gets one try per item by definition.
            'navigation_mode' => ['string:20', 'default' => 'nonlinear'],
            'submission_mode' => ['string:20', 'default' => 'individual'],
            // Availability and expiry. The deadline is derived rather than
            // stored, as the earlier of started_at plus the limit and
            // closes_at, so changing a close time changes live attempts
            // consistently; attempts.expires_at caches the derived value so
            // the sweeper can index it.
            'opens_at' => '?datetime',
            'closes_at' => '?datetime',
            'expiry_policy' => ['string:20', 'default' => 'auto_submit'],
            // The 60 second constant is the floor for clock skew, not merely
            // the default.
            'grace_seconds' => ['uint', 'default' => 60],
            'auto_submit' => ['bool', 'default' => 1],
            // Two durations rather than one, because the common pedagogical
            // pattern is a short gap after the first failure and a longer
            // one after that.
            'cooldown_after_first_seconds' => '?uint',
            'cooldown_after_later_seconds' => '?uint',
            // Two orthogonal settings rather than one four-valued grading
            // method: attempts_counted filters the attempt set,
            // attempt_aggregation reduces what is left, and the aggregator
            // is itself a provider so "average of the best three" needs no
            // migration. The params column is where that provider's n or
            // decay constant lives.
            'attempts_counted' => ['string:10', 'default' => 'all'],
            'attempt_aggregation' => ['string:30', 'default' => 'highest'],
            'attempt_aggregation_params' => '?json',
            // Which composition decides what an attempt is served, when the
            // quiz's form is not simply its own published questions. Null is
            // the free default and is every quiz authored question by
            // question. A real column because StartAttempt has to be able to
            // tell "this quiz has no questions" from "this quiz is built
            // from a placement tree the package that reads it is gone": the
            // first is a survey nobody has written yet, the second served a
            // learner an empty exam that graded, passed at a pass mark of 0
            // and consumed an attempt.
            'composition' => '?string:40',
            // Off by default, and disclosed in the learner instructions when
            // it is not. The resolved rule is recorded on the attempt, so a
            // later regrade under a different rule is auditable rather than
            // silently different.
            'negative_marking_policy' => '?string:40',
            'allow_quiz_negative' => ['bool', 'default' => 0],
            // In fraction space, where both operands are dimensionless, and
            // applied per part rather than per question.
            'try_penalty' => ['decimal:5,4', 'default' => 0],
            // qti-item-session-control, names and defaults verbatim, so a
            // migrating author meets no surprise. Prefixed because the quiz
            // already has a max_attempts of its own and the two are
            // different things.
            'item_max_attempts' => ['uint', 'default' => 1],
            'item_show_feedback' => ['bool', 'default' => 0],
            'item_allow_review' => ['bool', 'default' => 1],
            'item_show_solution' => ['bool', 'default' => 0],
            'item_allow_comment' => ['bool', 'default' => 0],
            'item_allow_skipping' => ['bool', 'default' => 1],
            'item_validate_responses' => ['bool', 'default' => 0],
            // The review visibility matrix: what a learner may see of their
            // own attempt, per phase. JSON rather than columns because it is
            // read as one set at attempt start and frozen with the rest of
            // the policy, and nothing filters or groups on a single cell;
            // named members also mean revealing something new is a key
            // rather than a bit nobody can read. Null means derive it from
            // show_results, which stays the coarse switch.
            'review_visibility' => '?json',
        ],
        'indexes' => [
            ['columns' => ['course_id']],
            ['columns' => ['lesson_id']],
        ],
    ],
    'questions' => [
        'columns' => [
            'quiz_id' => 'uint',
            'type' => 'string:30',
            'position' => 'uint',
            'points' => ['decimal:8,2', 'default' => 1],
            'definition' => 'json',
            'state' => ['string:20', 'default' => 'published'],
            // A batch upgrade finds affected rows with an index rather than
            // a portability-hostile JSON path query, and the version gate
            // recognises a row newer than the resolved provider before it is
            // mismarked.
            'definition_version' => ['uint', 'default' => 1],
            // The generic fallback renderer, the answer validator and an
            // export all have to work with the provider uninstalled, so none
            // of them may ask the provider what shape a response takes.
            'response_shape' => '?string:30',
            // Item analysis filters on it, and the totals exclude an
            // unscored item from both sums, which is what makes an opinion
            // scale authorable without moving a pass percentage.
            'scored' => ['bool', 'default' => 1],
            // Reporting filters and groups on the policy key. A derived
            // mirror of the definition's policy reference, written by the
            // same code path, never an independent input.
            'scoring_policy' => '?string:40',
            'scoring_params' => '?json',
            // The pending-external-scoring queue is a filtered query.
            'external_scored' => '?string:20',
            // One stimulus serves many questions, and the attempt view groups
            // consecutive questions sharing one into a single screen.
            'stimulus_id' => '?uint',
            // Two joins. The free build never writes either, and both are
            // free columns for the reason every column on this list is one:
            // a later release cannot retroactively populate a column that
            // was never written, and a row must survive with the package
            // that wrote it uninstalled.
            'bank_entry_id' => '?uint',
            // A version rather than a rubric, because the grade records
            // which version it ran under and editing a rubric must never
            // move a mark already given.
            'rubric_version_id' => '?uint',
        ],
        'indexes' => [
            ['columns' => ['quiz_id', 'position']],
            ['columns' => ['definition_version']],
            ['columns' => ['scoring_policy']],
            ['columns' => ['external_scored']],
            ['columns' => ['stimulus_id']],
            ['columns' => ['bank_entry_id']],
        ],
    ],
    'stimuli' => [
        'columns' => [
            'course_id' => 'uint',
            'title' => 'string:255',
            'body' => '?mediumtext',
            'media' => '?json',
            'alt_text' => '?text',
            'long_description' => '?mediumtext',
        ],
        'indexes' => [
            ['columns' => ['course_id']],
        ],
    ],
    'attempts' => [
        'columns' => [
            'quiz_id' => 'uint',
            'enrolment_id' => 'uint',
            'user_id' => 'uint',
            'attempt_number' => 'uint',
            'status' => ['string:20', 'default' => 'in_progress'],
            'started_at' => 'datetime',
            'submitted_at' => '?datetime',
            'score' => '?decimal:8,2',
            'max_score' => '?decimal:8,2',
            'percentage' => '?decimal:5,2',
            'passed' => '?bool',
            'time_spent_seconds' => '?uint',
            // Selection, ordering, option shuffle and variable binding all
            // derive from the seed, so a form is reproducible for support,
            // disputes and regrades.
            'presentation_seed' => '?string:32',
            // The frozen policy set. Changing a quiz setting cannot silently
            // regrade an attempt already in flight, because the attempt
            // carries the policy it started under.
            'policy_snapshot' => '?json',
            'behaviour' => '?string:30',
            // Derived and cached so a sweeper can index it.
            'expires_at' => '?datetime',
            // Why an attempt the learner had open was closed for them, and
            // which seat ledger row caused it. Both null for every attempt the
            // learner ended themselves, which is nearly all of them.
            //
            // They are on the attempt rather than only in the ledger because
            // the learner is the person who has to be able to see it: an
            // attempt that ended at minute forty because an administrator
            // reclaimed the seat is otherwise indistinguishable from one they
            // abandoned, and the drill-through an auditor needs runs the other
            // way, from the attempt to the seat_events row that closed it.
            'closure_reason' => '?string:60',
            'closed_by_seat_event_id' => '?uint',
        ],
        'indexes' => [
            ['columns' => ['quiz_id', 'user_id', 'attempt_number'], 'unique' => true],
            ['columns' => ['enrolment_id']],
            ['columns' => ['user_id']],
            ['columns' => ['expires_at']],
        ],
    ],
    'attempt_questions' => [
        'columns' => [
            'attempt_id' => 'uint',
            'position' => 'uint',
            // Consecutive questions sharing a stimulus are one screen.
            'screen' => ['uint', 'default' => 0],
            'question_id' => 'uint',
            'question_version_id' => '?uint',
            'points' => ['decimal:8,2', 'default' => 1],
            'scored' => ['bool', 'default' => 1],
            // Exactly what presentForLearner returned. Storage refs, never a
            // signed URL: a signature expires and a frozen expired URL
            // breaks review, appeals, manual grading and the removal
            // placeholder on exactly the image-bearing types that need them.
            'presented' => 'json',
            // QTI choiceSequence: the order actually shown.
            'choice_sequence' => '?json',
            'variant' => '?json',
            'definition_hash' => 'string:64',
            'answered_status' => ['string:20', 'default' => 'notpresented'],
            // The marking rule this placement overrode the question's own
            // with, frozen with everything else the attempt was served.
            // Real columns rather than a key inside variant, because the
            // grading path reads them on every submit and every regrade and
            // reporting groups on the same key the grade row carries: a
            // value that lives only in the audit blob is a value nothing
            // reads, which is exactly what happened to the slot override.
            'policy_key' => '?string:40',
            'policy_params' => '?json',
        ],
        'indexes' => [
            ['columns' => ['attempt_id', 'position'], 'unique' => true],
            ['columns' => ['question_id']],
            // Question-bank usage and last-used filters join a bank version
            // directly to the frozen attempt manifest. Without this index a
            // bounded bank page can still force a scan of every served item.
            ['columns' => ['question_version_id']],
            ['columns' => ['definition_hash']],
        ],
    ],
    'question_definitions' => [
        'columns' => [
            'definition_hash' => 'string:64',
            'type' => 'string:30',
            'definition_version' => ['uint', 'default' => 1],
            'definition' => 'json',
        ],
        'indexes' => [
            ['columns' => ['definition_hash'], 'unique' => true],
        ],
    ],
    'attempt_answers' => [
        'columns' => [
            'attempt_id' => 'uint',
            'question_id' => 'uint',
            'answer' => 'json',
            'answered_at' => 'datetime',
            // PCI getState and setState: a half-finished drag survives
            // navigation. Without setState the column would be written and
            // never read.
            'ui_state' => '?json',
            // The submitted string. Precision marking must judge on it and
            // it is impossible to backfill.
            'raw_input' => '?text',
            // interactive, non_drag_pointer, keyboard, linear_alternative,
            // scribe, braille_transcript. An audit must prove alternatives
            // were used and not merely offered.
            'presentation_form' => ['string:20', 'default' => 'interactive'],
            'certainty' => '?uint',
        ],
        'indexes' => [
            ['columns' => ['attempt_id', 'question_id'], 'unique' => true],
        ],
    ],
    'attempt_answer_events' => [
        'columns' => [
            'attempt_id' => 'uint',
            'question_id' => 'uint',
            'part_id' => '?string:64',
            'try' => ['uint', 'default' => 1],
            'response' => 'json',
            'raw_input' => '?text',
            'presentation_form' => ['string:20', 'default' => 'interactive'],
            'certainty' => '?uint',
            // Whether the learner submitted this response for processing,
            // which is what a try means. An autosave is recorded (this table
            // is where per-item timing lives) and is not a try: counting
            // every changed autosave made the try penalty charge a learner
            // for changing their mind.
            'processed' => ['bool', 'default' => 0],
            'occurred_at' => 'datetime',
        ],
        'indexes' => [
            ['columns' => ['attempt_id', 'question_id', 'try']],
            ['columns' => ['occurred_at']],
        ],
    ],
    'attempt_grades' => [
        'columns' => [
            'attempt_id' => 'uint',
            'question_id' => '?uint',
            'grader_id' => '?uint',
            'points_awarded' => 'decimal:8,2',
            'max_points' => 'decimal:8,2',
            'feedback' => '?text',
            'graded_at' => 'datetime',
            // Per-part reporting must be groupable; the roll-up row carries
            // null.
            'part_id' => '?string:64',
            // The mapping sum before clamping must survive to the grader and
            // to a regrade.
            'raw_score' => '?decimal:8,2',
            'min_score' => '?decimal:8,2',
            // Standard 4.19 requires an automated scoring algorithm to be
            // documentable; without these the append-only history proves what
            // was awarded but not why.
            'policy_key' => '?string:40',
            'scoring_params' => '?json',
            'session_status' => ['string:30', 'default' => 'final'],
            'score_status' => ['string:20', 'default' => 'scored'],
            'state' => ['string:24', 'default' => 'incorrect'],
            'answered_status' => ['string:20', 'default' => 'answered'],
            // grade() returns ids; the server resolves them to bodies.
            'feedback_ids' => '?json',
            'version' => ['uint', 'default' => 1],
            // The rubric version a human graded against, when one did. The
            // selected level per criterion lives beside it rather than only
            // the points, because the level is what makes analytic feedback,
            // rater agreement analysis and a safe rubric revision possible.
            'rubric_version_id' => '?uint',
        ],
        'indexes' => [
            ['columns' => ['attempt_id']],
            ['columns' => ['session_status']],
        ],
    ],
    // Advisory rows and nothing else. Window blur and page visibility events
    // have a documented false positive rate against legitimate access tools:
    // separate-window and docked magnifiers, on-screen keyboards, speech
    // recognition correction windows and separate dictionary or read-aloud
    // applications all blur the browser. Nothing in the core may act on one
    // of these rows automatically, the actor is always "system", and
    // recording is suppressed entirely for a learner whose accessibility
    // profile declares input-requirements, magnification, spoken, braille or
    // additional-testing-time.
    //
    // It is a free table because the free build is what records it. The
    // computation over these rows may well be a paid feature; the evidence
    // cannot be, because a paid release cannot retroactively populate rows
    // that were never written, and the route that records one is on the
    // contract and is therefore served by every build.
    'integrity_events' => [
        'columns' => [
            'attempt_id' => 'uint',
            'kind' => 'string:40',
            // A real column defaulting to system rather than an implicit
            // constant, so the row is self describing in an export and an
            // audit reads the fact rather than a document promising it.
            'actor' => ['string:20', 'default' => 'system'],
            'occurred_at' => 'datetime',
            'context' => '?json',
        ],
        'indexes' => [
            ['columns' => ['attempt_id', 'occurred_at']],
            ['columns' => ['kind']],
        ],
    ],

    // ── Assignments ───────────────────────────────────────────────────────
    'assignments' => [
        'columns' => [
            'lesson_id' => 'uint',
            'instructions' => '?mediumtext',
            'max_points' => ['decimal:8,2', 'default' => 100],
            'allowed_file_types' => '?string:255',
            'max_file_size_kb' => '?uint',
            'max_files' => ['uint', 'default' => 1],
            'due_at' => '?datetime',
            'allow_resubmission' => ['bool', 'default' => 0],
            'pass_percentage' => '?decimal:5,2',
            'rubric_json' => '?text',
        ],
        'indexes' => [
            ['columns' => ['lesson_id'], 'unique' => true],
        ],
    ],
    'submissions' => [
        'columns' => [
            'assignment_id' => 'uint',
            'enrolment_id' => 'uint',
            'user_id' => 'uint',
            'attempt_number' => ['uint', 'default' => 1],
            'status' => ['string:20', 'default' => 'draft'],
            'text_response' => '?mediumtext',
            'submitted_at' => '?datetime',
            'graded_at' => '?datetime',
            'grader_id' => '?uint',
            'points_awarded' => '?decimal:8,2',
            'feedback' => '?text',
            'assessment_json' => '?text',
        ],
        'indexes' => [
            ['columns' => ['assignment_id', 'user_id', 'attempt_number'], 'unique' => true],
            ['columns' => ['enrolment_id']],
        ],
    ],
    'submission_files' => [
        'columns' => [
            'submission_id' => 'uint',
            'path' => 'string:1024',
            'filename' => 'string:255',
            'size_bytes' => 'uint',
            'mime' => 'string:100',
        ],
        'indexes' => [
            ['columns' => ['submission_id']],
        ],
    ],

    // ── Access ────────────────────────────────────────────────────────────
    // External shop lifecycle correlation; financial records stay in the shop.
    'shop_access_links' => [
        'columns' => [
            'shop' => 'string:24',
            'object_type' => 'string:20',
            'object_id' => 'string:64',
            'user_id' => 'uint',
            'revision' => ['uint', 'default' => 0],
            'entitlements' => 'json',
            'last_reason' => 'string:100',
        ],
        'indexes' => [
            [
                'columns' => ['shop', 'object_type', 'object_id'],
                'unique' => true,
                'name' => 'shop_access_object_uq',
            ],
        ],
    ],
    'entitlements' => [
        'columns' => [
            'user_id' => 'uint',
            'course_id' => 'uint',
            'source' => 'string:30',
            'source_ref' => '?string:190',
            'status' => ['string:20', 'default' => 'active'],
            'granted_by' => '?uint',
            'starts_at' => '?datetime',
            'expires_at' => '?datetime',
            // The organisation anchor, the membership the grant hangs off and the
            // digest that makes one source reference unique.
            //
            // source_ref_digest is nullable only because a manual grant and an
            // import grant carry no external reference to derive one from, and
            // MySQL and SQLite both treat nulls as distinct in a unique index,
            // which is exactly what those two paths rely on.
            //
            // **On the money path the unique index IS the race guard.** The
            // repository writes the digest inside the insert and GrantEntitlement
            // catches the violation and re-reads, so two concurrent purchases of
            // one order line end with one entitlement rather than two. That was
            // not true when this table was declared: the digest was written by a
            // second statement after the insert on the organisation path alone,
            // so the index protected nothing anywhere else, and this comment used
            // to say so. It is the comment the next migration author reads, so it
            // says what is true now.
            //
            // The organisation path additionally holds OrganisationLearningLock,
            // because a seat assignment has a quantity to check and not only a
            // key to collide on.
            'organisation_id' => '?uint',
            'org_membership_id' => '?uint',
            'source_ref_digest' => '?string:64',
            'revoked_reason' => '?string:60',
            'revoked_ref' => '?string:190',
            // Captured at grant time so the free dispute evidence bundle has
            // one source whoever owns the order. On the CMS-shop path there is
            // no orders row at all and these are the only place those facts
            // can live, and a purchase IP cannot be reconstructed later, which
            // is why they are day-one columns rather than a retrofit.
            //
            // A hash and a derived country rather than the raw address: the
            // hash satisfies the audit purpose, GDPR Article 5(1)(c) prefers
            // it, and a dispute response quotes the derived country. The free
            // build stores no raw purchase IP anywhere.
            'purchase_ip_hash' => '?string:64',
            'purchase_country' => '?string:2',
            'purchased_at' => '?datetime',
        ],
        'indexes' => [
            ['columns' => ['user_id', 'course_id', 'status']],
            ['columns' => ['course_id']],
            ['columns' => ['source', 'source_ref']],
            ['columns' => ['source_ref_digest'], 'unique' => true, 'name' => 'entitlement_source_digest_uq'],
            ['columns' => ['organisation_id', 'status', 'id']],
            ['columns' => ['org_membership_id', 'status']],
        ],
    ],
    'enrolments' => [
        'columns' => [
            'user_id' => 'uint',
            'course_id' => 'uint',
            'entitlement_id' => 'uint',
            'status' => ['string:20', 'default' => 'active'],
            'enrolled_at' => 'datetime',
            'completed_at' => '?datetime',
            'last_activity_at' => '?datetime',
            'progress_percent' => ['decimal:5,2', 'default' => 0],
            // The denormalised pointer at the learner's one active cohort
            // membership, written by Pro and never by the free build. Every
            // progress row already reaches the enrolment, so this one
            // nullable column turns cohort completion rate, cohort average
            // score and "who is behind schedule in cohort X" into indexed
            // predicates rather than a temporal join through membership
            // history. cohort_members stays the historical record.
            'cohort_id' => '?uint',
        ],
        'indexes' => [
            ['columns' => ['user_id', 'course_id'], 'unique' => true],
            ['columns' => ['course_id', 'status']],
            ['columns' => ['cohort_id', 'status']],
        ],
    ],

    // ── Access: availability ──────────────────────────────────────────────
    //
    // One versioned boolean tree per gated row, its leaves opaque to the
    // tree and dispatched to condition providers. Availability is evaluated
    // on read and never materialised: nothing here records that an item
    // unlocked, because a design where a learner's access depends on a job
    // having fired is broken on both hosts (Joomla's lazy scheduler has a
    // 300-second floor and fires from frontend responses; WP-Cron fires on
    // page load or, with DISABLE_WP_CRON and no system cron, never).
    'availability_rules' => [
        'columns' => [
            // course, module, lesson, quiz, assignment.
            'subject_type' => 'string:20',
            'subject_id' => 'uint',
            // content_gating | enrolment_gating. Two of the four surfaces
            // store here, so without the discriminator one course cannot
            // hold both trees: they collide on the unique index and the
            // save-time applicability refusal has nothing to check against.
            // The other two surfaces store in notification_rules.conditions
            // and badges.criteria.
            'surface' => ['string:20', 'default' => 'content_gating'],
            // StoredShape-wrapped boolean tree. JSON because it is recursive
            // and its leaf shapes belong to providers; normalised condition
            // rows would make every new condition type a schema migration.
            'rule' => 'json',
            // Mirrors the tree grammar version for an indexed batch upgrade.
            // Each leaf carries its own payload version, in
            // availability_rule_refs, because one column cannot carry many.
            'rule_version' => ['uint', 'default' => 1],
            // shown | hidden. Real because the outline query reads it for
            // every gated row. Shown with the reason is the default, because
            // an invisible restriction is a support ticket.
            'default_visibility' => ['string:10', 'default' => 'shown'],
            'updated_by' => '?uint',
        ],
        'indexes' => [
            [
                'columns' => ['subject_type', 'subject_id', 'surface'],
                'unique' => true,
                'name' => 'availability_rules_subject_uq',
            ],
            ['columns' => ['rule_version']],
        ],
    ],
    'availability_rule_refs' => [
        'columns' => [
            // availability_rule | notification_rule | badge | coupon |
            // automation_rule. The fifth owner is a value and not a
            // migration: the column is already string:20 and already
            // indexed on (owner_type, owner_id), which is what makes
            // "every rule that references lesson 88" an indexed query
            // across all five owners and what makes a course copy remap
            // an automation's references through the identical save-time
            // pass.
            // Polymorphic in the owner because the same grammar is stored in
            // four places now, and a table keyed to the first alone leaves a
            // badge criterion or a notification condition naming a lesson id
            // unremapped by a course copy and invisible to a batched payload
            // upgrade. `coupon` is the fifth surface's owner: a coupon's tree
            // stores on the coupon row rather than in availability_rules,
            // because that table is unique on (subject_type, subject_id,
            // surface) and a coupon has no subject row to hang from.
            'owner_type' => ['string:20', 'default' => 'availability_rule'],
            'owner_id' => 'uint',
            'surface' => ['string:20', 'default' => 'content_gating'],
            // "c.0.c.2": the leaf's position in the tree.
            'leaf_path' => 'string:100',
            'type' => 'string:40',
            'payload_version' => ['uint', 'default' => 1],
            // lesson, module, quiz, assignment, course, cohort, group, badge,
            // product, plan. The last two are what a coupon's product-scope
            // leaf names, and they are here rather than folded into `course`
            // because a product is what is sold and a course is what it
            // grants: the same course is legitimately in several products.
            'ref_type' => '?string:20',
            'ref_id' => '?uint',
        ],
        'indexes' => [
            ['columns' => ['owner_type', 'owner_id']],
            ['columns' => ['type', 'payload_version']],
            ['columns' => ['ref_type', 'ref_id']],
        ],
        'timestamps' => false,
    ],
    'availability_grants' => [
        'columns' => [
            // course, cohort, group, site.
            'scope_type' => 'string:20',
            'scope_id' => 'uint',
            'subject_type' => 'string:20',
            'subject_id' => 'uint',
            'user_id' => 'uint',
            // grant opens a blocked item; deny closes an available one. A
            // deny with no expires_at yields NEVER and therefore removes the
            // item from that learner's completion denominator, which is what
            // excusing a learner means and what the confirmation dialog says.
            'mode' => ['string:10', 'default' => 'grant'],
            'reason' => '?string:255',
            'granted_by' => 'uint',
            // A read-time predicate, never swept state, exactly as expiry is
            // on a certificate: an expired grant stops applying at the
            // instant it expires rather than when a job next runs.
            'expires_at' => '?datetime',
        ],
        'indexes' => [
            [
                'columns' => ['scope_type', 'scope_id', 'subject_type', 'subject_id', 'user_id'],
                'unique' => true,
                'name' => 'availability_grants_uq',
            ],
            ['columns' => ['user_id', 'subject_type', 'subject_id']],
            ['columns' => ['expires_at']],
        ],
    ],

    // ── Progress ──────────────────────────────────────────────────────────
    'lesson_progress' => [
        'columns' => [
            'enrolment_id' => 'uint',
            'lesson_id' => 'uint',
            'user_id' => 'uint',
            'status' => ['string:20', 'default' => 'not_started'],
            'progress_percent' => ['decimal:5,2', 'default' => 0],
            'seconds_spent' => ['uint', 'default' => 0],
            'position_seconds' => '?uint',
            'first_accessed_at' => '?datetime',
            'completed_at' => '?datetime',
            'last_heartbeat_at' => '?datetime',
        ],
        'indexes' => [
            ['columns' => ['enrolment_id', 'lesson_id'], 'unique' => true],
            ['columns' => ['user_id']],
            ['columns' => ['lesson_id']],
            // The keyset walk's own pair. pageByKeyset orders by (enrolment_id,
            // id), and the unique index above leads on enrolment_id but then on
            // lesson_id, so it cannot serve that order and every page sorted.
            // The activity log carries the same shape for the same reason.
            ['columns' => ['enrolment_id', 'id']],
        ],
    ],
    'course_progress' => [
        'columns' => [
            'enrolment_id' => 'uint',
            'lessons_total' => ['uint', 'default' => 0],
            'lessons_completed' => ['uint', 'default' => 0],
            'percent' => ['decimal:5,2', 'default' => 0],
            'quiz_average' => '?decimal:5,2',
            'final_quiz_passed' => '?bool',
            'computed_at' => 'datetime',
        ],
        'indexes' => [
            ['columns' => ['enrolment_id'], 'unique' => true],
        ],
    ],
    'activity_log' => [
        'columns' => [
            'user_id' => 'uint',
            'course_id' => '?uint',
            'lesson_id' => '?uint',
            'enrolment_id' => '?uint',
            'action' => 'string:50',
            'context' => '?json',
            'occurred_at' => 'datetime',
            // The originating event's idempotency key: at-least-once event
            // redelivery must not duplicate timeline rows. Null for entries
            // that do not come from a domain event.
            'event_ref' => '?string:190',
        ],
        'indexes' => [
            ['columns' => ['event_ref'], 'unique' => true, 'name' => 'activity_log_event_uq'],
            ['columns' => ['user_id', 'occurred_at']],
            ['columns' => ['course_id']],
            // The keyset sort key, and the reason it is a pair. This table is
            // written from fifteen-second heartbeats, so an offset-paged
            // export of it WILL duplicate and skip rows while it runs: offset
            // makes the engine build and discard every skipped row and lets
            // rows shift between pages under concurrent inserts. A stable
            // cursor needs the sort key to be indexed and unique, and
            // occurred_at alone is neither.
            ['columns' => ['occurred_at', 'id']],
        ],
    ],
    'video_positions' => [
        'columns' => [
            'user_id' => 'uint',
            'lesson_id' => 'uint',
            'position_seconds' => ['uint', 'default' => 0],
            'duration_seconds' => '?uint',
        ],
        'indexes' => [
            ['columns' => ['user_id', 'lesson_id'], 'unique' => true],
        ],
    ],

    // ── Credentials ───────────────────────────────────────────────────────
    //
    // A template is a three-level aggregate. The template row owns what does
    // not depend on paper, a page owns paper, an element owns one drawn
    // thing. The split between the last two is the load-bearing one: layout
    // and ordering are host columns because the drag-and-drop editor and the
    // layout service own them, while font, size, colour and every
    // element-specific setting live inside the StoredShape-wrapped "data",
    // because they belong to the element provider and a shared column for
    // them would make every new element a migration.
    'certificate_templates' => [
        'columns' => [
            'name' => 'string:255',
            // A stable identifier for a shipped template, distinct from the
            // editable name, so a package can still find the template it
            // seeded after somebody has renamed it. Nullable because a
            // template an author created by hand has no shipped identity.
            'template_key' => '?string:100',
            'renderer' => ['string:30', 'default' => 'html'],
            // The explicit discriminator, so the renderer never guesses and
            // the seeded free row needs no migration: "html" is a
            // token-driven body, "elements" walks the pages through the
            // layout service. Both are free.
            'format' => ['string:20', 'default' => 'html'],
            // A closed enumeration of thirty Open Badges 3.0 values the
            // credential builder reads and the admin filters by, which is why
            // it is a column rather than a key inside metadata.
            'achievement_type' => ['string:50', 'default' => 'CertificateOfCompletion'],
            'body' => 'mediumtext',
            'is_default' => ['bool', 'default' => 0],
            'state' => ['string:20', 'default' => 'published'],
            'locale' => '?string:20',
            // Supersession and bulk reissue key on the revision: the fan-out
            // selects rows below the target and skips what it has already
            // written, which is what makes a restart idempotent.
            'revision' => ['uint', 'default' => 1],
            // Mirrors the page and element grammar version, for an indexed
            // batch upgrade. Each element payload carries its own version in
            // certificate_template_elements.data_version, because one column
            // cannot express many elements at many versions.
            'schema_version' => ['uint', 'default' => 1],
            // never | fixed | days_after_issue, which is Moodle's badge
            // expiry vocabulary. Expiry is a plain data property of the
            // credential (VC 2.0 validUntil) and never a status lookup.
            'expiry_mode' => ['string:20', 'default' => 'never'],
            'expiry_days' => '?uint',
            'expiry_at' => '?datetime',
            // Set on the first issuance, freezing criteria and expiry.
            // Without the lock the same credential means different things to
            // different holders and every audit question is unanswerable.
            'locked_at' => '?datetime',
            'published_snapshot' => '?json',
            'published_summary' => '?json',
            'published_name' => '?string:255',
            'published_revision' => '?uint',
            'has_unpublished_changes' => ['bool', 'default' => 1],
            'published_at' => '?datetime',
            'published_by' => '?uint',
            'active_default_slot' => '?string:10',
            'category' => '?string:100',
            'provenance' => '?json',
            'render_provenance' => '?json',
        ],
        'indexes' => [
            ['columns' => ['is_default']],
            ['columns' => ['template_key'], 'unique' => true],
            ['columns' => ['state']],
            ['columns' => ['schema_version']],
            ['columns' => ['active_default_slot'], 'unique' => true],
            ['columns' => ['category']],
        ],
    ],
    'course_certificate_policies' => [
        'columns' => [
            'course_id' => 'uint',
            'revision' => ['uint', 'default' => 1],
            'enabled' => ['bool', 'default' => 1],
            'template_id' => '?uint',
            'issue_rule' => ['string:30', 'default' => 'course_completed'],
            'expiry' => 'json',
            'co_branding' => ['string:20', 'default' => 'site'],
            'updated_by' => 'uint',
        ],
        'indexes' => [
            ['columns' => ['course_id'], 'unique' => true],
            ['columns' => ['template_id']],
        ],
    ],
    'certificate_template_pages' => [
        'columns' => [
            'template_id' => 'uint',
            'sequence' => ['uint', 'default' => 1],
            // Millimetres, which is what the renderers and every paper
            // standard agree on. A4 landscape by default.
            'width' => ['decimal:7,2', 'default' => 297],
            'height' => ['decimal:7,2', 'default' => 210],
            'orientation' => ['string:10', 'default' => 'landscape'],
            'margin_top' => ['uint', 'default' => 10],
            'margin_right' => ['uint', 'default' => 10],
            'margin_bottom' => ['uint', 'default' => 10],
            'margin_left' => ['uint', 'default' => 10],
            'background' => '?string:1024',
        ],
        'indexes' => [
            ['columns' => ['template_id', 'sequence']],
        ],
    ],
    'certificate_template_elements' => [
        'columns' => [
            'page_id' => 'uint',
            // Draw order, which is what puts a border behind a name rather
            // than over it.
            'sequence' => ['uint', 'default' => 1],
            'element' => 'string:50',
            'name' => '?string:255',
            'data' => '?json',
            'data_version' => ['uint', 'default' => 1],
            'posx' => ['uint', 'default' => 0],
            'posy' => ['uint', 'default' => 0],
            'width' => '?uint',
            'height' => '?uint',
            'refpoint' => ['string:20', 'default' => 'top_left'],
            'alignment' => ['string:10', 'default' => 'left'],
        ],
        'indexes' => [
            ['columns' => ['page_id', 'sequence']],
            // "Which templates use the QR element" has to be answerable
            // before a package is removed, not after.
            ['columns' => ['element']],
            ['columns' => ['data_version']],
        ],
    ],
    'certificates' => [
        'columns' => [
            'user_id' => 'uint',
            'course_id' => 'uint',
            'enrolment_id' => 'uint',
            'template_id' => 'uint',
            'template_revision' => '?uint',
            // The frozen resolved template, its pages and its elements, and
            // the frozen resolved token values. Rendering reads these and
            // never the live template, so correcting a template cannot
            // rewrite a certificate somebody already holds. Nullable only for
            // rows issued before the snapshot existed, which fall back to the
            // live template and report that they did.
            'template_snapshot' => '?json',
            'context_snapshot' => '?json',
            'renderer_key' => ['string:30', 'default' => 'html'],
            'engine_version' => '?string:40',
            'code' => 'string:64',
            // The credential id, separate from the human code: the code is
            // printed and retyped, the uuid is embedded and dereferenced.
            'uuid' => '?string:64',
            'issued_at' => 'datetime',
            // Populated from CourseCompleted. A manual reissue makes it
            // diverge from issued_at, and the public card labels its date
            // "Completed", so presenting one fact under another's label on an
            // evidentiary page is not defensible.
            'completed_at' => '?datetime',
            'valid_from' => '?datetime',
            'expires_at' => '?datetime',
            // active | suspended | revoked | superseded. "expired" and
            // "not_yet_valid" are derived from the clock and never stored, so
            // a clock change cannot leave rows stale. Where a stored and a
            // derived status both apply the precedence is published: revoked,
            // superseded, suspended, not_yet_valid, expired, active.
            'status' => ['string:20', 'default' => 'active'],
            // The exactly-once issuance guard, moved off enrolment_id. It
            // holds the enrolment id while the row is active, suspended or
            // not yet valid, and NULL once the row is superseded or revoked,
            // under a unique index. A unique enrolment_id makes supersession
            // structurally impossible, and dropping it without a replacement
            // loses the guard that makes a replayed CourseCompleted return
            // the existing row instead of inserting a second one. Every
            // target engine permits multiple NULLs in a unique index and none
            // supports a portable partial index, which is the same argument
            // cohort_members.active_key already carries.
            'active_enrolment_key' => '?string:64',
            'revoked_at' => '?datetime',
            'revocation_reason' => '?string:190',
            'superseded_by_id' => '?uint',
            'supersedes_id' => '?uint',
            // The per-certificate publication gate the verification page
            // reads, defaulting to the site setting at issue.
            'published' => ['bool', 'default' => 0],
            // Renamed from pdf_path in 1.7.0: the free build stores HTML
            // here, and the column was never PDF-specific.
            'artefact_path' => '?string:1024',
            'artefact_checksum' => '?string:64',
            'rendered_at' => '?datetime',
            'credential_json' => '?mediumtext',
            'credential_format' => '?string:50',
            // Null on an unsigned format, which is what the free build
            // serves. The three columns exist from day one so that adding a
            // signed format is a registration and not a migration.
            'proof_type' => '?string:50',
            // sha256$<hex> over the identifier plus the salt, so a publicly
            // shareable credential proves it was issued to the holder of an
            // address without publishing the address. The salt is per row and
            // is carried forward by a reissue, because regenerating it
            // changes the hash and any relying party that pinned the old one
            // can no longer match the holder.
            'recipient_identity_hash' => '?string:100',
            'recipient_identity_salt' => '?string:64',
            'event_ref' => '?string:190',
            // The caller-owned idempotency key, from Credly's issuer earner
            // id. Unique where it is not null, because an idempotency key
            // with no unique index is only a lookup: two concurrent imports
            // carrying the same identifier would both find nothing and both
            // insert.
            'issuer_earner_id' => '?string:190',
            'metadata' => '?json',
        ],
        'indexes' => [
            ['columns' => ['code'], 'unique' => true],
            ['columns' => ['uuid'], 'unique' => true],
            ['columns' => ['active_enrolment_key'], 'unique' => true],
            ['columns' => ['issuer_earner_id'], 'unique' => true],
            // Plain, not unique: the history accessor asks which certificates
            // an enrolment has ever held, and the answer is many.
            ['columns' => ['enrolment_id']],
            ['columns' => ['user_id']],
            ['columns' => ['course_id']],
            ['columns' => ['status']],
            // The bulk reissue selection predicate reads template, status and
            // revision together.
            ['columns' => ['template_id', 'status']],
        ],
    ],
    // Cross-scheme facts are real columns and scheme-specific ones are not,
    // because compliance reporting groups by scheme and reporting date while
    // only the scheme provider interprets its own parameters. amount is
    // decimal and never float, because it is reported to a regulator.
    // contact_minutes is stored beside amount because the minutes are the
    // shared fact and the credit amounts are derived per scheme.
    'certificate_credits' => [
        'columns' => [
            'certificate_id' => 'uint',
            'scheme' => 'string:40',
            'amount' => 'decimal:8,2',
            'unit' => 'string:20',
            'field_of_study' => '?string:190',
            'awarding_body_id' => '?string:100',
            'contact_minutes' => '?uint',
            'params' => '?json',
            'params_version' => ['uint', 'default' => 1],
            'reported_at' => '?datetime',
        ],
        'indexes' => [
            ['columns' => ['certificate_id']],
            ['columns' => ['scheme', 'reported_at']],
        ],
    ],
    // The authored source those rows are written from, at issue, inside the
    // certificate's own transaction: a credit row without its certificate is
    // a compliance defect under retention regimes that run to five and seven
    // years. Without this table the free contact_hours scheme, the free
    // credit_statement element and the free {credit.*} tokens all read an
    // empty table.
    'course_credits' => [
        'columns' => [
            'course_id' => 'uint',
            'scheme' => 'string:40',
            'params' => '?json',
            'params_version' => ['uint', 'default' => 1],
            'created_by' => '?uint',
        ],
        'indexes' => [
            ['columns' => ['course_id', 'scheme'], 'unique' => true, 'name' => 'course_credits_uq'],
            ['columns' => ['scheme']],
        ],
    ],
    // The certifying board ID or partial date of birth a scheme's
    // requiredLearnerFields() names, so adding a scheme never touches the
    // user model and the values it needs have somewhere to live.
    'credit_scheme_identities' => [
        'columns' => [
            'user_id' => 'uint',
            'scheme' => 'string:40',
            'field' => 'string:60',
            'value' => 'string:190',
        ],
        'indexes' => [
            [
                'columns' => ['user_id', 'scheme', 'field'],
                'unique' => true,
                'name' => 'credit_scheme_identities_uq',
            ],
        ],
    ],
    // A child table and not two columns on the credential row. One
    // status_list_id and one status_list_index carry one statusPurpose, while
    // revocation and suspension are independent axes on purpose: a
    // plagiarism investigation suspending a certificate whose only index came
    // from the revocation list makes every verifier report a permanent and
    // defamatory answer to a reversible hold, and allocated from the
    // suspension list instead, a later revocation has nowhere to be recorded.
    // It serves certificates and badge_awards alike, which also closes the
    // hole where both allocate from one counter and nothing stops a badge
    // award and a certificate sharing a bit.
    'credential_status_entries' => [
        'columns' => [
            'subject_type' => 'string:20',
            'subject_id' => 'uint',
            'purpose' => 'string:20',
            'list_id' => 'uint',
            'list_index' => 'uint',
        ],
        'indexes' => [
            [
                'columns' => ['list_id', 'list_index'],
                'unique' => true,
                'name' => 'credential_status_entries_slot_uq',
            ],
            [
                'columns' => ['subject_type', 'subject_id', 'purpose'],
                'unique' => true,
                'name' => 'credential_status_entries_subject_uq',
            ],
        ],
    ],
    'credential_status_lists' => [
        'columns' => [
            'purpose' => ['string:20', 'default' => 'revocation'],
            'list_uuid' => 'string:64',
            // The specification's 16KB uncompressed minimum, 131072
            // single-bit entries, from the very first row and never sized to
            // the number of credentials issued: the minimum exists for herd
            // privacy, and a list sized to the real count leaks the issuer's
            // volume.
            'size' => ['uint', 'default' => 131072],
            // Taken by one conditional increment and never a read followed by
            // a write: UPDATE ... SET next_index = next_index + 1 WHERE id =
            // ? AND next_index < size, with zero affected rows meaning the
            // list is full and the allocator rolls to a new list row for that
            // purpose. That form works on MySQL 5.7, MariaDB and SQLite.
            'next_index' => ['uint', 'default' => 0],
            // Nullable because the free build allocates indexes without
            // publishing the list; publication is paid work.
            'encoded_list' => '?mediumtext',
            'ttl_ms' => ['uint', 'default' => 300000],
            // What gives the republication debounce a ceiling. Scheduling the
            // publish at now + delay under a dedupe key would let 200
            // revocations an hour apart push the pending task forward every
            // time, so every wallet reads all 200 as valid for the whole
            // hour.
            'dirty_since' => '?datetime',
            'published_at' => '?datetime',
        ],
        'indexes' => [
            ['columns' => ['list_uuid'], 'unique' => true],
            ['columns' => ['purpose']],
            ['columns' => ['dirty_since']],
        ],
    ],

    // ── Communication ─────────────────────────────────────────────────────
    'email_log' => [
        'columns' => [
            'user_id' => '?uint',
            'template_key' => 'string:100',
            'recipient' => 'string:320',
            'status' => ['string:20', 'default' => 'queued'],
            'error' => '?text',
            'sent_at' => '?datetime',
            'event_ref' => '?string:190',
            // SHA-256 of event_ref|template_key|recipient. A direct unique
            // index over those three columns would exceed the 767-byte key
            // limit of legacy MySQL and MariaDB row formats under utf8mb4.
            'dedupe_key' => '?string:64',
            // This table stopped being the enqueue point when
            // notification_queue took it, and became the per-attempt
            // delivery record. None of the digest's three components varies
            // between attempts, so under a per-message unique index a retry
            // after a transient SMTP failure either threw inside the send
            // path after the mail had already gone out or silently upserted
            // and lost the evidence that a retry happened, which is the one
            // thing a per-attempt record exists to hold.
            'attempt' => ['uint', 'default' => 1],
        ],
        'indexes' => [
            [
                'columns' => ['dedupe_key', 'attempt'],
                'unique' => true,
                'name' => 'email_log_dedupe_attempt_uq',
            ],
            ['columns' => ['dedupe_key']],
            ['columns' => ['event_ref']],
            ['columns' => ['user_id']],
        ],
    ],
    'notification_rules' => [
        'columns' => [
            'rule_key' => 'string:100',
            'trigger_key' => 'string:100',
            // The trigger provider's own shape.
            'definition' => '?json',
            'definition_version' => ['uint', 'default' => 1],
            // The availability tree again, on the notification_rule surface,
            // re-evaluated at send and not only at enqueue: a seven-day nudge
            // conditioned on "has not completed" must not mail a learner who
            // finished on day two.
            'conditions' => '?json',
            'conditions_version' => ['uint', 'default' => 1],
            'audience_key' => 'string:100',
            'audience_config' => '?json',
            'audience_config_version' => ['uint', 'default' => 1],
            'category_key' => 'string:50',
            'template_key' => 'string:100',
            // immediate | absolute | relative | recurring_window. A closed
            // core enum rather than a registry, so the payload belongs to
            // the enum and not to any provider.
            'schedule_kind' => ['string:20', 'default' => 'immediate'],
            // Real because "which rules break if this anchor's package is
            // removed" is a query, and the admin filters on schedule_kind.
            'anchor_key' => '?string:60',
            // The kind's payload, StoredShape-wrapped. Three flat columns
            // can store one of the four kinds: absolute has nowhere to put
            // "at", recurring_window nowhere for its bounds, and relative
            // nowhere for offset_unit.
            'schedule' => '?json',
            'schedule_version' => ['uint', 'default' => 1],
            // Other rule keys this one silences for the same subject within
            // a window. Without cross-rule suppression a site that enables
            // five sensible rules mails a learner five times on completion
            // day.
            'suppresses' => '?json',
            'course_id' => '?uint',
            'cohort_id' => '?uint',
            // Disabled by default, which puts the nudge research in the
            // schema rather than in a comment: light-touch nudges are small,
            // context dependent and sometimes negative.
            'state' => ['string:20', 'default' => 'disabled'],
            'queued_count' => ['uint', 'default' => 0],
            'sent_count' => ['uint', 'default' => 0],
            'suppressed_count' => ['uint', 'default' => 0],
            'created_by' => '?uint',
        ],
        'indexes' => [
            ['columns' => ['rule_key'], 'unique' => true],
            ['columns' => ['trigger_key', 'state']],
            ['columns' => ['category_key']],
            ['columns' => ['anchor_key']],
            ['columns' => ['course_id']],
            ['columns' => ['cohort_id']],
        ],
    ],
    // A child table rather than a delimited column, because "which rules use
    // sms" and "which rules break if the webhook package is removed" are
    // asked from outside the rule and a string:190 answers them with a LIKE
    // scan.
    'notification_rule_channels' => [
        'columns' => [
            'rule_id' => 'uint',
            'channel_key' => 'string:50',
        ],
        'indexes' => [
            [
                'columns' => ['rule_id', 'channel_key'],
                'unique' => true,
                'name' => 'notification_rule_channels_uq',
            ],
            ['columns' => ['channel_key']],
        ],
        'timestamps' => false,
    ],
    // The transactional outbox. The intent row is inserted by the use case
    // that changes the state, inside DatabasePort::transactional(), never
    // from an event listener: EventBus wraps every listener in try/catch and
    // logs, so an outbox written from a listener commits the business state
    // and loses the row. Delivery is at-least-once with an idempotent
    // consumer; the exactly-once effect comes from the dedupe digest and the
    // claim, not from the transport.
    'notification_queue' => [
        'columns' => [
            'rule_id' => '?uint',
            'trigger_key' => 'string:100',
            'category_key' => 'string:50',
            // Derived at enqueue from the trigger provider that owns the
            // template and stamped here for the audit trail. It is not a
            // column on notification_rules, because an editable class is a
            // one-field bypass of consent, of the RFC 8058 headers and of
            // the Article 21(3) refusal.
            'class' => ['string:20', 'default' => 'transactional'],
            // Real columns because three behaviours filter on the subject
            // (the duplicate window, cross-rule suppression and the
            // send-time re-resolution) and event_ref is an opaque identity
            // no policy may parse.
            'subject_type' => '?string:20',
            'subject_id' => '?uint',
            // Null only on the pending_fanout intent row the business
            // transaction writes; audience resolution and policy evaluation
            // belong to the binned fan-out task.
            'recipient_user_id' => '?uint',
            'channel_key' => '?string:50',
            'template_key' => 'string:100',
            'parameters' => '?json',
            'event_ref' => 'string:190',
            // The sweep's periodBucket, the recurring window's occurrence
            // ordinal, the digest run's period bucket, or the literal
            // "once". Mandatory: without it the other six digest components
            // are identical across every occurrence of a repeating schedule,
            // so a window bounded at eleven occurrences enqueues one and
            // silently drops ten, and a terminal row would occupy the unique
            // space for that recipient and that message for the life of the
            // site.
            'occurrence_key' => 'string:64',
            // SHA-256 over rule_id|trigger_key|event_ref|template_key|
            // channel_key|recipient_user_id|occurrence_key, unique on the
            // digest alone for the reason email_log already states.
            'dedupe_key' => 'string:64',
            'run_at' => 'datetime',
            // Stamped at enqueue and defaulted to the site timezone, never
            // to the literal UTC: a UTC 08:00 to 21:00 quiet-hours window is
            // 03:00 to 16:00 US Eastern, and a literal default would breach
            // the statute no rule may defeat. A later timezone change cannot
            // then resurrect a past send.
            'recipient_timezone' => '?string:64',
            // Stamped at send, so a render is reproducible after the fact.
            'recipient_locale' => '?string:20',
            // pending_fanout, pending, sending, sent, deferred, suppressed,
            // failed_permanent, cancelled. An unenumerated state is a
            // stalled queue.
            'state' => ['string:20', 'default' => 'pending'],
            // The claim. Both hosts are documented multi-firers, so two
            // runners over one pending row is the normal case, and a dedupe
            // digest deduplicates rows rather than sends.
            'claim_token' => '?string:64',
            'claimed_at' => '?datetime',
            'lease_expires_at' => '?datetime',
            'attempts' => ['uint', 'default' => 0],
            'next_attempt_at' => '?datetime',
            'provider_message_id' => '?string:190',
            'recipient_address_digest' => '?string:64',
            'last_error' => '?text',
            // Real, because the per-rule counters screen groups by it, and
            // counting suppressions without their reason cannot tell a site
            // whether a rule is throttled by the frequency cap, blocked by
            // consent or silenced by a competing rule.
            'suppressed_reason' => '?string:100',
            'sent_at' => '?datetime',
        ],
        'indexes' => [
            ['columns' => ['dedupe_key'], 'unique' => true, 'name' => 'notification_queue_dedupe_uq'],
            ['columns' => ['state', 'run_at']],
            ['columns' => ['state', 'lease_expires_at']],
            [
                'columns' => ['trigger_key', 'subject_type', 'subject_id', 'recipient_user_id', 'state'],
                'name' => 'notification_queue_subject_idx',
            ],
            ['columns' => ['recipient_user_id', 'sent_at']],
            ['columns' => ['provider_message_id']],
            ['columns' => ['rule_id', 'suppressed_reason']],
        ],
    ],
    // Its own table rather than a queue state, because the collapse rule
    // reads and rewrites many rows into one and needs an index shape the
    // send path does not share; folding it into the queue would dirty the
    // (state, run_at) index every send depends on.
    'notification_digest_items' => [
        'columns' => [
            'recipient_user_id' => 'uint',
            'channel_key' => 'string:50',
            'category_key' => 'string:50',
            'frequency' => 'string:20',
            'trigger_key' => 'string:100',
            'subject_type' => '?string:20',
            'subject_id' => '?uint',
            'parameters' => '?json',
            // Gives the digest path the same exactly-once property the queue
            // has: computed on the queue digest's inputs plus the frequency,
            // because a replayed event with no subject would otherwise write
            // two identical items and nothing would notice.
            'dedupe_key' => 'string:64',
            'occurred_at' => 'datetime',
            // Claimed by a conditional update before the queue row is
            // composed, so an item arriving mid-collapse falls into
            // tomorrow's batch instead of being stamped into a message that
            // does not mention it.
            'collapse_batch' => '?string:64',
            'collapsed_into' => '?uint',
            // A terminal state for a dropped item, so a learner who switches
            // from daily to never does not leave rows rescanned every night
            // forever.
            'dropped_at' => '?datetime',
            'dropped_reason' => '?string:100',
        ],
        'indexes' => [
            [
                'columns' => ['dedupe_key'],
                'unique' => true,
                'name' => 'notification_digest_items_dedupe_uq',
            ],
            [
                'columns' => ['recipient_user_id', 'channel_key', 'frequency', 'collapsed_into'],
                'name' => 'notification_digest_items_pending_idx',
            ],
            ['columns' => ['collapse_batch']],
            ['columns' => ['occurred_at']],
        ],
    ],
    // User preference and site policy are different aggregates with
    // different lifecycles and different legal weight, and conflating them
    // is why the three admin states are so often misread. There is
    // deliberately no notification_categories table: categories are declared
    // by trigger providers, so a row could otherwise name a category no
    // provider owns.
    'notification_preferences' => [
        'columns' => [
            'user_id' => 'uint',
            'category_key' => 'string:50',
            'channel_key' => 'string:50',
            // immediate | daily | weekly | never.
            'frequency' => ['string:20', 'default' => 'immediate'],
        ],
        'indexes' => [
            [
                'columns' => ['user_id', 'category_key', 'channel_key'],
                'unique' => true,
                'name' => 'notification_prefs_uq',
            ],
            ['columns' => ['category_key', 'channel_key']],
        ],
    ],
    'notification_policies' => [
        'columns' => [
            'category_key' => 'string:50',
            'channel_key' => 'string:50',
            // disallowed | permitted | forced. A forced state is refused at
            // save for an Optional or Marketing category: GDPR Article 21(3)
            // admits no balancing on an objection to direct marketing.
            'state' => ['string:20', 'default' => 'permitted'],
            'default_frequency' => ['string:20', 'default' => 'immediate'],
        ],
        'indexes' => [
            [
                'columns' => ['category_key', 'channel_key'],
                'unique' => true,
                'name' => 'notification_policies_uq',
            ],
        ],
    ],
    // Append-only, never updated and never deleted: GDPR Article 7(1) puts
    // the burden of proof on the controller, and a deleted row is
    // indistinguishable both from never having consented and from never
    // having objected. The second matters, because an Article 21 objection
    // is permanent.
    'notification_consents' => [
        'columns' => [
            'user_id' => 'uint',
            'category_key' => 'string:50',
            // granted | withdrawn | objected.
            'state' => 'string:20',
            // consent | soft_opt_in | contract | legitimate_interests. A
            // real column because that is the question an audit asks, and
            // because the position that the soft opt-in never covers
            // bought-in, rented or broker-supplied lists is only enforceable
            // if the basis is stored per row.
            'basis' => ['string:30', 'default' => 'consent'],
            'source' => 'string:50',
            'evidence' => '?json',
            'evidence_version' => ['uint', 'default' => 1],
            'actor_user_id' => '?uint',
            'ip' => '?string:45',
            'occurred_at' => 'datetime',
        ],
        'indexes' => [
            ['columns' => ['user_id', 'category_key', 'occurred_at']],
            ['columns' => ['category_key', 'state']],
            ['columns' => ['basis']],
        ],
    ],
    // A deliverability fact about an address, binding every message class
    // including the ones a site must send. There is deliberately no
    // unsubscribed_all reason: a category opt-out writes a preference row
    // and a consent row and never lands here, or the next certificate mail
    // would be silently suppressed.
    'notification_suppressions' => [
        'columns' => [
            'channel_key' => 'string:50',
            // A SHA-256 digest, never the address, so the suppression list
            // is not a second plaintext copy of the mailing list. Per
            // address rather than per user, because one person's second
            // address is a different deliverability fact.
            'address_digest' => 'string:64',
            // hard_bounce | complaint | manual.
            'reason' => 'string:30',
            'soft_bounce_count' => ['uint', 'default' => 0],
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'released_at' => '?datetime',
            'diagnostic' => '?text',
        ],
        'indexes' => [
            [
                'columns' => ['channel_key', 'address_digest'],
                'unique' => true,
                'name' => 'notification_suppressions_uq',
            ],
            ['columns' => ['reason']],
        ],
    ],
    // Four separate part columns rather than one JSON body, because
    // multipart/alternative ordering is a per-part decision and a channel
    // asks for the parts it can render through a capability interface; a
    // JSON body would mean decoding a document to answer "does this template
    // have a short form".
    'notification_templates' => [
        'columns' => [
            'template_key' => 'string:100',
            'locale' => ['string:20', 'default' => 'en-GB'],
            'channel_key' => ['string:50', 'default' => 'email'],
            'subject' => '?string:255',
            'body_text' => '?mediumtext',
            'body_html' => '?mediumtext',
            'body_short' => '?string:500',
            'body_version' => ['uint', 'default' => 1],
            'state' => ['string:20', 'default' => 'published'],
            // Structured authoring is kept beside the compiled live parts.
            // A published row may carry a draft without changing what send
            // resolution sees; a draft-only translation starts at version 0.
            'published_source' => '?json',
            'draft_source' => '?json',
            // Never reset this counter on discard or publish: a revision that
            // could repeat would make an old editor tab current again (ABA).
            'draft_revision' => ['uint', 'default' => 0],
            'draft_base_version' => '?uint',
            // user | system. Kept as provenance rather than inferred from a
            // nullable actor because a system operation may still name one.
            'draft_origin' => '?string:20',
            'draft_updated_by' => '?uint',
            'draft_updated_at' => '?datetime',
            'published_by' => '?uint',
            'published_at' => '?datetime',
            'blueprint_version' => ['uint', 'default' => 1],
        ],
        'indexes' => [
            [
                'columns' => ['template_key', 'locale', 'channel_key'],
                'unique' => true,
                'name' => 'notification_templates_uq',
            ],
        ],
    ],
    // The channel_confirmation policy's store. Without it nothing held a
    // channel address or a confirmation state, so the policy would either
    // deny every non-exempt message on every site or deny nothing, and
    // neither is testable. The address is a digest for the same reason the
    // suppression list is.
    'notification_channel_addresses' => [
        'columns' => [
            'user_id' => 'uint',
            'channel_key' => 'string:50',
            'address_digest' => 'string:64',
            'confirmation_sent_at' => '?datetime',
            'confirmed_at' => '?datetime',
        ],
        'indexes' => [
            [
                'columns' => ['user_id', 'channel_key'],
                'unique' => true,
                'name' => 'notification_channel_addresses_uq',
            ],
            ['columns' => ['address_digest']],
        ],
    ],

    // ── Platform ──────────────────────────────────────────────────────────
    'settings' => [
        'columns' => [
            'name' => 'string:190',
            'value' => '?json',
        ],
        'indexes' => [
            ['columns' => ['name'], 'unique' => true],
        ],
    ],
    // Provider configuration, for every registry, at any scope. One table so
    // that configuring a provider is a row rather than a migration: a policy
    // needing a dedicated column would make the ninth policy a schema change,
    // which is the failure the registry exists to prevent, and it privileges
    // whichever providers shipped first. quiet_hours_mode and one_time_only
    // are therefore rule-scoped rows here rather than columns on
    // notification_rules. A settings row could not carry this, because a
    // settings row cannot be scoped to a course, a cohort or a rule.
    'provider_settings' => [
        'columns' => [
            // AvailabilityCondition, NotificationPolicy, Notification, ...
            'registry_key' => 'string:60',
            'provider_key' => 'string:100',
            // site | course | cohort | rule | organisation | placement.
            // Learning, organisation, AI and automation configuration scopes.
            'scope_type' => ['string:20', 'default' => 'site'],
            'scope_id' => ['uint', 'default' => 0],
            // JSON because only the owning provider interprets it, with the
            // version mirrored because a provider shape without a version is
            // a shape nobody can upgrade.
            'params' => '?json',
            'params_version' => ['uint', 'default' => 1],
            'updated_by' => '?uint',
            // A pointer resolved through SecretsPort (env:NAME, store:KEY) and
            // never a value. provider_settings is a readable table on a
            // shared-hosting install, and a leaked webhook signing secret lets
            // an attacker forge entitlement grants, so the column that would
            // otherwise hold one holds a reference to it instead.
            'secret_ref' => '?string:190',
            // A real column because the admin screens list providers by
            // registry and by enabled state, which a params key cannot be
            // indexed on.
            'enabled' => ['bool', 'default' => 1],
        ],
        'indexes' => [
            [
                'columns' => ['registry_key', 'provider_key', 'scope_type', 'scope_id'],
                'unique' => true,
                'name' => 'provider_settings_uq',
            ],
            ['columns' => ['provider_key']],
            ['columns' => ['registry_key', 'enabled']],
        ],
    ],
    'feature_providers' => [
        'columns' => [
            'extension_point' => 'string:50',
            'provider_key' => 'string:100',
            'package' => 'string:100',
            'active' => ['bool', 'default' => 1],
            // The conformance kit result a provider declared, and whether it
            // declared none. The three-mode gate is a refusal at registration,
            // but "verified by nobody" is a state a site owner has to be able
            // to see, and a flag that lives in a page's memory cannot answer
            // which installed types are unverified.
            'conformance_result' => '?string:190',
            'flagged' => ['bool', 'default' => 0],
        ],
        'indexes' => [
            ['columns' => ['extension_point', 'provider_key'], 'unique' => true],
        ],
    ],
    'import_jobs' => [
        'columns' => [
            'importer' => 'string:50',
            // Thirty rather than the twenty every other status column carries,
            // because "completed_with_problems" is twenty three characters and
            // twenty is where this wave would have stopped. SQLite ignores a
            // VARCHAR length, so the whole suite passes on a width MySQL
            // refuses: in strict mode the write is "Data too long for column
            // 'status'", raised at the moment a job that met one bad row tries
            // to record that it finished, which is the worst possible moment
            // to lose a run. Found by the migration rehearsal on real MySQL.
            'status' => ['string:30', 'default' => 'pending'],
            'source_path' => '?string:1024',
            'options' => '?json',
            'log' => '?mediumtext',
            'totals' => '?json',
            'started_at' => '?datetime',
            'finished_at' => '?datetime',
            'created_by' => 'uint',
            // The organisation whose roster this run loaded, stamped from the
            // run context and never from a column in the file: a member CSV
            // carrying an organisation column would let an org admin enrol
            // people into another organisation, which is the group-leader leak
            // wearing a spreadsheet.
            //
            // NULL is the site-scoped run every job written before this column
            // existed is, and every migration import an operator starts from
            // the site console still is. It is not a defaulted-away tenant: a
            // null here means the job belongs to the site, which is why the
            // site-scoped list keeps serving those rows and the org-scoped list
            // never sees them.
            'organisation_id' => '?uint',
            'organisation_unit_id' => '?uint',
        ],
        'indexes' => [
            ['columns' => ['status']],
            ['columns' => ['organisation_id', 'status']],
            ['columns' => ['organisation_id', 'organisation_unit_id', 'id']],
        ],
    ],
    // One row of an import that did not go the way the file said it would.
    //
    // Separate from import_jobs.log for a mechanical reason rather than a
    // tidy one: the log is rewritten whole on every progress flush, so ten
    // thousand bad rows in a forty thousand row file make the flush
    // quadratic and the job's own progress becomes the slowest thing in the
    // import. The log gets one summary note per file instead, and this table
    // carries the rows, paged.
    //
    // `row` is 1 based and includes the header, matching Canvas exactly, so
    // an operator's spreadsheet line number matches the report. `file` is the
    // CSV name inside a zip and the empty string for a single-file import.
    // `code` is one of the eight published in RowProblem::CODES; it is stored
    // as text rather than as an enum because a vocabulary in a column type is
    // a migration every time a code is added, and read paths tolerate a code
    // they do not know rather than failing a page of problems.
    //
    // `value_excerpt` is a verbatim fragment of somebody's CSV, bounded by
    // ImportNote::EXCERPT_LIMIT and neutralised through CellSafetyPolicy
    // before it is written. That makes retention a GDPR question and not only
    // a storage one: rows are pruned with their job, on the same thirty day
    // window PruneImportSources already applies to the uploaded file.
    'import_row_problems' => [
        'columns' => [
            'import_job_id' => 'uint',
            'file' => ['string:190', 'default' => ''],
            'row' => 'uint',
            // 190 rather than 120, and the pair is pinned by
            // StatusColumnWidthTest so it cannot drift again.
            // RowProblem::COLUMN_LIMIT is 190 and its docblock calls that "the
            // stored width", so a 190 character export header was written at
            // 190 bytes into a 120 byte column: SQLite ignores the length and
            // MySQL in strict mode answers error 1406 on the row-problem
            // insert, which is the pipeline's worst moment to lose a job.
            'column_name' => '?string:190',
            'code' => 'string:40',
            'message' => 'string:1000',
            // 120, and RowProblem bounds the excerpt AFTER CellSafetyPolicy
            // has prefixed its tab rather than before, so a dangerous cell at
            // the limit is 120 bytes and not 121.
            'value_excerpt' => '?string:120',
            'outcome' => ['string:20', 'default' => 'failed'],
        ],
        'indexes' => [
            ['columns' => ['import_job_id', 'row']],
            ['columns' => ['import_job_id', 'code']],
            ['columns' => ['import_job_id', 'outcome']],
        ],
        'timestamps' => false,
    ],
    // The manifest of one report artefact.
    //
    // GenerateReportTask used to JSON-encode a result and write it to
    // reports/{key}/{jobId}.json with no run record, no format, no row count,
    // no truncation flag, no expiry and nothing that handed the caller a URL,
    // even though StoragePort::signedUrl() already existed. Three independent
    // problems force the manifest: the download must be a signed expiring URL
    // and nothing recorded which path belonged to which request; an artefact
    // of personal data needs a retention date under GDPR Article 5(1)(e) and
    // a sweep must find it without opening object storage by hand; and a run
    // that produced zero rows must be distinguishable from one that failed,
    // because a schedule's empty-report policy branches on exactly that.
    //
    // Every column here is real because something filters or branches on it.
    // state is real because the 202 contract polls on it. row_count and
    // truncated are real because the empty-report policy and the export
    // refusal branch on them. storage_path is real because the signed URL is
    // minted from it. scope_digest is real because a cached run must never be
    // handed to a scope it was not computed for, and it holds OrgScope's own
    // digest verbatim, whose "v1" shape prefix is hashed in rather than
    // printed. timezone is real and NON NULL because a period-bounded filing
    // extract that cannot be regenerated identically is not a filing
    // artefact: it is part of the run's identity, not of its rendering.
    //
    // expires_at is NON NULL for the reason a nullable one fails: the
    // retention sweep filters on it, reports.artefact_retention_days is a
    // settings row rather than a column default, and any row inserted before
    // that setting is read would hold a null and be skipped by a
    // "WHERE expires_at < now" sweep for the life of the site. A genuinely
    // permanent run is a far-future date, never a null.
    'report_runs' => [
        'columns' => [
            'report_key' => 'string:64',
            'format_key' => ['string:20', 'default' => 'json'],
            'delivery_key' => '?string:24',
            'parameters' => '?json',
            'parameters_version' => ['uint', 'default' => 1],
            'timezone' => ['string:64', 'default' => 'UTC'],
            'scope_digest' => 'string:64',
            // Which recipe wrote the digest beside it. Two different shape
            // markers write this column: ReportScopeDigest hashes its own
            // private SHAPE into the value and OrgScope::digest() hashes
            // DIGEST_SHAPE into it, and R1 records that adding the actor id
            // moves the second to v2. Without this column the day either
            // marker moves is the day every stored digest silently stops
            // matching, with nothing to tell a v1 row from a v2 one and no way
            // to say which of the two recipes wrote a given row. It is a
            // filterable migration instead.
            'scope_digest_shape' => ['string:8', 'default' => 'v1'],
            'requested_by' => 'uint',
            // Who the artefact was rendered for, when a schedule rendered per
            // recipient rather than once under the author's scope.
            'rendered_as' => '?uint',
            // The organisation an organisation-scoped run belongs to. Null for
            // a site-scoped run, which is why it is not on the scoped-reader
            // list: a site report belongs to nobody but the site.
            'organisation_id' => '?uint',
            'schedule_id' => '?uint',
            // queued | running | done | empty | failed | expired.
            'state' => ['string:20', 'default' => 'queued'],
            'row_count' => ['uint', 'default' => 0],
            // How far a run has got, beside how far it went. Without it a long
            // export is merely pending rather than observable, and a poll can
            // only say "still running" for twenty minutes. It is written as
            // the export streams rather than at the end, which is the whole
            // point of having it beside row_count.
            'current_line' => ['uint', 'default' => 0],
            'truncated' => ['bool', 'default' => 0],
            'storage_path' => '?string:1024',
            'byte_size' => ['bigint', 'default' => 0],
            'error' => '?text',
            // What happened to the delivery, beside what happened to the run.
            // The published miss direction for the ReportDelivery registry is
            // that the run completes, the artefact waits and the schedule
            // records a delivery failure an admin can see; the pipeline called
            // deliverThrough() as a statement and discarded its DeliveryResult,
            // so a failed delivery ended state "done" with error NULL and
            // nothing anywhere. They are separate from state and error because
            // a run whose rows are perfect and whose mail bounced is not a
            // failed run, and collapsing the two would either hide the bounce
            // or throw away the expensive half.
            'delivery_state' => '?string:20',
            'delivery_error' => '?text',
            'started_at' => '?datetime',
            'finished_at' => '?datetime',
            'expires_at' => 'datetime',
        ],
        'indexes' => [
            ['columns' => ['report_key', 'state']],
            ['columns' => ['requested_by', 'created_at']],
            ['columns' => ['expires_at']],
            ['columns' => ['schedule_id']],
            ['columns' => ['organisation_id', 'created_at']],
        ],
    ],
    // A saved report, its recurrence, its audience and how it is rendered for
    // them. It is Moodle's proven field set rather than a cron string, because
    // a cron string cannot carry a timezone and a schedule with no timezone is
    // a set of DST bugs waiting for the last Sunday in October.
    //
    // The envelope is the one the cohort and the notification schedules
    // already use: a JSON body under a StoredShape version key, with the
    // version mirrored into its own column so a shape migration is a query
    // rather than a full-table JSON walk. A second schedule shape in one
    // product is a second set of those bugs.
    'report_schedules' => [
        'columns' => [
            'title' => 'string:190',
            'report_key' => 'string:64',
            // An export registry key and a delivery registry key, validated
            // against the two registries at save time rather than at 03:00.
            'format_key' => ['string:20', 'default' => 'csv'],
            'delivery_key' => ['string:24', 'default' => 'notification-link'],
            // The saved parameter set, StoredShape-wrapped, so a parameter
            // shape outlives the report version that produced it.
            'definition' => '?json',
            'definition_version' => ['uint', 'default' => 1],
            // Recipients as an audience rather than as a list of addresses,
            // so revoking a role revokes the report. The same audience
            // registry the notification rules resolve through.
            'audience_key' => 'string:100',
            'audience_config' => '?json',
            'audience_config_version' => ['uint', 'default' => 1],
            // daily | weekly | monthly, with the day carried beside it. Not a
            // cron expression: the recurrence and the timezone together are
            // what make the next run reproducible, and a cron string hides the
            // second one.
            'recurrence' => ['string:20', 'default' => 'daily'],
            'recurrence_day' => ['uint', 'default' => 1],
            'run_at_minute' => ['uint', 'default' => 0],
            // Explicit, never inferred from the site. A schedule whose author
            // is in Chennai and whose site is in UTC has one correct answer
            // and it is the one the author chose.
            'timezone' => ['string:64', 'default' => 'UTC'],
            'starts_at' => 'datetime',
            'next_run_at' => '?datetime',
            'last_run_at' => '?datetime',
            // recipient | author, and the default is the recipient. Rendering
            // once under the author's visibility and mailing the same file to
            // a mixed audience is a data breach the first time a site has two
            // group leaders; rendering per recipient is what serves twenty
            // leaders correctly from one schedule.
            'render_as' => ['string:20', 'default' => 'recipient'],
            // send | send_without_file | skip. Real because the task branches
            // on it, and it can only branch on it because report_runs
            // distinguishes a run that produced zero rows from one that failed.
            'empty_policy' => ['string:24', 'default' => 'send'],
            'subject' => '?string:255',
            'body' => '?text',
            // The organisation an organisation-scoped schedule belongs to.
            // Null for a site schedule, on report_runs.organisation_id's own
            // terms: a site report belongs to nobody but the site.
            'organisation_id' => '?uint',
            'state' => ['string:20', 'default' => 'disabled'],
            'created_by' => 'uint',
            'run_count' => ['uint', 'default' => 0],
            'last_error' => '?text',
        ],
        'indexes' => [
            ['columns' => ['state', 'next_run_at']],
            ['columns' => ['report_key']],
            ['columns' => ['organisation_id']],
        ],
    ],
    // The gradebook's three stored things. course_progress.quiz_average is one
    // cached float with no per-item detail and no history, which is enough for
    // a progress bar and not enough for anybody who has ever had a grade
    // disputed.
    //
    // One: a grade item per graded thing, plus per category, plus the course
    // total. A category is an item of its own type carrying the aggregation
    // key rather than a fourth table, because a category total IS a grade item
    // in every vocabulary this design borrows from and two tables would have
    // to agree about the tree.
    'grade_items' => [
        'columns' => [
            'course_id' => 'uint',
            // The category this item folds into. Null for a course total,
            // which is the root of the tree.
            'parent_item_id' => '?uint',
            // activity | category_total | course_total.
            'item_type' => ['string:20', 'default' => 'activity'],
            // What produced it: quiz, assignment, manual, or a provider key a
            // later lesson type ships. Real because "which items break if this
            // quiz is deleted" is a query.
            'source_kind' => ['string:40', 'default' => 'manual'],
            'source_id' => ['uint', 'default' => 0],
            'title' => 'string:190',
            // The maximum this item is configured at today. The grade row
            // carries its own snapshot, which is the whole point of the pair.
            'grade_max' => ['decimal:12,5', 'default' => 100],
            'grade_min' => ['decimal:12,5', 'default' => 0],
            // Basis points, so two weights sum exactly. A float weight is a
            // total that does not add up and nobody can say why.
            'weight_bp' => ['uint', 'default' => 10000],
            'weight_overridden' => ['bool', 'default' => 0],
            'extra_credit' => ['bool', 'default' => 0],
            // A GradeAggregation provider key, never an AggregationMethod one.
            // The two registries fold different things and registering into the
            // wrong one lists a category strategy in the quiz settings picker.
            'aggregation_key' => ['string:60', 'default' => 'natural'],
            'aggregation_params' => '?json',
            'aggregation_params_version' => ['uint', 'default' => 1],
            // Per category and never global. An empty grade is genuinely
            // ambiguous (not submitted, submitted but ungraded, manually
            // deleted), so one global reading of it is wrong for half the
            // sites that have one.
            'empty_grade_policy' => ['string:20', 'default' => 'exclude'],
            'drop_lowest' => ['uint', 'default' => 0],
            'display_mode' => ['string:20', 'default' => 'real'],
            'decimals' => ['uint', 'default' => 2],
            'hidden' => ['bool', 'default' => 0],
            'locked' => ['bool', 'default' => 0],
            'position' => ['uint', 'default' => 0],
        ],
        'indexes' => [
            ['columns' => ['course_id', 'position']],
            ['columns' => ['course_id', 'item_type']],
            ['columns' => ['source_kind', 'source_id']],
            ['columns' => ['parent_item_id']],
        ],
    ],
    // Two: one cell per item per learner, self-describing. raw_grade_max is
    // the column an implementer is tempted to drop and it is the one that
    // must not be: an instructor may change the item maximum afterwards, and
    // without the snapshot an old grade cannot be interpreted at all.
    'grade_cells' => [
        'columns' => [
            'grade_item_id' => 'uint',
            'user_id' => 'uint',
            // Denormalised so a course gradebook is one index range rather
            // than a join through every item, and so a course-scoped erasure
            // finds its rows without one.
            'course_id' => 'uint',
            'raw_grade' => '?decimal:12,5',
            'raw_grade_max' => ['decimal:12,5', 'default' => 100],
            'raw_grade_min' => ['decimal:12,5', 'default' => 0],
            'final_grade' => '?decimal:12,5',
            // LTI AGS 2.0's two independent progress axes, adopted rather than
            // invented. activity_progress is the learner's side (Initialized,
            // Started, InProgress, Submitted, Completed) and grading_progress
            // the grader's (FullyGraded, Pending, PendingManual, Failed,
            // NotReady). A cell whose grading_progress is not FullyGraded does
            // not move the visible total.
            'activity_progress' => ['string:20', 'default' => 'Initialized'],
            'grading_progress' => ['string:20', 'default' => 'NotReady'],
            // OneRoster 1.2's scoreStatus vocabulary: exempt, fully graded,
            // not submitted, partially graded, submitted. A third vocabulary
            // of our own would be a mapper nobody outside this product can
            // read.
            'score_status' => ['string:24', 'default' => 'not submitted'],
            // What the aggregator did with it, so a total can be explained
            // rather than asserted: used, novalue, dropped or unknown, with
            // the weight it was actually folded at.
            'aggregation_status' => ['string:20', 'default' => 'novalue'],
            'aggregation_weight_bp' => ['uint', 'default' => 0],
            'excluded' => ['bool', 'default' => 0],
            'hidden' => ['bool', 'default' => 0],
            'locked' => ['bool', 'default' => 0],
            'overridden_at' => '?datetime',
            'feedback' => '?text',
            'graded_by' => '?uint',
            'graded_at' => '?datetime',
            // The instant the score this cell holds was recorded at, which is
            // AGS's out-of-order protection: a score older than the last
            // accepted one for this user and line item is rejected rather than
            // written.
            'scored_at' => '?datetime',
        ],
        'indexes' => [
            ['columns' => ['grade_item_id', 'user_id'], 'unique' => true],
            ['columns' => ['course_id', 'user_id']],
            ['columns' => ['user_id']],
        ],
    ],
    // Three: the append-only change log, with before, after, actor, reason and
    // request reference. Canvas queries its GradeChangeEvent per assignment,
    // course, student and grader over a time range, and Moodle keeps
    // grade_grades_history, for the same reason both do: somebody will
    // eventually dispute a grade, and a total with no history is one person's
    // word against another's.
    'grade_changes' => [
        'columns' => [
            'grade_cell_id' => 'uint',
            'grade_item_id' => 'uint',
            'course_id' => 'uint',
            'user_id' => 'uint',
            'grade_before' => '?decimal:12,5',
            'grade_after' => '?decimal:12,5',
            'max_before' => '?decimal:12,5',
            'max_after' => '?decimal:12,5',
            'status_before' => '?string:24',
            'status_after' => '?string:24',
            // Null for a machine regrade, which is a fact and not a gap: a row
            // that named a system user would be indistinguishable from one an
            // administrator actually made.
            'actor_id' => '?uint',
            // Why, in the actor's own words or in a provider's reason key.
            // Free text because the audience is a human reading it back during
            // a dispute.
            'reason' => '?string:255',
            // What produced it: an attempt ref, an import job, a request id.
            'request_ref' => '?string:190',
            'payload' => '?json',
            'payload_version' => ['uint', 'default' => 1],
            'occurred_at' => 'datetime',
        ],
        // No updated_at, because an append-only log row is never updated. A
        // ledger with a mutable timestamp is a ledger nobody can rely on
        // during the dispute it exists for.
        'timestamps' => false,
        'indexes' => [
            ['columns' => ['grade_cell_id', 'occurred_at']],
            ['columns' => ['course_id', 'occurred_at']],
            ['columns' => ['user_id', 'occurred_at']],
            ['columns' => ['grade_item_id', 'occurred_at']],
            ['columns' => ['actor_id']],
        ],
    ],
    // What an importer created, keyed by the identity the source system gave
    // it. The unique triple is what makes a re-run an update instead of a
    // duplicate, and what lets a failed import resume; read the other way it
    // answers where a local course, module or question came from.
    'import_map' => [
        'columns' => [
            'importer' => 'string:50',
            'source_type' => 'string:50',
            'source_key' => 'string:190',
            'local_type' => 'string:50',
            'local_id' => 'uint',
            'job_id' => 'uint',
        ],
        'indexes' => [
            ['columns' => ['importer', 'source_type', 'source_key'], 'unique' => true],
            ['columns' => ['job_id']],
        ],
    ],
    'idempotency_keys' => [
        'columns' => [
            'scope' => 'string:100',
            'idem_key' => 'string:190',
            'status' => ['string:20', 'default' => 'claimed'],
            // Text, not json, and this is the one place that distinction
            // matters. The column holds the response body a completed write
            // produced, and a replay of that write has to answer with it
            // again. MySQL's JSON type stores a normalised binary form and
            // hands back the keys in its own order, so a replay came back
            // with the same document written differently. Nothing queries
            // into this column, so the type bought nothing and cost that.
            'outcome' => '?mediumtext',
            'expires_at' => 'datetime',
        ],
        'indexes' => [
            ['columns' => ['scope', 'idem_key'], 'unique' => true],
            ['columns' => ['expires_at']],
        ],
    ],
    'org_notification_secrets' => [
        'columns' => [
            'queue_id' => 'uint', 'organisation_id' => 'uint', 'invitation_id' => 'uint',
            'claim_id' => '?uint', 'sealed_payload' => 'text', 'expires_at' => 'datetime',
        ],
        'indexes' => [
            ['columns' => ['queue_id'], 'unique' => true, 'name' => 'org_notification_secret_queue_uq'],
            ['columns' => ['expires_at']],
        ],
    ],
    'schema_version' => [
        'columns' => [
            'version' => 'string:20',
            'applied_at' => 'datetime',
        ],
        'indexes' => [
            ['columns' => ['version'], 'unique' => true],
        ],
    ],

    // ── Organisations ──────────────────────────────────────────────
    // These sixteen tables were declared in four classes under
    // Infrastructure/Persistence/Organisation and unioned in here at the end of
    // the file. The union kept the left operand, so a same-named key added above
    // would have discarded the fragment silently, and the static calls stopped
    // this file being readable as data without an autoloader. They are declared
    // here, in the order the union produced, and the classes are gone.
    // Three columns here are the shape of the one-table hierarchy model the
    // design records as rejected, and their status is decided rather than
    // left ambiguous.
    //
    // "type" is LIVE and is a label, never a gate. It resolves through the
    // OrgType registry on every organisation read, and an unresolved value
    // renders as its own key. The rejected model is the one that made the
    // tenancy boundary a mutable enum on an editable row; a display label is
    // not that, and the boundary here is a table identity.
    //
    // "parent_id", "root_id" and "depth" are LIVE, and this paragraph said the
    // opposite for a wave after they stopped being dead. They are NOT the
    // hierarchy inside one organisation: that is org_units plus
    // org_unit_closure, and nothing here may be used for it. What they carry
    // is the reseller and holding-company subtree ACROSS organisations, which
    // the design defers to the sub-allocation stage and which
    // Pro\Application\Organisation\OrganisationSubtree is the sole writer of.
    // root_id is written on insert as the row's own id, and attaching a child
    // writes all three at once, so a row never says it is a root and says it
    // has a parent at the same time. Nothing else in this product may write
    // them, and no free code path reads them: a free build sees every
    // organisation as its own root, which is what a free build has.
    'organisations' => [
        'columns' => [
            'name' => 'string:255', 'slug' => 'string:190',
            'type' => ['string:40', 'default' => 'organisation'],
            'state' => ['string:20', 'default' => 'active'],
            'revision' => ['uint', 'default' => 1],
            'parent_id' => '?uint', 'root_id' => 'uint', 'depth' => ['uint', 'default' => 0],
            'contact_email' => '?string:190', 'timezone' => '?string:64', 'locale' => '?string:20',
            // Which account may use an invitation addressed to an address, as
            // a registered InvitationMatch key. It is a column rather than a
            // compile-time literal because the registry was registrable and
            // not selectable: three call sites named DEFAULT_KEY, so a
            // provider could be registered and never asked anything. The
            // default names the free strict_email policy, so every
            // organisation stored before this column reads back as the
            // organisation it already was, and a key nothing resolves fails
            // closed at acceptance rather than admitting anybody.
            'invitation_match_key' => ['string:60', 'default' => 'strict_email'],
            // How long this organisation keeps what it controls, as a versioned
            // stored shape with its version mirrored into a column.
            //
            // It is per organisation and not a site setting because the
            // derivation says so rather than because it is convenient: in an
            // employer-buys-seats arrangement the employer determines the
            // purposes, GDPR Article 28(10) makes that employer a controller in
            // respect of that processing, and EDPB Guidelines 07/2020 reserve
            // the essential means to the controller, naming the duration of the
            // processing among them. One site-wide number cannot be two
            // organisations' answer.
            //
            // Nullable with no default, so every organisation stored before this
            // column reads back as the organisation it already was: no policy,
            // and the site's own floors apply. A policy may only ever lengthen
            // the administrator access log's site floor, never shorten it, which
            // is refused at the write route with a named error and recorded.
            'retention_policy' => '?json',
            'retention_policy_version' => ['uint', 'default' => 1],
            'created_by' => 'uint', 'archived_at' => '?datetime',
        ],
        'indexes' => [
            ['columns' => ['slug'], 'unique' => true, 'name' => 'organisations_slug_uq'],
            ['columns' => ['state', 'id']],
            ['columns' => ['root_id', 'state']],
        ],
    ],
    'org_memberships' => [
        'columns' => [
            'organisation_id' => 'uint', 'org_unit_id' => '?uint', 'user_id' => 'uint',
            'state' => ['string:20', 'default' => 'active'], 'active_key' => '?string:64',
            'joined_at' => 'datetime', 'left_at' => '?datetime',
            'provisioned_by' => ['string:20', 'default' => 'manual'], 'source_ref' => '?string:190',
            'directory_managed' => ['bool', 'default' => 0],
            // OneRoster roles.csv roleType: exactly one primary membership per
            // person per organisation. No index enforces it, because a person
            // may hold none and a unique index cannot express "at most one";
            // OrganisationStore::setDirectory() clears every other primary for
            // the same person in the same transaction, so the rule holds
            // between commits rather than being a comment the console honours.
            'is_primary' => ['bool', 'default' => 0],
            // OneRoster roles.csv beginDate and endDate. They are separate from
            // joined_at and left_at, which record what happened; these record
            // what was agreed, and a roster load has nowhere else to put them.
            'begins_at' => '?datetime', 'ends_at' => '?datetime',
            // The SCIM enterprise extension attributes
            // (urn:ietf:params:scim:schemas:extension:enterprise:2.0:User),
            // which every identity provider already sends and which every
            // organisation report groups by. They are real columns and not JSON
            // for the schema's own stated reason: everything reports filter on
            // is a real column, and they cannot be back filled.
            //
            // They live on the membership rather than on the person because
            // every one of them is true of somebody *within* an organisation
            // and changes when the membership changes. SCIM's own "organization"
            // attribute is stored here as a display label and is never a tenant
            // key: RFC 7643 section 4.3 defines it as free text, so letting it
            // decide a tenant would let a payload field choose its own.
            'job_title' => '?string:190',
            'department_label' => '?string:190',
            'division' => '?string:190',
            'organization_label' => '?string:190',
            'cost_centre' => '?string:100',
            'employee_number' => '?string:100',
            // Validated on every write against an active membership in the same
            // organisation, in the use case, because the free line_manager
            // audience resolves recipients straight from it: an unchecked id
            // belonging to another organisation would send this organisation's
            // compliance reminders, carrying its learners' names, outside it.
            'manager_user_id' => '?uint',
        ],
        'indexes' => [
            ['columns' => ['active_key'], 'unique' => true, 'name' => 'org_memberships_active_uq'],
            ['columns' => ['organisation_id', 'state', 'id']],
            ['columns' => ['organisation_id', 'user_id']],
            ['columns' => ['user_id', 'state']],
            // The line_manager audience's own read: everyone this person
            // manages inside one organisation.
            ['columns' => ['organisation_id', 'manager_user_id']],
        ],
    ],
    'org_role_assignments' => [
        'columns' => [
            'organisation_id' => 'uint', 'membership_id' => 'uint', 'role_key' => 'string:60',
            'scope_type' => ['string:20', 'default' => 'organisation'], 'scope_id' => 'uint',
            'assigned_by' => 'uint',
            // The assigned role's own payload, in the shape its paramsSchema()
            // describes, StoredShape wrapped with its version mirrored beside
            // it. OrgRoleInterface declared both methods ahead of this column
            // and said so in as many words: "the wave that adds a
            // parameterised role adds the column, the StoredShape wrap and the
            // catalogue field in one edit". This is that wave, and the first
            // such role is the Pro group leader, whose group_ids can be
            // neither the assignment's scalar scope_id nor derived from
            // cohort_members, because one leader leads several runs and an
            // assignment has to say what was granted rather than what is true
            // today.
            //
            // Nullable, because every assignment written before this column
            // existed carries no payload and every role that takes none writes
            // none. A row with no params is a role with a closed empty schema,
            // which is what the free four have.
            'params' => '?json',
            // Which shape wrote params, in the StoredShape sense, as a column
            // rather than a key inside the JSON so "can this provider read
            // this row" is answerable without decoding it. Defaulted to 1
            // rather than 0 because a payload-free row has no shape to be
            // wrong about and one is what every shipped provider answers.
            'params_version' => ['uint', 'default' => 1],
        ],
        'indexes' => [
            ['columns' => ['organisation_id', 'membership_id', 'role_key'], 'unique' => true,
                'name' => 'org_role_assignment_uq'],
        ],
    ],
    // The membership event stream, append only: no update and no delete use
    // case exists and none is to be written. It is what lets a customer answer
    // why a person had access on 3 March without replaying a directory log
    // they do not own.
    //
    // ## The discontinuity this table carries, stated once
    //
    // Rows written before R5 carry the coarse event keys the first slice wrote
    // ("member.added", "member.updated") and nulls in every column added
    // below. Rows written from R5 carry the split vocabulary
    // (MembershipEvent::KEYS: role_granted, role_revoked, unit_changed,
    // suspended, resumed, removed, attributes_updated beside joined) and the
    // two clocks. Both development sites already hold such rows, thirty one on
    // Joomla and thirty six on WordPress, written by the user's own journeys.
    //
    // They are left exactly as they are. Backfilling them would mean inventing
    // an occurred_at for an event nobody recorded one for, and guessing which
    // of seven meanings a "member.updated" row had from a details payload that
    // records the state it moved to and not always the reason. A reader
    // therefore treats a null occurred_at as "this row predates the two
    // clocks, created_at is its only clock", and a coarse event key as a fact
    // about when the row was written rather than about what happened.
    'org_membership_events' => [
        'columns' => [
            'organisation_id' => 'uint', 'actor_id' => 'uint', 'subject_id' => '?uint',
            'event_key' => 'string:80', 'organisation_revision' => 'uint', 'details' => 'json',
            // Which shape wrote "details", in the StoredShape sense: 0 is a row
            // written before the version existed, never a shape called 0. It is
            // a column and not a key inside the JSON because this ledger is
            // append only, so the question "which rows can this reader
            // understand" has to be answerable without decoding every row.
            'payload_version' => ['uint', 'default' => 0],
            // Where in the tree the event happened, so a unit manager's own
            // audit read is an index range rather than a scan that decodes
            // every payload looking for a unit id inside JSON.
            'org_unit_id' => '?uint',
            // The role the event granted or revoked. Null on an event that is
            // not about a role, which is most of them.
            'role_key' => '?string:60',
            // Which provenance wrote it: a MembershipEvent::SOURCES word.
            // Defaulted rather than nullable because every row written from
            // here on has a provenance and "manual" is what a row written by a
            // person in the console is.
            'source' => ['string:20', 'default' => 'manual'],
            // The foreign object the source names: a OneRoster sourcedId, an
            // invitation id, an import job. Never an email address.
            'source_ref' => '?string:190',
            // system, principal, importer or gateway. The actor_id beside it
            // answers "who", this answers "what kind of thing".
            'actor_type' => ['string:20', 'default' => 'principal'],
            // The MappingDecision this event was the effect of, for the
            // identity path to point at. Null everywhere no rule was
            // evaluated, which is every row a person wrote by hand.
            'decision_ref' => '?string:190',
            // The two clocks. A backdated correction and a delayed webhook are
            // otherwise indistinguishable: occurred_at is when the thing
            // happened in the world, recorded_at is when this row was written.
            //
            // Both are nullable, which is a deviation from the design's
            // non-null pair and is deliberate. A NOT NULL datetime added to a
            // table that already holds rows writes a value onto every one of
            // them, and both development sites hold real rows whose occurrence
            // instant nobody recorded. A null here means "written before the
            // two clocks existed"; created_at is that row's only clock.
            'occurred_at' => '?datetime',
            'recorded_at' => '?datetime',
        ],
        'indexes' => [
            ['columns' => ['organisation_id', 'id']],
            // The two the design names, with subject_id standing in for the
            // design's user_id because that is what this table calls the
            // person an event is about.
            ['columns' => ['organisation_id', 'occurred_at']],
            ['columns' => ['subject_id', 'occurred_at']],
        ],
    ],
    'invitations' => [
        'columns' => [
            'organisation_id' => 'uint', 'email' => 'string:190', 'role_key' => 'string:60',
            'state' => ['string:20', 'default' => 'pending'], 'pending_key' => '?string:64',
            'token_hash' => '?string:64', 'revision' => ['uint', 'default' => 1],
            'expires_at' => 'datetime', 'issued_by' => 'uint', 'accepted_by' => '?uint',
            'accepted_at' => '?datetime',
            // The reservation this invitation is a promise against, so
            // reserve-at-issue is honest rather than implied. Nullable because
            // an invitation to an organisation with no seat pool promises
            // membership and nothing else, which is most of them.
            'seat_assignment_id' => '?uint',
        ],
        'indexes' => [
            ['columns' => ['pending_key'], 'unique' => true, 'name' => 'org_invitation_pending_uq'],
            ['columns' => ['token_hash'], 'unique' => true, 'name' => 'org_invitation_token_uq'],
            ['columns' => ['organisation_id', 'state', 'id']],
            ['columns' => ['organisation_id', 'expires_at']],
            // The invitation expiry sweep releases a reservation as it expires
            // the invitation, so it reads the pair rather than the row twice.
            ['columns' => ['organisation_id', 'seat_assignment_id']],
        ],
    ],
    'org_invitation_claims' => [
        'columns' => [
            'organisation_id' => 'uint', 'invitation_id' => 'uint', 'invitation_revision' => 'uint',
            'user_id' => 'uint', 'email_hash' => 'string:64', 'proof_hash' => '?string:64',
            'state' => ['string:20', 'default' => 'pending'], 'expires_at' => 'datetime',
        ],
        'indexes' => [
            ['columns' => ['organisation_id', 'invitation_id', 'user_id']],
            ['columns' => ['expires_at']],
        ],
    ],
    'org_invitation_limits' => [
        'columns' => ['bucket_key' => 'string:64', 'attempts' => 'uint', 'expires_at' => 'datetime'],
        'indexes' => [
            ['columns' => ['bucket_key'], 'unique' => true, 'name' => 'org_invitation_bucket_uq'],
            ['columns' => ['expires_at']],
        ],
    ],
    'org_access_termination_jobs' => [
        'columns' => [
            'organisation_id' => 'uint', 'user_id' => '?uint', 'course_id' => '?uint', 'actor_id' => 'uint',
            'reason' => 'string:60', 'state' => ['string:20', 'default' => 'queued'],
            'max_entitlement_id' => 'uint', 'max_assignment_id' => 'uint',
            'processed_count' => ['uint', 'default' => 0], 'failure_code' => '?string:60',
        ],
        'indexes' => [['columns' => ['organisation_id', 'state', 'id']], ['columns' => ['state', 'id']]],
    ],
    'org_learning_locks' => [
        'columns' => ['user_id' => 'uint', 'course_id' => 'uint', 'revision' => ['uint', 'default' => 1]],
        'indexes' => [['columns' => ['user_id', 'course_id'], 'unique' => true, 'name' => 'org_learning_lock_uq']],
    ],
    'org_course_releases' => [
        'columns' => [
            'organisation_id' => 'uint', 'course_id' => 'uint', 'released_by' => 'uint',
            'state' => ['string:20', 'default' => 'active'],
            'starts_at' => '?datetime', 'ends_at' => '?datetime',
        ],
        'indexes' => [
            ['columns' => ['organisation_id', 'course_id'], 'unique' => true, 'name' => 'org_course_release_uq'],
            ['columns' => ['organisation_id', 'state', 'id']],
        ],
    ],
    // The selection an organisation makes from the set above, and never a
    // widening of it. Written by an org administrator; every row is validated
    // against org_course_releases at save and re-checked at grant time, because
    // a release can be withdrawn after an entry was written.
    //
    // source_key names a registered OrgCatalogueSource. A course reaches an
    // organisation through a provider and never through a column, which is what
    // lets a programme, a stored query or a seat pool add a way of selecting
    // without a schema change. course_id is nonetheless a real nullable column,
    // because the learner catalogue listing and the reverse question (which
    // organisations reach this course) are both indexed reads and a query-shaped
    // source's own parameters cannot answer either.
    //
    // grant_mode is real for the same reason: the auto-grant sweep selects on
    // it. visible is Docebo's per-branch catalogue, auto_grant is LearnDash's
    // group courses, and IOMAD's Instant access flag is the proof that the two
    // are different questions rather than one setting.
    'org_catalogue_entries' => [
        'columns' => [
            'organisation_id' => 'uint',
            'scope_type' => ['string:20', 'default' => 'organisation'],
            'scope_id' => 'uint',
            'source_key' => ['string:40', 'default' => 'course'],
            'course_id' => '?uint',
            'params' => '?json',
            'params_version' => ['uint', 'default' => 1],
            'grant_mode' => ['string:20', 'default' => 'visible'],
            'applies_to_descendants' => ['bool', 'default' => 1],
            'state' => ['string:20', 'default' => 'active'],
            'starts_at' => '?datetime',
            'ends_at' => '?datetime',
            'created_by' => 'uint',
        ],
        'indexes' => [
            ['columns' => ['organisation_id', 'state']],
            ['columns' => ['organisation_id', 'course_id']],
            ['columns' => ['course_id']],
        ],
    ],
    'seat_pools' => [
        'columns' => [
            'organisation_id' => 'uint', 'course_id' => 'uint', 'name' => 'string:255',
            'state' => ['string:20', 'default' => 'active'], 'seats_purchased' => ['uint', 'default' => 0],
            'seats_granted' => 'uint', 'seats_assigned' => ['uint', 'default' => 0],
            'reclaim_policy' => ['string:60', 'default' => 'before_completion'],
            // The other three policy families, each a real column and not a key
            // inside policy_params, because the reconciliation report groups on
            // them and a key inside JSON cannot be indexed for the expiry sweep.
            // Each default names the free provider, so every pool written
            // before these columns existed reads back as the pool it already
            // was and behaves identically.
            'scope_key' => ['string:60', 'default' => 'course'],
            'over_allocation_key' => ['string:60', 'default' => 'refuse_and_queue'],
            'expiry_disposition_key' => ['string:60', 'default' => 'lapse_access_keep_progress'],
            // A provider's own configuration, interpreted by nothing but the
            // provider, which is exactly the rule that makes a column JSON.
            // Each carries its StoredShape version beside it rather than inside
            // it, so the question "can this provider read this row" is
            // answerable without decoding it. 0 is a row written before the
            // column existed, never a shape called 0.
            'scope_params' => '?json',
            'scope_params_version' => ['uint', 'default' => 0],
            'policy_params' => '?json',
            'policy_params_version' => ['uint', 'default' => 0],
            // Microsoft's ninety-day reassignment rule generalised: the minimum
            // days a seat is held before it may be reclaimed, defaulting to
            // zero so a seller who needs anti-churn has it and one who does not
            // is unaffected.
            'min_hold_days' => ['uint', 'default' => 0],
            // IOMAD's licence-is-valid-for-N-days, distinct from a term end: a
            // pool bought in January whose learners each get ninety days has no
            // single ends_at.
            'access_days' => '?uint',
            // Correlate externally authorized seat access with its funding source.
            // The external shop owns prices, payments and financial documents.
            'funding_state' => ['string:20', 'default' => 'none'],
            'funding_ref' => '?string:190',
            // The first activation makes seat capacity available and records
            // pool_funded in the access ledger. Draft or awaiting_payment pools
            // have zero capacity until then; an abandoned draft keeps this null.
            'funded_at' => '?datetime',
            'starts_at' => '?datetime', 'ends_at' => '?datetime', 'created_by' => 'uint',
        ],
        'indexes' => [
            ['columns' => ['organisation_id', 'state', 'id']],
            // The term sweep asks for every pool past its end date across all
            // organisations, which has no organisation to lead with.
            ['columns' => ['state', 'ends_at']],
        ],
    ],
    'seat_assignments' => [
        'columns' => [
            'organisation_id' => 'uint', 'pool_id' => 'uint',
            // Both nullable since reserve-at-issue, and only for the reserved
            // state. A pending invitation to somebody with no account cannot
            // be a membership row, which is the whole reason invitations are a
            // first-class resource, so a seat reserved at issue has no
            // membership and may have no account either. Every other state
            // still carries both, and convert() is the one writer that fills
            // them in. Widening a column keeps every row already stored.
            'membership_id' => '?uint', 'user_id' => '?uint',
            'state' => ['string:20', 'default' => 'assigned'], 'holder_key' => '?string:100',
            // When an unclaimed reservation gives its seat back. Null for
            // every state but reserved, which is what lets the reservation
            // sweep ask for the due ones with one indexed predicate instead of
            // scanning the assignment table for a state and a date.
            'reserved_until' => '?datetime',
            'entitlement_id' => '?uint', 'assigned_by' => 'uint', 'assigned_at' => 'datetime',
            'closed_at' => '?datetime', 'problem_code' => '?string:60',
            // An opaque value from Port/Random, minted BEFORE the insert, and
            // what source_ref names. The row's own id does not exist until the
            // insert has happened, so a ref built from it would need a
            // placeholder and an update, which reopens the double-assign race
            // holder_key closes. Nullable only because the rows already stored
            // have none and NULLs are distinct under a unique index on both
            // MySQL and SQLite; every row this wave writes carries one.
            'assignment_ref' => '?string:32',
            // When the first progress event and the completion arrived, so the
            // reclaim policy reads the assignment and never the enrolment. They
            // are columns rather than a derivation because the reconciliation
            // report has to be answerable as at a past date, and a derivation
            // over today's progress cannot be.
            'started_at' => '?datetime',
            'completed_at' => '?datetime',
            // When this row began occupying a seat, which is not when it was
            // created: an over-allocation row is created holding nothing and
            // starts holding a seat only if a capacity rise promotes it. The
            // as-at reconciliation reads this column and closed_at, so a row
            // that was queued on the date being asked about is not counted as
            // having held a seat then. Null means the row has never held one,
            // which is true of every pending row and of every over-allocation
            // that was withdrawn without being promoted.
            'held_from' => '?datetime',
        ],
        'indexes' => [
            ['columns' => ['holder_key'], 'unique' => true, 'name' => 'org_seat_holder_uq'],
            ['columns' => ['assignment_ref'], 'unique' => true, 'name' => 'org_seat_assignment_ref_uq'],
            ['columns' => ['organisation_id', 'pool_id', 'state', 'id']],
            ['columns' => ['organisation_id', 'membership_id', 'state']],
            ['columns' => ['organisation_id', 'entitlement_id']],
            // The reservation sweep asks for every reservation past its window
            // across all organisations, which has no organisation to lead with,
            // exactly as the pool term sweep's (state, ends_at) does.
            ['columns' => ['reserved_until']],
        ],
    ],
    'seat_events' => [
        'columns' => [
            'organisation_id' => 'uint', 'pool_id' => 'uint', 'assignment_id' => '?uint',
            'event_key' => 'string:60', 'seat_delta' => 'int', 'balance_after' => 'int',
            'subject_user_id' => '?uint', 'entitlement_id' => '?uint', 'actor_id' => 'uint',
            'reason_key' => 'string:60', 'occurred_at' => 'datetime', 'recorded_at' => 'datetime',
            // Who moved the seat, as a kind rather than only an id: system,
            // principal, importer or gateway. Nullable with no default on
            // purpose. A default would have written "principal" onto every row
            // already in the ledger, and those rows were hashed without the
            // column, so the chain over them would stop verifying the day
            // anything checked it. A row carrying nothing hashes as though the
            // column were absent, exactly as payload_version and
            // idempotency_key already do.
            'actor_type' => '?string:20',
            // The reference in whatever system caused this, for a delayed
            // webhook or a re-run import. Nullable for the same reason and by
            // the same rule.
            'external_ref' => '?string:190',
            'organisation_revision' => 'uint', 'prev_hash' => 'string:64', 'hash' => 'string:64',
            'payload' => 'json',
            // Which shape wrote "payload", in the StoredShape sense. It is part
            // of the hashed row, so a shape change is provable rather than
            // merely suspected; a row at version 0 was written before the
            // column existed and is hashed as though the column were absent, so
            // every chain written before this migration still verifies.
            'payload_version' => ['uint', 'default' => 0],
            // Append once. Nullable because the rows already in the ledger have
            // no key and NULLs are distinct under a unique index on both MySQL
            // and SQLite; the writer that mints one is the seat lifecycle work,
            // not this migration.
            'idempotency_key' => '?string:190',
        ],
        'indexes' => [
            ['columns' => ['organisation_id', 'pool_id', 'id']],
            ['columns' => ['idempotency_key'], 'unique' => true, 'name' => 'seat_events_idem_uq'],
        ],
    ],
    'org_units' => [
        'columns' => ['organisation_id' => 'uint', 'parent_id' => '?uint', 'name' => 'string:190',
            'path' => 'string:1024', 'path_digest' => 'string:64', 'depth' => 'uint',
            'state' => ['string:20', 'default' => 'active']],
        'indexes' => [
            ['columns' => ['organisation_id', 'path_digest'], 'unique' => true, 'name' => 'org_unit_path_uq'],
            ['columns' => ['organisation_id', 'parent_id', 'state', 'id']],
        ],
    ],
    'org_unit_closure' => [
        'columns' => ['organisation_id' => 'uint', 'ancestor_id' => 'uint', 'descendant_id' => 'uint', 'depth' => 'uint'],
        'indexes' => [
            ['columns' => ['organisation_id', 'ancestor_id', 'descendant_id'], 'unique' => true, 'name' => 'org_unit_closure_uq'],
            ['columns' => ['organisation_id', 'descendant_id', 'depth']],
        ],
    ],
    // The six elements NIST SP 800-53 AU-3 fixes as the minimum audit record
    // (what happened, when, where, the source, the outcome and the identities
    // involved) plus the two organisation anchors, because in an organisations
    // feature the threat is an administrator reading rather than writing.
    //
    // Reads are recorded, not only writes: ISO/IEC 27001:2022 A.8.15 names
    // access attempts among the events to log. There is no update use case and
    // no delete use case; the retention sweep is the only writer that removes a
    // row, because 21 CFR 11.10(e) forbids a change that obscures previously
    // recorded information and A.8.15 requires that a privileged user cannot
    // interact with the log of the system they control.
    //
    // prev_hash and hash are the chain seat_events already carries, computed by
    // the same function, and they are written free from day one because a chain
    // started later cannot cover the rows written before it. ip_hash rather than
    // an address, because the log is itself personal data and the question it
    // answers, "was this the same source", survives hashing.
    'admin_access_log' => [
        'columns' => [
            'event_type' => 'string:40',
            'occurred_at' => 'datetime',
            'actor_id' => 'uint',
            'actor_org_id' => '?uint',
            // Every role the caller held, sorted and comma joined, not the
            // first one alphabetically. AU-3 asks under what authority a read
            // was made, and a caller holding two roles was recorded under
            // whichever sorted first, permanently, in a record with no update
            // path by design. The width is unchanged, because the table is
            // installed on live sites and widening it is an ALTER against an
            // append-only audit table; a set too wide for it keeps whole keys
            // and carries a "+N more" suffix, so a truncation says it happened
            // and the full sorted set is still hashed into scope_digest.
            'actor_role_key' => '?string:40',
            'subject_user_id' => '?uint',
            'subject_org_id' => '?uint',
            // The existing "course:{id}" grammar, extended with "org:{id}"
            // rather than a second grammar invented beside it.
            'resource' => '?string:100',
            'route' => 'string:190',
            // allowed | refused.
            'outcome' => ['string:20', 'default' => 'allowed'],
            'record_count' => ['uint', 'default' => 0],
            // OrgScope::digest(): a sha256 whose version prefix is hashed in
            // rather than printed, so the column holds 64 characters and the
            // day the recipe changes every stored digest stops matching, which
            // is the point of versioning it at all.
            'scope_digest' => '?string:64',
            'request_ref' => '?string:64',
            'ip_hash' => '?string:64',
            'prev_hash' => '?string:64',
            'hash' => 'string:64',
        ],
        'indexes' => [
            ['columns' => ['actor_org_id', 'occurred_at']],
            ['columns' => ['subject_org_id', 'occurred_at']],
            ['columns' => ['actor_id', 'occurred_at']],
            ['columns' => ['subject_user_id', 'occurred_at']],
            ['columns' => ['outcome', 'occurred_at']],
        ],
        // The row carries its own occurred_at, and an append-only record that
        // also carried an updated_at would be advertising a write nothing may
        // make.
        'timestamps' => false,
    ],

    // ── Organisation identity and provisioning ────────────────────────────
    //
    // Four tables and not one of them holds a credential. There is no
    // certificate column, no secret column and no token column on the
    // connection, which is how "the core never terminates an identity
    // protocol and never holds a signing key" is true in the schema rather
    // than only in prose. secret_ref is a reference the host resolves through
    // the SecretsPort ref grammar and is never the secret itself.

    // An organisation's claim on an issuer, with the same ceremony a domain
    // claim gets and for the same reason: a claim over a namespace somebody
    // else owns has to be proved.
    //
    // Uniqueness on (organisation_id, issuer) alone would let two
    // organisations register one issuer and both have their rules fire on the
    // other's assertion, so verified_issuer_key holds the issuer while the
    // claim is verified and NULL otherwise, under a GLOBAL unique index. Many
    // organisations may hold pending claims on one issuer; at most one may
    // hold it verified. It is the device org_domains.verified_key uses and
    // the device cohort_members.active_key uses, for the third time and the
    // same reason.
    //
    // Writes: the row is written free by an organisation's own settings
    // holder. issuer_status, authoritative and jit_enabled are written only
    // by a site-scoped principal, and each change is an audited event on the
    // membership stream. An organisation administrator who could set
    // authoritative could turn a pending link into an active one on an
    // address they do not own.
    'org_connections' => [
        'columns' => [
            'organisation_id' => 'uint',
            // What kind of thing asserts through this connection. The free
            // build writes one value and the column is open, because the
            // other words belong to packages that are not installed.
            'protocol' => ['string:20', 'default' => 'host'],
            // The IdentityMapper key. A connection names the dialect that
            // reads its assertions, so a mapper is registered rather than
            // selected by a chain of instanceof checks.
            'dialect' => ['string:40', 'default' => 'host-group'],
            'issuer' => 'string:190',
            // pending, verified, failed, revoked.
            'issuer_status' => ['string:20', 'default' => 'pending'],
            'issuer_verified_at' => '?datetime',
            'issuer_verified_by' => '?uint',
            'verified_issuer_key' => '?string:190',
            // A subsidiary under the same root uses a parent's verified
            // connection instead of claiming the issuer itself, so a holding
            // company on one identity tenant is not forced to make one of its
            // organisations the only verified holder.
            'delegated_from_connection_id' => '?uint',
            'authoritative' => ['bool', 'default' => 0],
            'jit_enabled' => ['bool', 'default' => 0],
            // complete or partial. Partial is the default because the safe
            // reading of a source nobody has characterised is that it may have
            // been truncated, and a partial source may never remove anything.
            'default_completeness' => ['string:10', 'default' => 'partial'],
            // A reference in the SecretsPort "env:NAME" or "store:KEY"
            // grammar. Never a secret.
            'secret_ref' => '?string:190',
            'attribute_overrides' => '?json',
            'attribute_overrides_version' => ['uint', 'default' => 1],
            // The ordered rule list, as one StoredShape envelope. A shape this
            // build cannot read grants nothing rather than throwing, which is
            // the fail-closed direction the role registry already publishes.
            'mapping_rules' => '?json',
            'mapping_rules_version' => ['uint', 'default' => 1],
            'state' => ['string:20', 'default' => 'active'],
            'created_by' => 'uint',
        ],
        'indexes' => [
            ['columns' => ['organisation_id', 'issuer'], 'unique' => true, 'name' => 'org_connections_uq'],
            ['columns' => ['verified_issuer_key'], 'unique' => true, 'name' => 'org_connections_verified_issuer_uq'],
            // "Which organisations claim this issuer" is the read, and the
            // status is deliberately not on it: 190 plus 20 characters is 840
            // bytes under utf8mb4 and the legacy row format floor is 767, so
            // the status is filtered after the seek rather than indexed. At
            // most a handful of organisations ever claim one issuer, and at
            // most one of them holds it verified, which the global unique key
            // above already enforces.
            ['columns' => ['issuer']],
            ['columns' => ['delegated_from_connection_id']],
            ['columns' => ['organisation_id', 'state', 'id']],
        ],
    ],

    // Which host account an asserted identity is, keyed on the pair and never
    // on an address.
    //
    // There is deliberately NO email column and no unique index on any address
    // anywhere in this table. An address is reassignable, is asserted by the
    // party whose assertion is being checked, and keying on one is how a
    // directory load creates duplicate people that are very hard to correct
    // afterwards. It is also what lets a roster membership carrying no address
    // at all be a supported state: a conformant membership service is
    // guaranteed to carry only an identifier and a role.
    //
    // identity_key is a sha256 over issuer, a NUL and subject, and it is what
    // the unique index is on, so the comparison is byte exact by construction
    // rather than by a collation the host could have installed differently.
    // That is the same device entitlements.source_ref_digest already uses and
    // for the second of its two reasons as well: (issuer, subject) as a
    // natural key is 445 characters, which is 1780 bytes under utf8mb4 and is
    // refused by this schema's own index width assertion, whose message names
    // the digest as the remedy. It is also the mitigation for the assertion
    // comment truncation family, where a document traversal returned a
    // different subject string than the one the signature actually covered:
    // two different byte strings hash differently and one of them matches
    // nothing.
    'org_identity_links' => [
        'columns' => [
            // Nullable because a link is minted before any tenant is known:
            // the pair identifies a person, and which organisations they then
            // belong to is a membership question.
            'organisation_id' => '?uint',
            'connection_id' => '?uint',
            'issuer' => 'string:190',
            'subject' => 'string:255',
            'identity_key' => 'string:64',
            'user_id' => 'uint',
            // The directory's own immutable object id, never a name and never
            // an address, stored with its connection so two feeds against one
            // tree can coexist.
            'external_id' => '?string:190',
            // Which rule fired, from a closed vocabulary. Every match decision
            // is recorded, because "why is this person linked to that account"
            // is the question a duplicate makes urgent and unanswerable.
            'match_basis' => 'string:30',
            'match_ref' => '?string:190',
            // active, pending or conflict. A conflict is a row an
            // administrator resolves; two existing people are never merged
            // automatically.
            'state' => ['string:20', 'default' => 'active'],
            'first_seen_at' => 'datetime',
            'last_asserted_at' => '?datetime',
        ],
        'indexes' => [
            ['columns' => ['identity_key'], 'unique' => true, 'name' => 'org_identity_links_uq'],
            // The plain index for "every subject this issuer has asserted",
            // which the unique key on the digest cannot serve because a digest
            // has no prefix.
            ['columns' => ['issuer', 'user_id']],
            ['columns' => ['user_id']],
            ['columns' => ['connection_id', 'external_id']],
            ['columns' => ['organisation_id', 'external_id']],
            ['columns' => ['organisation_id', 'state', 'id']],
        ],
    ],

    // An organisation's claim on a mail domain, recorded free and shown
    // unverified until something can prove it.
    //
    // verified_key holds the domain while the claim is verified and NULL
    // otherwise, under a unique index, so several organisations may hold
    // pending claims on one domain and only one may hold it verified.
    // last_checked_at and failure_count are real columns because the re-check
    // sweep orders on them: verification is not a one-time event, and a domain
    // whose record disappears loses verified status along with every policy
    // that depended on it. A one-shot check is a latent hole.
    'org_domains' => [
        'columns' => [
            'organisation_id' => 'uint',
            // Lower-cased, and punycode for an international domain, so the
            // stored value is the one every comparison is made against.
            'domain' => 'string:190',
            'verified_key' => '?string:190',
            // dns-txt or http-file.
            'method' => ['string:20', 'default' => 'dns-txt'],
            'token' => 'string:64',
            // pending, verified, failed, revoked.
            'status' => ['string:20', 'default' => 'pending'],
            'verified_at' => '?datetime',
            'last_checked_at' => '?datetime',
            'failure_count' => ['uint', 'default' => 0],
            'created_by' => 'uint',
        ],
        'indexes' => [
            ['columns' => ['verified_key'], 'unique' => true, 'name' => 'org_domains_verified_uq'],
            ['columns' => ['organisation_id', 'status']],
            ['columns' => ['domain']],
            ['columns' => ['status', 'last_checked_at']],
        ],
    ],

    // How a person may join an organisation, as an owned row rather than as a
    // boolean on a domain.
    //
    // Auto-join is a grant of access, so it needs an owner, an effective date,
    // the role it grants and an audit trail, and every join it causes writes a
    // membership event naming the rule that admitted the person. A
    // configuration flag that silently admits anyone whose address ends in the
    // right characters is a tenancy hole with no record of who opened it.
    'org_join_rules' => [
        'columns' => [
            'organisation_id' => 'uint',
            'rule_key' => ['string:40', 'default' => 'invitation_only'],
            'params' => '?json',
            'params_version' => ['uint', 'default' => 1],
            'role_key' => ['string:40', 'default' => 'org_member'],
            'state' => ['string:20', 'default' => 'active'],
            'effective_from' => '?datetime',
            'effective_to' => '?datetime',
            'created_by' => 'uint',
        ],
        'indexes' => [
            ['columns' => ['organisation_id', 'state']],
            ['columns' => ['rule_key']],
        ],
    ],

    // ── Compliance ────────────────────────────────────────────────────────
    //
    // Four tables that ship free with **no rule registered into the
    // ComplianceRule seam**, which is the design's own free line and not a
    // half-finished feature. The tables are free because a rule is a
    // registration and a table is a migration: shipping the schema now means a
    // Pro or Business rule reaches a site without one, and the alternative is
    // that the first paid rule carries a core schema change with it.
    //
    // Compliance is a separate seam from availability. Availability answers may
    // this person open this now; compliance answers must this person have
    // completed this by when, and when must they do it again. A missed deadline
    // is not a locked door.
    'compliance_requirements' => [
        'columns' => [
            'organisation_id' => 'uint',
            'scope_type' => ['string:20', 'default' => 'organisation'],
            'scope_id' => 'uint',
            'title' => 'string:255',
            'course_id' => '?uint',
            'program_id' => '?uint',
            // mandatory | required | recommended | optional. Mandatoriness lives
            // here and never on courses, because the same course is mandatory
            // for one organisation and optional for another, and this column is
            // what finally gives the compliance report a denominator.
            'requirement' => ['string:20', 'default' => 'mandatory'],
            // The registered ComplianceRule key, or "none". A word rather than a
            // null, so "which rule decided this" always has an answer and the
            // free console renders "assigned, untracked" from the row instead of
            // from an absence.
            'rule_key' => ['string:40', 'default' => 'none'],
            'rule_params' => '?json',
            'rule_params_version' => ['uint', 'default' => 1],
            // due_at marks late and blocks nothing; enforced_at is optional and
            // is what an availability condition or an entitlement sweep reads.
            // Two dates and a policy rather than a boolean, reusing the due and
            // cutoff distinction date_overrides already carries.
            'enforced_at' => '?datetime',
            'state' => ['string:20', 'default' => 'active'],
            'created_by' => 'uint',
        ],
        'indexes' => [
            ['columns' => ['organisation_id', 'state']],
            ['columns' => ['course_id']],
            ['columns' => ['rule_key']],
        ],
    ],
    'compliance_assignments' => [
        'columns' => [
            'requirement_id' => 'uint',
            'organisation_id' => 'uint',
            'user_id' => 'uint',
            'cycle' => ['uint', 'default' => 1],
            // Eight dates as eight columns, because every one of them is
            // filtered or bucketed by a report. baseline_expires_at stays
            // separate from expires_at or the series drifts by the length of
            // every late completion: under a fixed scheme with a minimum active
            // period the effective date moves and the next cycle must still
            // compute from the baseline.
            'assigned_at' => 'datetime',
            'starts_at' => '?datetime',
            'due_at' => '?datetime',
            'completed_at' => '?datetime',
            'window_opens_at' => '?datetime',
            'expires_at' => '?datetime',
            'baseline_expires_at' => '?datetime',
            'grace_ends_at' => '?datetime',
            // Two orthogonal axes, both closed vocabularies. A person can be
            // compliant and due for renewal at once, which one enumeration
            // cannot express, and a denominator cannot be computed over an open
            // vocabulary. overdue (never completed by the due date) stays
            // distinct from expired (completed, then lapsed).
            'compliance_status' => ['string:20', 'default' => 'assigned'],
            'renewal_status' => ['string:20', 'default' => 'not_due'],
            // The resolving rule's own per-assignment memory, with its shape
            // version mirrored. Never read by a report and never joined on,
            // which is the schema's JSON doctrine applied correctly: without it
            // the first rule needing a ninth date is a core migration.
            'rule_state' => '?json',
            'rule_state_version' => ['uint', 'default' => 1],
            'entitlement_id' => '?uint',
            'certificate_id' => '?uint',
        ],
        'indexes' => [
            ['columns' => ['requirement_id', 'user_id', 'cycle'], 'unique' => true,
                'name' => 'compliance_assignments_uq'],
            ['columns' => ['organisation_id', 'compliance_status']],
            ['columns' => ['user_id', 'compliance_status']],
            ['columns' => ['due_at']],
            ['columns' => ['expires_at']],
        ],
    ],
    // The previous cycle, kept rather than overwritten. The recertification
    // window reset is destructive and 21 CFR 11.10(e) forbids a change that
    // obscures previously recorded information, so the completion, the
    // certificate and the dates of every earlier cycle survive the reset that
    // opens the next one. It carries no organisation_id because it hangs off an
    // assignment that has one.
    'compliance_cycles' => [
        'columns' => [
            'assignment_id' => 'uint',
            'cycle' => 'uint',
            'completed_at' => '?datetime',
            'certificate_id' => '?uint',
            'expires_at' => '?datetime',
            'baseline_expires_at' => '?datetime',
            'reset_at' => '?datetime',
            'reset_ref' => '?string:190',
        ],
        'indexes' => [
            ['columns' => ['assignment_id', 'cycle'], 'unique' => true, 'name' => 'compliance_cycles_uq'],
        ],
    ],
    // Assignment raises an exception rather than assigning silently. Every kind
    // here is a fact somebody has to decide about, and a routine that skipped
    // them would produce a compliance report whose denominator is quietly wrong.
    // anchor_unresolved is the one a relative-anchor design hits first: the
    // shipped ScheduleAnchor registry supplies the anchor and publishes that an
    // unresolved anchor blocks rather than opens, so a requirement whose anchor
    // resolves to null writes a row here instead of passing over a person.
    'assignment_exceptions' => [
        'columns' => [
            'organisation_id' => 'uint',
            'requirement_id' => '?uint',
            'user_id' => 'uint',
            'kind' => 'string:40',
            'resolved_input' => '?json',
            'resolved_input_version' => ['uint', 'default' => 1],
            'raised_at' => 'datetime',
            'resolved_at' => '?datetime',
            'resolved_by' => '?uint',
            'resolution' => '?string:40',
        ],
        'indexes' => [
            ['columns' => ['organisation_id', 'kind', 'resolved_at']],
            ['columns' => ['requirement_id', 'user_id']],
        ],
    ],
    // ── Ledger redaction ──────────────────────────────────────────────────
    //
    // The receipt of a chain-preserving pseudonymisation, held outside the
    // ledgers it describes.
    //
    // The design resolves the ledger-versus-erasure tension by RETAINING a
    // seat_events row under the legal_obligation basis with the member identity
    // PSEUDONYMISED, because deleting ledger rows breaks the append-only
    // guarantee finance depends on and the hash chain that makes it
    // tamper-evident. Doing that needs somewhere to record it: subject_user_id
    // is inside the hashed row, so zeroing it changes what a verifier
    // recomputes, and without this table an erasure and an edit would look
    // identical.
    //
    // It is a separate table and not two columns on the ledgers, deliberately.
    // Adding a column with a default to seat_events would write a value onto
    // every row already in the chain, and those rows were hashed without it, so
    // the chain over them would stop verifying the day anything checked it.
    // Nothing here alters a hashed column: hash and prev_hash are never
    // rewritten, so the link structure of the chain still proves that nothing
    // was inserted, deleted or reordered, and pre_hash is bound into it by the
    // following row's prev_hash, which was written long before the redaction.
    //
    // It carries no user id. subject_token is an HMAC over the organisation and
    // the person under the host secret, so two redacted rows about one person
    // still correlate inside one organisation and nobody outside it can reverse
    // the token by trying every user id.
    'org_ledger_redactions' => [
        'columns' => [
            'organisation_id' => 'uint',
            // seat_events | org_membership_events.
            'record_class' => 'string:40',
            'row_id' => 'uint',
            'subject_token' => 'string:64',
            // legal_obligation | legal_claims | contract | none.
            'basis' => ['string:30', 'default' => 'legal_obligation'],
            'statute_ref' => '?string:190',
            // The row's own hash at the moment it was redacted, for a chained
            // ledger, and null for one with no chain to attest.
            'pre_hash' => '?string:64',
            'redacted_at' => 'datetime',
        ],
        'indexes' => [
            ['columns' => ['organisation_id', 'record_class', 'row_id'], 'unique' => true,
                'name' => 'org_ledger_redactions_uq'],
            ['columns' => ['organisation_id', 'subject_token']],
            ['columns' => ['organisation_id', 'redacted_at']],
        ],
        // The row carries its own redacted_at, and a receipt for an append-only
        // operation that also carried an updated_at would be advertising a write
        // nothing may make. The same reasoning admin_access_log states.
        'timestamps' => false,
    ],
    // ── Automations ────────────────────────────────────────────────
    // The free automation engine. A rule is a trigger from the shipped
    // notification trigger registry, a condition tree in the shipped
    // availability grammar on the Automation surface, and an ordered list of
    // actions from one new registry. Nothing here is a second rule language
    // and nothing here is a second mailer: notify delegates to the
    // notification engine's own enqueue.
    // The one store the free action catalogue needs and the free build did not
    // have. set_tag and clear_tag are two of the nine free actions, they are
    // named as reversible and each names the other as its inverse, and the free
    // schema carried no per-learner label anywhere: cohorts and question tags
    // are both Pro tables and neither is about a learner. An action registered
    // with nothing to write is the free-provider rule failing one step further
    // in, so the store is declared here rather than the pair being dropped.
    //
    // It is deliberately small and deliberately not a taxonomy. There is no
    // tag table, no colour, no description and no hierarchy, because the only
    // thing the engine needs is whether this person carries this label, and a
    // vocabulary table would make the tenth label a foreign key rather than a
    // string.
    //
    // The provenance columns are here for loop protection mechanism one, which
    // needs provenance on every write an automation makes: source names the
    // kind of writer and source_ref names the rule and run, so a rule that acts
    // on tags can ignore a change any rule already on the current cascade made
    // rather than only one the same rule made.
    'learner_tags' => [
        'columns' => [
            'user_id' => 'uint',
            'tag' => 'string:100',
            // automation | manual | import. A closed core vocabulary rather
            // than a registry: this names who wrote the row and not how it
            // behaves, and nothing dispatches on it.
            'source' => ['string:20', 'default' => 'automation'],
            // "automation:{ruleId}:{runId}" for a rule's own write.
            'source_ref' => '?string:190',
            'set_by' => '?uint',
        ],
        'indexes' => [
            ['columns' => ['user_id', 'tag'], 'unique' => true, 'name' => 'learner_tags_uq'],
            ['columns' => ['tag']],
        ],
    ],
    'automation_rules' => [
        'columns' => [
            'rule_key' => 'string:100',
            'title' => 'string:255',
            'trigger_key' => 'string:100',
            'definition' => '?json',
            'definition_version' => ['uint', 'default' => 1],
            'conditions' => '?json',
            'conditions_version' => ['uint', 'default' => 1],
            'schedule_kind' => ['string:20', 'default' => 'immediate'],
            'anchor_key' => '?string:60',
            'schedule' => '?json',
            'schedule_version' => ['uint', 'default' => 1],
            'subject_binding' => ['string:20', 'default' => 'learner'],
            // never | on_new_occurrence | always. Declared from version one,
            // because retrofitting re-entry changes every stored rule payload.
            'reentry' => ['string:20', 'default' => 'never'],
            'times_per_subject' => '?uint',
            'total_completions' => '?uint',
            // Non-zero by default, and the builder pre-fills the site setting.
            // Zero was one of the three loop protections being off by default,
            // and a rule whose action set contains emit_event is refused at save
            // when this is zero.
            'cooldown_seconds' => ['uint', 'default' => 300],
            // Refused at save when null on a rule carrying an action that
            // declares DeclaresCost, so an unbounded AI fan-out cannot be armed.
            // No free action declares a cost, so on a free build the refusal has
            // nothing to fire on and both columns stay null.
            'max_ai_runs_per_occurrence' => '?uint',
            'max_ai_runs_per_arming' => '?uint',
            'run_order' => ['uint', 'default' => 100],
            'version' => ['uint', 'default' => 1],
            'active' => ['bool', 'default' => 0],
            'scope_type' => ['string:20', 'default' => 'site'],
            'scope_id' => ['uint', 'default' => 0],
            'course_id' => '?uint',
            'cohort_id' => '?uint',
            'organisation_id' => '?uint',
            // The constitutional columns of this design: the named person whose
            // explicit action every AI run inside this rule executes under, when
            // they armed it, and a SHA-256 over the full ordered action set
            // INCLUDING PARAMS at that moment. Burying them in the definition
            // JSON would make "who authorised this" a JSON path query in an
            // audit. The digest exists because the params carry
            // custom.instruction, the one author_instruction segment in the
            // design, and an edit after arming would otherwise change what every
            // future subject gets while the arming record still named the
            // original act. The fan-out refuses to enqueue an AI step whose
            // digest no longer matches. The prompt ref is NOT here: it is per
            // step, on automation_rule_actions, because a rule may hold three AI
            // steps against three tasks at three prompt versions.
            'armed_by' => '?uint',
            'armed_at' => '?datetime',
            'armed_digest' => '?string:64',
            // disabled | enabled | degraded. Disabled by default, so a rule
            // cannot fire before someone armed it, exactly as
            // notification_rules defaults. degraded is a registry miss on any
            // action, retry policy or failure policy the rule names: the rule
            // does not fan out and the screen names the missing key, so a
            // registry miss is a rule-level condition and the FailurePolicy axis
            // never sees it.
            'state' => ['string:20', 'default' => 'disabled'],
            'last_dry_run_at' => '?datetime',
            'queued_count' => ['uint', 'default' => 0],
            'executed_count' => ['uint', 'default' => 0],
            'suppressed_count' => ['uint', 'default' => 0],
            'failed_count' => ['uint', 'default' => 0],
            'created_by' => '?uint',
        ],
        'indexes' => [
            ['columns' => ['rule_key', 'version'], 'unique' => true, 'name' => 'automation_rules_uq'],
            ['columns' => ['trigger_key', 'state', 'run_order']],
            ['columns' => ['anchor_key']],
            ['columns' => ['course_id']],
            ['columns' => ['cohort_id']],
            ['columns' => ['organisation_id']],
            ['columns' => ['armed_by']],
            ['columns' => ['active']],
        ],
    ],
    'automation_rule_versions' => [
        'columns' => [
            'rule_id' => 'uint',
            'version' => 'uint',
            // mediumtext and not json, for exactly the reason
            // idempotency_keys.outcome is: this holds the authored document a
            // running instance is still executing against and it must come back
            // byte-identical. MySQL's JSON type normalises and reorders keys, so
            // a replayed version came back written differently. Nothing queries
            // into this column.
            'payload' => 'mediumtext',
            'payload_version' => ['uint', 'default' => 1],
            'created_by' => 'uint',
            'activated_at' => '?datetime',
            'retired_at' => '?datetime',
        ],
        'indexes' => [
            ['columns' => ['rule_id', 'version'], 'unique' => true, 'name' => 'automation_rule_versions_uq'],
            ['columns' => ['rule_id', 'activated_at']],
        ],
    ],
    // A child table rather than a JSON array on the rule, for exactly the
    // reason notification_rule_channels is one: "which rules use the webhook
    // action" and "which rules break if the Pro package is removed" are asked
    // from outside the rule, and a delimited or JSON column answers them with a
    // LIKE scan.
    'automation_rule_actions' => [
        'columns' => [
            'rule_id' => 'uint',
            // Rows are copied per version. Without it the unique is
            // (rule_id, step_index) with no version and no updated_at, so an
            // armed rule's action params could be edited afterwards with nothing
            // to detect the edit against, and automation_rule_versions.payload
            // holds the authored document only for an in-flight run while the
            // binned fan-out resolves steps at fan-out time.
            'rule_version' => ['uint', 'default' => 1],
            'step_index' => 'uint',
            'action_key' => 'string:100',
            'params' => '?json',
            'params_version' => ['uint', 'default' => 1],
            'guard_conditions' => '?json',
            'guard_conditions_version' => ['uint', 'default' => 1],
            'delay_anchor_key' => '?string:60',
            'delay' => '?json',
            'delay_version' => ['uint', 'default' => 1],
            'retry_policy_key' => ['string:40', 'default' => 'external_default'],
            'failure_policy_key' => ['string:40', 'default' => 'park'],
            // Two separate facts, because they were one capability doing two
            // jobs: whether a named person releases the step BEFORE its effect,
            // and whether the outcome is parked for a named person AFTER it.
            'requires_approval_before_effect' => ['bool', 'default' => 0],
            'produces_review_item' => ['bool', 'default' => 0],
            // The prompt version in force when this step was armed, stamped per
            // step rather than per rule.
            'armed_prompt_ref' => '?string:190',
            'owner_user_id' => '?uint',
        ],
        'indexes' => [
            [
                'columns' => ['rule_id', 'rule_version', 'step_index'],
                'unique' => true,
                'name' => 'automation_rule_actions_uq',
            ],
            ['columns' => ['action_key']],
            ['columns' => ['requires_approval_before_effect']],
            ['columns' => ['owner_user_id']],
        ],
        'timestamps' => false,
    ],
    'automation_runs' => [
        'columns' => [
            'rule_id' => 'uint',
            'rule_version' => 'uint',
            'trigger_key' => 'string:100',
            'event_ref' => 'string:190',
            'occurrence_key' => 'string:64',
            'subject_type' => '?string:20',
            'subject_id' => '?uint',
            // The person the occurrence is about, which is not always the
            // subject. The entitlement expiring sweep yields a learner as the
            // recipient and the course as the subject, and with no column here
            // the recipient was lost at the outbox write: notify then read the
            // subject id as a user id and queued a learner's expiry notice to
            // whoever held that integer. Null means the subject is the only
            // person the occurrence names.
            'recipient_user_id' => '?uint',
            // The rule's authoriser, never a literal system user. The audit gap
            // the idempotency literature names is a step gated under one
            // business identity and completed under another.
            'actor_user_id' => '?uint',
            'dry_run' => ['bool', 'default' => 0],
            'state' => ['string:20', 'default' => 'pending_fanout'],
            'cascade_depth' => ['uint', 'default' => 0],
            // The ordered rule ids already on this cascade, inherited through
            // the event envelope an emit_event action stamps. The default event
            // trigger ignores changes whose actor is any rule on the chain, not
            // only the same rule, because a two-rule cycle is invisible to a
            // same-rule filter.
            'rule_chain' => '?string:190',
            'matched_count' => ['uint', 'default' => 0],
            'executed_count' => ['uint', 'default' => 0],
            'skipped_count' => ['uint', 'default' => 0],
            'failed_count' => ['uint', 'default' => 0],
            'log' => '?mediumtext',
            'started_at' => '?datetime',
            'finished_at' => '?datetime',
        ],
        'indexes' => [
            [
                'columns' => ['rule_id', 'occurrence_key', 'subject_type', 'subject_id', 'dry_run'],
                'unique' => true,
                'name' => 'automation_runs_occurrence_uq',
            ],
            ['columns' => ['state', 'created_at']],
            ['columns' => ['rule_id', 'created_at']],
            ['columns' => ['event_ref']],
        ],
    ],
    // notification_queue's shape with action_key where channel_key was and no
    // recipient, and the reasoning transfers line for line. The claim triple
    // exists because both hosts are documented multi-firers, so two runners
    // over one pending row is the normal case and a dedupe digest deduplicates
    // rows rather than effects.
    'automation_queue' => [
        'columns' => [
            'run_id' => 'uint',
            'rule_id' => 'uint',
            'rule_version' => ['uint', 'default' => 1],
            'step_index' => 'uint',
            'action_key' => 'string:100',
            'subject_type' => '?string:20',
            'subject_id' => '?uint',
            // Carried down from the run, so an action that acts for a person
            // reads the person rather than falling back to the subject id.
            'recipient_user_id' => '?uint',
            'params' => '?json',
            'params_version' => ['uint', 'default' => 1],
            'occurrence_key' => 'string:64',
            // SHA-256 over rule_id|rule_version|run_id|step_index|action_key|
            // subject_type|subject_id|occurrence_key|dry_run, unique on the
            // digest alone, for the reason notification_queue already states
            // about the 767-byte key limit under utf8mb4. dry_run is in the
            // digest so a preview and a real execution of one step are two rows.
            'dedupe_key' => 'string:64',
            // The same digest the idempotency_keys row is claimed under, so the
            // ledger and the queue agree on what one effect is.
            'idem_key' => 'string:190',
            'run_at' => 'datetime',
            // pending | awaiting_approval | claimed | executing | executed |
            // deferred | suppressed | parked | failed_permanent | cancelled.
            // awaiting_approval is where a step whose action declares
            // RequiresApprovalBeforeEffect waits for a named person to release
            // it, which for ai.prepare_draft is BEFORE the model is called.
            'state' => ['string:20', 'default' => 'pending'],
            'claim_token' => '?string:64',
            'claimed_at' => '?datetime',
            'lease_expires_at' => '?datetime',
            'attempts' => ['uint', 'default' => 0],
            'next_attempt_at' => '?datetime',
            'cascade_depth' => ['uint', 'default' => 0],
            'dry_run' => ['bool', 'default' => 0],
            'suppressed_reason' => '?string:100',
            'last_error' => '?text',
            'ai_run_id' => '?uint',
            'executed_at' => '?datetime',
        ],
        'indexes' => [
            ['columns' => ['dedupe_key'], 'unique' => true, 'name' => 'automation_queue_dedupe_uq'],
            ['columns' => ['state', 'run_at']],
            ['columns' => ['state', 'lease_expires_at']],
            ['columns' => ['run_id', 'step_index']],
            ['columns' => ['rule_id', 'suppressed_reason']],
            ['columns' => ['action_key', 'state']],
            ['columns' => ['subject_type', 'subject_id', 'action_key', 'state']],
            ['columns' => ['ai_run_id']],
        ],
    ],
    // The audit, retained past the queue's pruning. The idempotency guarantee
    // is NOT carried here: it is carried by idempotency_keys under scope
    // automation.action, with its existing unique index and its outcome column.
    // Splitting them is deliberate: the ledger has to be written before the
    // side effect inside a small transaction, and the audit carries before and
    // after state that the ledger has no business holding. idem_key here is a
    // lookup and never a constraint.
    'automation_action_log' => [
        'columns' => [
            'queue_id' => '?uint',
            'run_id' => '?uint',
            'rule_id' => '?uint',
            'rule_version' => ['uint', 'default' => 1],
            'step_index' => ['uint', 'default' => 0],
            'action_key' => 'string:100',
            'subject_type' => '?string:20',
            'subject_id' => '?uint',
            'actor_user_id' => '?uint',
            'idem_key' => 'string:190',
            'success' => ['bool', 'default' => 0],
            'before_state' => '?json',
            'after_state' => '?json',
            'state_version' => ['uint', 'default' => 1],
            'error_code' => '?string:40',
            'error_message' => '?text',
            'ai_run_id' => '?uint',
            'executed_at' => 'datetime',
        ],
        'indexes' => [
            ['columns' => ['rule_id', 'executed_at']],
            ['columns' => ['subject_type', 'subject_id', 'executed_at']],
            ['columns' => ['action_key', 'success']],
            ['columns' => ['idem_key']],
            ['columns' => ['ai_run_id']],
        ],
    ],
    // Salesforce Flow Tests as rows: an initial subject state, a changed
    // subject state, and expectations written in the availability grammar on
    // the Automation surface plus the small closed set of engine assertions the
    // tree cannot express. last_result is real because the rules screen shows a
    // failing-test badge beside a rule and a JSON scan cannot drive a list
    // badge. rule_version is real because a test written against version 2 must
    // not silently claim to cover version 3.
    'automation_rule_tests' => [
        'columns' => [
            'rule_id' => 'uint',
            'rule_version' => ['uint', 'default' => 1],
            'name' => 'string:190',
            'given' => 'json',
            'given_version' => ['uint', 'default' => 1],
            'changed' => 'json',
            'changed_version' => ['uint', 'default' => 1],
            'expectations' => 'json',
            'expectations_version' => ['uint', 'default' => 1],
            'last_run_at' => '?datetime',
            'last_result' => '?string:20',
            'last_detail' => '?mediumtext',
            'created_by' => 'uint',
        ],
        'indexes' => [
            ['columns' => ['rule_id', 'name'], 'unique' => true, 'name' => 'automation_rule_tests_uq'],
            ['columns' => ['rule_id', 'last_result']],
        ],
    ],
    // ── The AI spine ──────────────────────────────────────────────────────
    //
    // Twelve free tables. Every one of them is free for the reason the admin
    // design canvas establishes with its orphaned-Pro-data state: drafts and
    // runs left behind by an uninstalled Pro package must still render, and a
    // free table read by a free code path is the only way that is true.
    //
    // ai_chunk_vectors is deliberately not here. It is written by Pro only and
    // read only by the embedding-backed retriever, so it lands with the model
    // access stage rather than as an empty table nothing free can fill.
    //
    // There is no ai_score, no ai_confidence and no ai_risk column anywhere
    // below, on a run, a draft, a finding or a provenance row. The absence is
    // the statement: adding one has to be a migration somebody argues for,
    // rather than setting a value on a field that was quietly waiting.
    'ai_routes' => [
        'columns' => [
            'task_key' => 'string:100',
            'bridge_key' => 'string:100',
            'model' => '?string:190',
            // (source, id, version). Version 0 with a literal unversioned note
            // when the bridge cannot report one, which is a visible gap rather
            // than a fabricated number.
            'prompt_ref' => '?string:190',
            'params' => '?json',
            'params_version' => ['uint', 'default' => 1],
            'position' => ['uint', 'default' => 0],
            // 0 by default, so a route created by an import or a provisioning
            // script is inert until a person turns it on.
            'enabled' => ['bool', 'default' => 0],
            // site | course | cohort | organisation.
            'scope_type' => ['string:20', 'default' => 'site'],
            'scope_id' => ['uint', 'default' => 0],
            // The per-run ceiling this route declares, in the cost unit the
            // bridge declares, read when the task does not implement
            // EstimatesCost. ZERO MEANS REFUSE: a route with no declared
            // per-run ceiling may not be selected, so a provisioning import
            // cannot create an uncapped route. There is deliberately no value
            // meaning unlimited, because this is the figure the per-run
            // ceiling compares against and a ceiling whose default is off is
            // not a ceiling.
            'max_cost_per_run' => ['uint', 'default' => 0],
            'updated_by' => '?uint',
        ],
        'indexes' => [
            ['columns' => ['task_key', 'scope_type', 'scope_id', 'position'], 'name' => 'ai_routes_chain_idx'],
            ['columns' => ['bridge_key']],
            ['columns' => ['enabled']],
        ],
    ],
    // The versioned data-use policy. The version column is the departure from
    // Moodle's ai_policy_register, and it is the difference between a consent
    // record and a consent claim: a changed policy forces re-acceptance and
    // every run row can say which policy was in force.
    'ai_policies' => [
        'columns' => [
            'version' => 'uint',
            'title' => 'string:190',
            'body' => 'mediumtext',
            'locale' => ['string:20', 'default' => 'en-GB'],
            // strict | pseudonymise | organisation, the AiRedactor provider key.
            'redaction_level' => ['string:20', 'default' => 'strict'],
            // Floor of 180 enforced in the write path, never in the column.
            'retention_days' => ['uint', 'default' => 400],
            'training_reuse' => ['string:30', 'default' => 'declined'],
            'enabled_tasks' => '?json',
            'enabled_tasks_version' => ['uint', 'default' => 1],
            'body_hash' => 'string:64',
            'effective_from' => 'datetime',
            'state' => ['string:20', 'default' => 'draft'],
            'created_by' => 'uint',
        ],
        'indexes' => [
            ['columns' => ['version'], 'unique' => true],
            ['columns' => ['state', 'effective_from']],
        ],
    ],
    'ai_policy_acceptances' => [
        'columns' => [
            'user_id' => 'uint',
            'policy_version' => 'uint',
            'scope_type' => ['string:20', 'default' => 'site'],
            'scope_id' => ['uint', 'default' => 0],
            // Where the person first met it. Article 50(5) requires disclosure
            // at first interaction, and the site owner needs to know where.
            'placement_key' => '?string:100',
            'accepted_at' => 'datetime',
            'evidence' => '?json',
            'evidence_version' => ['uint', 'default' => 1],
        ],
        'indexes' => [
            [
                'columns' => ['user_id', 'policy_version', 'scope_type', 'scope_id'],
                'unique' => true,
                'name' => 'ai_policy_acceptances_uq',
            ],
            ['columns' => ['policy_version']],
        ],
    ],
    // The ledger. One row per invocation, written before the bridge is called,
    // in state reserved, and updated by the outcome. A run row exists for every
    // run including refused and previewed ones, which is what makes the
    // learner's right-to-know view and the dry run buildable at all.
    'ai_runs' => [
        'columns' => [
            'run_key' => 'string:64',
            'job_id' => '?uint',
            'task_key' => 'string:100',
            'task_version' => ['uint', 'default' => 1],
            'placement_key' => '?string:100',
            'route_id' => '?uint',
            'bridge_key' => ['string:100', 'default' => 'null'],
            'model' => '?string:190',
            'prompt_ref' => '?string:190',
            'policy_version' => ['uint', 'default' => 0],
            // Who clicked.
            'principal_user_id' => '?uint',
            // person | automation. Real, because "show me every run an
            // automation started" is the first question asked after a rule
            // misfires, and deriving it from rule_id being non-null is the
            // kind of implicit encoding that breaks the day a person runs a
            // rule by hand.
            'actor_kind' => ['string:20', 'default' => 'person'],
            'rule_id' => '?uint',
            // Whose data was sent. Separate from the principal because the
            // learner is not the person who clicked, and the right-to-know
            // view filters on this one while the audit filters on the other.
            // It is a denormalisation of the single-subject case; the record
            // is ai_run_subjects.
            'on_behalf_of_user_id' => '?uint',
            'subject_type' => '?string:20',
            'subject_id' => '?uint',
            'course_id' => '?uint',
            'organisation_id' => '?uint',
            'locale' => ['string:20', 'default' => 'en-GB'],
            'inputs_digest' => 'string:64',
            'redaction_manifest' => '?json',
            'redaction_manifest_version' => ['uint', 'default' => 1],
            'dry_run' => ['bool', 'default' => 0],
            // reserved | running | succeeded | failed | refused | previewed |
            // cancelled. An unenumerated state is a stalled queue.
            'state' => ['string:20', 'default' => 'reserved'],
            'refusal_kind' => '?string:40',
            'error_code' => '?string:40',
            'error_message' => '?text',
            // Null rather than zero when the bridge does not declare
            // ReportsUsage, because unknown is honest and zero is a lie.
            'tokens_in' => '?uint',
            'tokens_out' => '?uint',
            // provider_units | tokens | usd_micros | none, declared by the
            // bridge. The core does not know what a provider's own unit is; it
            // knows what a unit is, and a windowed ceiling only ever sums one
            // unit at a time.
            'cost_unit' => ['string:20', 'default' => 'none'],
            // Written before the bridge is called, INSIDE THE SAME TRANSACTION
            // as the ceiling evaluation. The windowed ceilings read
            // GREATEST(cost_charged, cost_reserved) over states reserved,
            // running, succeeded and failed, because a ceiling that reads only
            // the settled figure passes every unit of a fan-out on the same
            // unsettled zero.
            'cost_reserved' => '?uint',
            // Null and not zero when the bridge does not declare ReportsUsage,
            // for the same reason the token columns are nullable.
            'cost_charged' => '?uint',
            // The model ran and the ledger did not settle. A different fact
            // from a free run, which is why it is a flag rather than a zero.
            'unbilled' => ['bool', 'default' => 0],
            // Three valued on purpose: not checked and checked-and-failed are
            // different facts.
            'schema_valid' => '?bool',
            'provider_request_id' => '?string:190',
            'provider_fingerprint' => '?string:190',
            'finish_reason' => '?string:40',
            'duration_ms' => '?uint',
            'started_at' => '?datetime',
            'completed_at' => '?datetime',
            'settled_at' => '?datetime',
            'retain_until' => 'datetime',
        ],
        'indexes' => [
            ['columns' => ['run_key'], 'unique' => true],
            ['columns' => ['task_key', 'state', 'created_at']],
            // The ceiling window: unsettled rows are in it, so it is scanned
            // by state and unit rather than by charged amount alone.
            ['columns' => ['state', 'cost_unit', 'created_at']],
            ['columns' => ['placement_key', 'created_at']],
            ['columns' => ['principal_user_id', 'created_at']],
            ['columns' => ['on_behalf_of_user_id', 'created_at']],
            ['columns' => ['subject_type', 'subject_id']],
            ['columns' => ['course_id', 'created_at']],
            ['columns' => ['organisation_id', 'created_at']],
            ['columns' => ['provider_request_id']],
            ['columns' => ['rule_id', 'actor_kind']],
            ['columns' => ['retain_until']],
        ],
    ],
    // One row per candidate attempted, written BEFORE the success test. A JSON
    // array on the run answers "which route is failing this week" with a full
    // scan, and one run row per attempt makes winners and losers
    // indistinguishable without a self join.
    'ai_run_attempts' => [
        'columns' => [
            'run_id' => 'uint',
            'attempt_no' => 'uint',
            'route_id' => '?uint',
            // Where in the route chain this candidate sat. The record carried
            // it and the row had nowhere to put it, so "the second route
            // answered after the first refused" was unreadable from the
            // ledger.
            'position' => ['uint', 'default' => 0],
            'bridge_key' => 'string:100',
            'model' => '?string:190',
            'success' => ['bool', 'default' => 0],
            'refusal_kind' => '?string:40',
            'error_code' => '?string:40',
            'error_message' => '?text',
            // The upstream's own reference for this call, which is what a
            // billing dispute is argued with. ai_runs has one and the attempt
            // rows, which are the ones that failed, did not.
            'provider_request_id' => '?string:190',
            // Whether this exact call may be sent again. It depends on a
            // capability of the bridge that answered and the row outlives the
            // object that knew it, which is why the record carries it rather
            // than a later reader recomputing it.
            'auto_retry_safe' => ['bool', 'default' => 0],
            // The schema keywords the route did not enforce. They used to be
            // written into error_message, so a SUCCEEDED attempt carried a
            // non-null error message and the item review screen had no list to
            // render beside its schema_unenforced flag.
            'dropped_constraints' => '?json',
            'dropped_constraints_version' => ['uint', 'default' => 1],
            'tokens_in' => '?uint',
            'tokens_out' => '?uint',
            'cost_unit' => ['string:20', 'default' => 'none'],
            'cost_charged' => '?uint',
            'latency_ms' => '?uint',
            'started_at' => 'datetime',
            'completed_at' => '?datetime',
        ],
        'indexes' => [
            ['columns' => ['run_id', 'attempt_no'], 'unique' => true, 'name' => 'ai_run_attempts_uq'],
            ['columns' => ['bridge_key', 'success', 'started_at']],
            ['columns' => ['route_id', 'success']],
        ],
        'timestamps' => false,
    ],
    // What GET /v1/me/ai-activity reads. One nullable column on the run answers
    // only the single-subject case, and the runs that touch the most learners
    // are exactly the multi-subject ones.
    'ai_run_subjects' => [
        'columns' => [
            'run_id' => 'uint',
            'user_id' => 'uint',
            // subject | contributor. A subject is a person whose data was
            // sent; a contributor is a person whose authored material was.
            'role' => ['string:20', 'default' => 'subject'],
        ],
        'indexes' => [
            ['columns' => ['run_id', 'user_id'], 'unique' => true, 'name' => 'ai_run_subjects_uq'],
            ['columns' => ['user_id', 'run_id']],
        ],
        'timestamps' => false,
    ],
    // A draft is NOT a row in questions with a flag on it. A generated
    // definition contains correct answers by construction, and a flag on a live
    // row is one forgotten condition away from a leak, so a draft lives here
    // and is reachable only under TEACH.
    'ai_drafts' => [
        'columns' => [
            'run_id' => 'uint',
            'draft_kind' => 'string:60',
            'target_extension_point' => '?string:50',
            'target_provider_key' => '?string:100',
            'subject_type' => '?string:20',
            'subject_id' => '?uint',
            'course_id' => '?uint',
            // What makes a course build a tree a reviewer accepts module by
            // module, rather than one payload accepted or discarded whole.
            'parent_draft_id' => '?uint',
            'ordinal' => ['uint', 'default' => 0],
            'payload' => 'json',
            'payload_version' => ['uint', 'default' => 1],
            'edited_payload' => '?json',
            'edited_payload_version' => ['uint', 'default' => 1],
            'citations' => '?json',
            'citations_version' => ['uint', 'default' => 1],
            // Its own column precisely so it can never be mistaken for the
            // empirical p-value the stratified composition reads.
            'predicted_difficulty' => '?decimal:5,3',
            'predicted_difficulty_method' => '?string:60',
            // requested | generated | checked | in_review | accepted |
            // discarded, with rejected returning to generated.
            'state' => ['string:20', 'default' => 'generated'],
            'findings_blocking' => ['uint', 'default' => 0],
            'findings_advisory' => ['uint', 'default' => 0],
            'citation_coverage' => '?uint',
            'reviewer_user_id' => '?uint',
            'review_started_at' => '?datetime',
            'decided_at' => '?datetime',
            'reject_reason_code' => '?string:60',
            'reject_reason' => '?text',
            'accepted_by' => '?uint',
            'accepted_at' => '?datetime',
            'edit_distance_ratio' => '?decimal:5,4',
            'disclosure_tier' => '?string:40',
            'created_entity_type' => '?string:30',
            'created_entity_id' => '?uint',
            // NOT nullable, set from the active policy at insert and refreshed
            // on every state change. Setting it only at a terminal state left
            // every draft in requested, generated, checked or in_review
            // matching no purge predicate at all, which is exactly the set
            // nobody decided about, surviving instead on the run's 400-day
            // clock.
            'retain_until' => 'datetime',
        ],
        'indexes' => [
            ['columns' => ['run_id', 'ordinal']],
            ['columns' => ['state', 'draft_kind']],
            ['columns' => ['course_id', 'state']],
            ['columns' => ['reviewer_user_id', 'state']],
            ['columns' => ['subject_type', 'subject_id']],
            ['columns' => ['created_entity_type', 'created_entity_id']],
            ['columns' => ['target_extension_point', 'target_provider_key']],
            ['columns' => ['parent_draft_id']],
            ['columns' => ['retain_until']],
        ],
    ],
    // A table rather than a JSON list on the draft, because findings are read
    // from outside it: "which check is producing the most noise" is the
    // question that tunes the pipeline and a JSON column answers it with a
    // scan.
    'ai_draft_findings' => [
        'columns' => [
            'draft_id' => 'uint',
            'check_key' => 'string:60',
            // blocking | advisory | info.
            'severity' => ['string:20', 'default' => 'advisory'],
            'code' => 'string:60',
            // A JSON pointer into the payload, so the review UI can highlight
            // the offending text rather than making the reviewer hunt for it.
            'path' => '?string:190',
            'message' => 'text',
            'evidence' => '?json',
            'evidence_version' => ['uint', 'default' => 1],
            'acknowledged' => ['bool', 'default' => 0],
            'acknowledged_by' => '?uint',
        ],
        'indexes' => [
            ['columns' => ['draft_id', 'severity']],
            ['columns' => ['check_key', 'code']],
            ['columns' => ['severity', 'acknowledged']],
        ],
    ],
    // The record that survives. Polymorphic in its owner exactly as
    // availability_rule_refs is, written inside the same transactional() call
    // as the artefact, and EXCLUDED FROM THE RETENTION PURGE: an
    // editorial-responsibility record whose task and model vanished after 180
    // days is not a record, which is why the task, the model and the ratio are
    // denormalised here rather than joined from the run.
    'ai_artefact_provenance' => [
        'columns' => [
            'subject_type' => 'string:30',
            'subject_id' => 'uint',
            'run_id' => 'uint',
            'draft_id' => '?uint',
            'task_key' => 'string:100',
            'task_version' => ['uint', 'default' => 1],
            'prompt_ref' => '?string:190',
            'model' => '?string:190',
            'inputs_digest' => 'string:64',
            'disclosure_tier' => 'string:40',
            // Denormalised here as well as on the draft, because the draft
            // payload is purged at 90 days and this is the rubber-stamp metric
            // the site owner is shown beside the spend. A tier without the
            // ratio gives the oversight measurement a shorter memory than the
            // compliance record it sits beside.
            'edit_distance_ratio' => '?decimal:5,4',
            'human_edited' => ['bool', 'default' => 0],
            'accepted_by' => 'uint',
            'accepted_at' => 'datetime',
            'superseded_at' => '?datetime',
        ],
        'indexes' => [
            [
                'columns' => ['subject_type', 'subject_id', 'run_id'],
                'unique' => true,
                'name' => 'ai_artefact_provenance_uq',
            ],
            ['columns' => ['subject_type', 'subject_id']],
            ['columns' => ['run_id']],
            ['columns' => ['task_key']],
            ['columns' => ['accepted_by', 'accepted_at']],
            ['columns' => ['disclosure_tier']],
        ],
        'timestamps' => false,
    ],
    // The grounding index. chunk_key is a hash rather than an autoincrement
    // because citation stability across a re-index is the whole point: a
    // citation stored on a draft in March still resolves in June.
    'ai_grounding_chunks' => [
        'columns' => [
            'subject_type' => 'string:20',
            'subject_id' => 'uint',
            'course_id' => 'uint',
            'ordinal' => 'uint',
            'content_hash' => 'string:64',
            // sha256(subject_type|subject_id|ordinal|content_hash).
            'chunk_key' => 'string:64',
            // detail | lesson_summary | module_summary.
            'level' => ['string:20', 'default' => 'detail'],
            'parent_id' => '?uint',
            'locale' => '?string:20',
            'token_count' => ['uint', 'default' => 0],
            'text' => 'mediumtext',
            // untrusted | trusted_instruction. A real column and not an
            // inference from subject_type, because the segment renderer
            // filters on it and a CI test asserts that nothing an importer
            // wrote is ever tagged trusted_instruction. The assertion needs a
            // column to read.
            'trust' => ['string:20', 'default' => 'untrusted'],
            'indexed_at' => 'datetime',
        ],
        'indexes' => [
            ['columns' => ['chunk_key'], 'unique' => true],
            ['columns' => ['subject_type', 'subject_id', 'ordinal'], 'name' => 'ai_grounding_chunks_src_idx'],
            ['columns' => ['course_id', 'level']],
            ['columns' => ['content_hash']],
            ['columns' => ['trust']],
        ],
    ],
    // The job model is import_jobs, copied rather than reinvented, with two
    // states import does not have. deferred is what a ceiling policy produces,
    // because a two hundred item build that trips the monthly ceiling at item
    // forty is not a failure; cancelled is the Article 14 stop, which must not
    // be filed as an ordinary breakage.
    //
    // There is no heartbeat column, for the same reason import_jobs has none:
    // the progress write moves updated_at, and a second callback moves it
    // alone so a silent chunking phase is not read as a dead process.
    'ai_jobs' => [
        'columns' => [
            // Exactly one of task_key and recipe_key is set. A single-task job
            // names the task; a multi-step build names a recipe, because one
            // job row holding one task key cannot express three task keys.
            'task_key' => '?string:100',
            'recipe_key' => '?string:100',
            'step_index' => ['uint', 'default' => 0],
            'placement_key' => '?string:100',
            // pending | running | completed | previewed | deferred | failed |
            // cancelled.
            'status' => ['string:20', 'default' => 'pending'],
            'scope_type' => ['string:20', 'default' => 'course'],
            'scope_id' => 'uint',
            'organisation_id' => '?uint',
            'options' => '?json',
            'options_version' => ['uint', 'default' => 1],
            'dry_run' => ['bool', 'default' => 0],
            'cost_unit' => ['string:20', 'default' => 'none'],
            'cost_reserved' => '?uint',
            // Real and written even on a failed job: an import that fails costs
            // nothing, an AI job that fails has already paid the upstream
            // tokens, and a job log that says only "failed" leaves a site owner
            // unable to explain their bill.
            'cost_charged' => '?uint',
            'log' => '?mediumtext',
            'totals' => '?json',
            // Provider-shaped, because AiRunProgress::counted() lets any task
            // name its own counters, so the keys are not engine owned.
            'totals_version' => ['uint', 'default' => 1],
            // Set when a ceiling policy defers the job; the scheduled task
            // re-arms itself under the same dedupe key, so a resume is one row
            // rather than a second job.
            'resume_at' => '?datetime',
            'started_at' => '?datetime',
            'finished_at' => '?datetime',
            'created_by' => 'uint',
        ],
        'indexes' => [
            ['columns' => ['status']],
            ['columns' => ['created_by', 'created_at']],
            ['columns' => ['task_key', 'status']],
            ['columns' => ['organisation_id', 'created_at']],
        ],
    ],
    // import_map's analogue, and for the same reason: it is what makes a
    // resumed or re-run build an update instead of a second set of drafts, and
    // read the other way it answers which syllabus section a generated module
    // came from.
    //
    // The uniqueness is carried on a digest rather than on the natural triple.
    // (job_id, source_type, source_key) is 964 bytes under utf8mb4, past the
    // 767-byte key limit of the legacy COMPACT and REDUNDANT InnoDB row
    // formats, and the schema's own refusal names the remedy: carry the
    // uniqueness on a string:64 SHA-256 digest and keep the natural columns on
    // a narrower plain index. import_map predates that rule and is grandfathered
    // by name; nothing may be added to that list, so this table takes the
    // treatment entitlements.source_ref_digest already uses.
    'ai_job_map' => [
        'columns' => [
            'job_id' => 'uint',
            'source_type' => 'string:50',
            'source_key' => 'string:190',
            // sha256(source_type|source_key), the uniqueness carrier.
            'source_digest' => 'string:64',
            'local_type' => 'string:50',
            'local_id' => 'uint',
        ],
        'indexes' => [
            ['columns' => ['job_id', 'source_digest'], 'unique' => true, 'name' => 'ai_job_map_uq'],
            ['columns' => ['job_id', 'source_type']],
            ['columns' => ['local_type', 'local_id']],
        ],
        'timestamps' => false,
    ],
];
