<?php

/**
 * The neutral VidyaLMS string catalogue, v1.
 *
 * Authored once here; compiled to Joomla INI files and WordPress gettext at
 * build time by tooling/i18n/compile.php. Keys are the TranslatorPort keys
 * used by the core; parameters use {name} placeholders on both platforms.
 *
 * Grouping is by feature; the compiler flattens it.
 */

declare(strict_types=1);

return [
    'vidyalms.automation.action.enrol' => 'Enrol in a course',
    'vidyalms.automation.action.unenrol' => 'Remove access granted by an automation',
    'vidyalms.automation.action.add_to_cohort' => 'Add to a cohort',
    'vidyalms.automation.action.remove_from_cohort' => 'Remove from a cohort',
    'vidyalms.automation.action.emit_event' => 'Send an automation event',
    'vidyalms.automation.action.create_review_task' => 'Create a review task',
    'vidyalms.automation.action.set_tag' => 'Add a learner tag',
    'vidyalms.automation.action.clear_tag' => 'Remove a learner tag',
    'vidyalms.automation.action.notify' => 'Send a notification',
    'vidyalms.automation.sentence.enrol' => 'Enrol the learner in the selected course.',
    'vidyalms.automation.sentence.unenrol' => 'Remove access granted by the selected automation rule. Other access remains valid.',
    'vidyalms.automation.sentence.cohort' => 'Update the learner membership in the selected cohort.',
    'vidyalms.automation.sentence.emit_event' => 'Send the selected event for this learner.',
    'vidyalms.automation.sentence.create_review_task' => 'Create a task for a person to review.',
    'vidyalms.automation.sentence.tag' => 'Update the selected learner tag.',
    'vidyalms.automation.sentence.notify' => 'Send the selected notification to the learner.',
    'vidyalms.automation.failure.continue' => 'Continue with the next step',
    'vidyalms.automation.failure.error_branch' => 'Follow the error branch',
    'vidyalms.automation.failure.halt' => 'Stop this run',
    'vidyalms.automation.failure.park' => 'Pause for review',
    'vidyalms.automation.retry.gentle' => 'Retry gradually',
    'vidyalms.automation.retry.external_default' => 'Retry temporary service failures',
    'vidyalms.automation.retry.none' => 'Do not retry',
    'vidyalms.notification.footer.reason' => 'You are receiving this learning notification based on your notification settings.',
    'vidyalms.notification.footer.manage' => 'Manage or unsubscribe from notifications',
    'vidyalms.quiz.opens_at_utc' => 'Opens at (UTC)',
    'vidyalms.quiz.unattached_opening_help' => 'A quiz without a lesson stays closed until you set an opening date.',
    'vidyalms.report.assignment-history' => 'Assignment submission history',
    'vidyalms.report.active-learning-time' => 'Active learning time (cumulative)',
    'vidyalms.report.training-activity' => 'Training activity and sign-ins',
    'vidyalms.evidence.id' => 'Record',
    'vidyalms.evidence.user_id' => 'Learner ID',
    'vidyalms.evidence.learner' => 'Learner',
    'vidyalms.evidence.course_id' => 'Course ID',
    'vidyalms.evidence.lesson_id' => 'Lesson ID',
    'vidyalms.evidence.lesson' => 'Lesson',
    'vidyalms.evidence.attempt_number' => 'Attempt',
    'vidyalms.evidence.status' => 'Status',
    'vidyalms.evidence.submitted_at' => 'Submitted at (UTC)',
    'vidyalms.evidence.graded_at' => 'Graded at (UTC)',
    'vidyalms.evidence.grader_id' => 'Instructor ID',
    'vidyalms.evidence.points_awarded' => 'Awarded points',
    'vidyalms.evidence.feedback' => 'Instructor feedback',
    'vidyalms.evidence.assessment' => 'Decision and detailed rubric (JSON)',
    'vidyalms.evidence.files' => 'Uploaded files (JSON)',
    'vidyalms.evidence.module_id' => 'Module ID',
    'vidyalms.evidence.module' => 'Module',
    'vidyalms.evidence.seconds_spent' => 'Active learning seconds',
    'vidyalms.evidence.first_accessed_at' => 'First opened (UTC)',
    'vidyalms.evidence.last_heartbeat_at' => 'Last active (UTC)',
    'vidyalms.evidence.completed_at' => 'Completed at (UTC)',
    'vidyalms.evidence.action' => 'Activity',
    'vidyalms.evidence.occurred_at' => 'Occurred at (UTC)',
    'vidyalms.evidence.context' => 'Activity details (JSON)',

    'vidyalms.token.invitation' => 'Private organisation invitation link',
    'org.role.org_owner' => 'Owner',
    'org.role.org_admin' => 'Administrator',
    'org.role.org_reporter' => 'Reporter',
    'org.role.org_member' => 'Member',
    // Organisation types are a label and never a gate, so an unresolved one
    // renders as its own key rather than blocking the read.
    'org.type.organisation' => 'Organisation',
    'org.type.department' => 'Department',
    'org.type.team' => 'Team',
    'org.type.location' => 'Location',
    'org.type.division' => 'Division',
    'org.type.school' => 'School',
    'org.type.district' => 'District',
    'org.type.local' => 'Local',
    'org.type.state' => 'State',
    'org.type.national' => 'National',
    'org.type.company' => 'Company',
    'org.join_rule.invitation_only' => 'Invitation only',
    // The two free catalogue sources, and the two lines the copy deck fixes
    // for the catalogue. The orphaned-source line names the organisation as
    // intact on purpose: an uninstalled paid package removes a list, not a
    // customer, and a screen that implied otherwise would send an
    // administrator looking for people who never went anywhere.
    'org.catalogue_source.course' => 'One course',
    'org.catalogue_source.unit_subtree' => 'Every course assigned to a unit and its descendants',
    'org.catalogue.source_not_installed' => 'This organisation\'s course list came from a source that is not '
        . 'installed on this site. The organisation and its members are intact.',
    'org.catalogue.course_not_released' => 'This course has not been released to {organisation}. Ask the site '
        . 'owner to release it, then add it here.',
    // The free compliance answer, stated rather than left blank. No rule is
    // registered, so a requirement is recorded and nothing reports anybody
    // compliant; a screen showing an empty due date without this line reads
    // as a bug rather than as the decision it is.
    'org.compliance.untracked' => 'Assigned, and not tracked. No installed rule sets a due date for this.',
    // The four notes the compliance seam writes, which reached no human at all
    // before this: the registry and both rule families wrote them, nothing
    // stored them, nothing returned them and none of them was in a catalogue,
    // so a site whose rule package had been uninstalled saw a status word with
    // no reason. Two of the four carry the rule key after a colon, so the
    // stored note is the key below plus ':' plus that key, and a renderer
    // splits on the colon before it looks the line up.
    'vidyalms.compliance.no_rule' => 'No rule tracks this requirement, so nobody is reported compliant for it.',
    'vidyalms.compliance.rule_missing' => 'The rule this requirement was written under is not installed on this '
        . 'site, so it is reported as assigned and untracked rather than as met.',
    'vidyalms.compliance.unresolved' => 'The installed rule could not work out a due date from the parameters '
        . 'this requirement carries, so it sets none rather than guessing one.',
    'vidyalms.compliance.completed_late' => 'Completed after the due date. It counts as met, and the lateness is '
        . 'recorded rather than inferred.',
    'org.seat_reclaim.never' => 'Never reclaim',
    'org.seat_reclaim.always' => 'Reclaim at any time',
    'org.seat_reclaim.before_start' => 'Reclaim before the learner starts',
    'org.seat_reclaim.before_completion' => 'Reclaim before the learner finishes',
    'vidyalms.trigger.organisation_invitation' => 'Organisation invitation',
    'vidyalms.trigger.organisation_proof' => 'Membership confirmation code',
    // Transactional mail. Each template has a .subject and a .body key; the
    // CMS mail template systems may override the layout around the body.
    'vidyalms.mail.enrolled.subject' => 'You are enrolled in {course_title}',
    'vidyalms.mail.enrolled.body' => 'Hello {learner_name},

You are now enrolled in {course_title}. Pick up where you left off any time from your learning page.

Good learning!',

    'vidyalms.mail.course_completed.subject' => 'You completed {course_title}',
    'vidyalms.mail.course_completed.body' => 'Congratulations {learner_name},

You completed {course_title}. Your progress and results are available on your learning page.',

    'vidyalms.mail.certificate_issued.subject' => 'Your certificate for {course_title}',
    'vidyalms.mail.certificate_issued.body' => 'Congratulations {learner_name},

Your certificate for {course_title} has been issued. Verification code: {certificate.code}.
Anyone can verify it at {certificate.verification_url}.',

    'vidyalms.mail.submission_received.subject' => 'A new submission in {course_title}',
    'vidyalms.mail.submission_received.body' => 'Hello,

{learner_name} has submitted work in {course_title}. It is waiting in your grading queue.',

    'vidyalms.mail.attempt_graded.subject' => 'Your result for {course_title}',
    'vidyalms.mail.attempt_graded.body' => 'Hello {learner_name},

Your attempt in {course_title} has been marked. Your result is on your learning page.',

    // Free, seeded disabled, and here because the free build really does
    // schedule unlock notices: a task the free build cannot service is worse
    // than one nobody has switched on.
    'vidyalms.availability.unlocked.subject' => 'Something new is available in {course_title}',
    'vidyalms.availability.unlocked.body' => 'Hello {learner_name},

A new part of {course_title} is now open. Pick it up from your learning page whenever you are ready.',

    // Free, seeded disabled, and it stays disabled until a learner-facing
    // "access ends on" state exists: a notification rule may never be the
    // only place a fact exists.
    'vidyalms.sweep.entitlement_expiring.subject' => 'Your access to {course_title} ends soon',
    'vidyalms.sweep.entitlement_expiring.body' => 'Hello {learner_name},

Your access to {course_title} ends on {expires_at}. Finish anything outstanding before then.',

    // Capability labels for the CMS permission screens.
    'vidyalms.capability.admin' => 'Configure VidyaLMS',
    'vidyalms.capability.manage' => 'Manage courses and people',
    'vidyalms.capability.teach' => 'Author and edit courses',
    'vidyalms.capability.grade' => 'Grade attempts and submissions',
    'vidyalms.capability.enrol' => 'Enrol other people',
    'vidyalms.capability.reports' => 'View reports',
    'vidyalms.capability.manage_groups' => 'Manage groups and seats',

    // Lesson types.
    'vidyalms.lesson_type.video' => 'Video',
    'vidyalms.lesson_type.reading' => 'Reading',
    'vidyalms.lesson_type.resource' => 'Resource download',
    'vidyalms.lesson_type.assignment' => 'Assignment',
    'vidyalms.lesson_type.quiz' => 'Quiz',

    // Question types.
    'vidyalms.question_type.single_choice' => 'Single choice',
    'vidyalms.question_type.multiple_choice' => 'Multiple choice',
    'vidyalms.question_type.true_false' => 'True or false',
    'vidyalms.question_type.short_answer' => 'Short answer',
    'vidyalms.question_type.fill_blank' => 'Fill in the blank',
    'vidyalms.question_type.ordering' => 'Ordering',

    // Question presets: the author-facing names over the type keys. A preset
    // is data, so its label is a catalogue key like every other label rather
    // than a literal passed at boot.
    'vidyalms.question_preset.single_choice' => 'Multiple choice, one answer',
    'vidyalms.question_preset.multiple_choice' => 'Multiple response',
    'vidyalms.question_preset.true_false' => 'True or false',
    'vidyalms.question_preset.short_answer' => 'Short answer',
    'vidyalms.question_preset.fill_blank' => 'Fill in the blank',
    'vidyalms.question_preset.ordering' => 'Sequencing',

    // What an explanatory grade row says when no provider could mark an
    // answer. These reach learners and graders through attempt_grades.feedback.
    'vidyalms.assessment.miss.unknown_type' =>
        'The provider for question type "{type}" is not installed, so this answer was not marked.',
    'vidyalms.assessment.miss.unknown_type_package' =>
        'The "{package}" package provides question type "{type}" and is not installed, '
        . 'so this answer was not marked.',
    'vidyalms.assessment.miss.unsupported_version' =>
        'This question was authored in a newer format (version {version}) than the installed "{type}" provider '
        . 'reads, so it was not marked.',
    'vidyalms.assessment.miss.unsupported_version_package' =>
        'This question was authored in a newer format (version {version}) than the installed "{type}" provider '
        . 'from "{package}" reads, so it was not marked.',
    'vidyalms.assessment.miss.definition_lost' =>
        'The stored definition this answer was served from is no longer available, so it was not marked.',
    'vidyalms.assessment.miss.not_rendered' =>
        'This question could not be shown, so there was nothing to answer and it was not marked.',

    // Save-time shape errors. The error summary repeats the message verbatim
    // inline, so an authored min_message or max_message replaces these
    // entirely rather than being appended to them.
    'vidyalms.assessment.error.too_few_selected' => 'Choose at least {min}.',
    'vidyalms.assessment.error.too_many_selected' => 'Choose at most {max}.',
    'vidyalms.assessment.error.match_max' => 'This one can be used at most {limit} times.',
    'vidyalms.assessment.error.invalid_permutation' => 'Every item must appear exactly once in the order you give.',
    'vidyalms.assessment.error.value_not_numeric' => 'Give a number.',
    'vidyalms.assessment.error.out_of_range' => 'That value is outside the scale.',
    'vidyalms.assessment.error.unsupported_file_type' => 'That file type is not accepted for this question.',

    // What arrived is not a response to this question at all: the wrong
    // cardinality, the wrong base type, or another interaction's envelope. It
    // is a shape error and never a wrong answer, and it reaches a learner
    // when a stale bundle posts an old shape.
    'vidyalms.assessment.error.invalid_envelope' => 'That answer could not be read for this question. Answer it again.',

    // Attempt behaviours.
    'vidyalms.attempt_policy.deferred_feedback' => 'Deferred feedback',
    'vidyalms.attempt_policy.manual' => 'Marked by a person',

    // How an attempt's questions are chosen.
    'vidyalms.composition.quiz_questions' => 'The quiz\'s own questions',

    // How several attempts reduce to one reported grade.
    'vidyalms.aggregation.highest' => 'Highest attempt',
    'vidyalms.aggregation.latest' => 'Latest attempt',

    // The quiz settings form, which is built from the registries rather than
    // from a hardcoded field list, so a new behaviour or aggregation method
    // appears in the workbench with no client change.
    'vidyalms.quiz_settings.group.behaviour' => 'Behaviour',
    'vidyalms.quiz_settings.group.behaviour.description' =>
        'How the attempt runs, and what the learner may see while it is running.',
    'vidyalms.quiz_settings.group.presentation' => 'Presentation',
    'vidyalms.quiz_settings.group.availability' => 'Availability and timing',
    'vidyalms.quiz_settings.group.availability.description' =>
        'The deadline of an attempt is the earlier of its time limit and the quiz close time, frozen when it starts.',
    'vidyalms.quiz_settings.group.grading' => 'Grading',
    'vidyalms.quiz_settings.group.grading.description' =>
        'Which attempts count, how they reduce to one grade, and what a wrong answer costs.',
    'vidyalms.quiz_settings.group.item_session' => 'Per-item rules',
    'vidyalms.quiz_settings.group.item_session.description' =>
        'The defaults for every item of the quiz, in the standard\'s own vocabulary.',
    'vidyalms.quiz_settings.field.behaviour' => 'Attempt behaviour',
    'vidyalms.quiz_settings.field.navigation_mode' => 'Navigation',
    'vidyalms.quiz_settings.field.navigation_mode.description' =>
        'Linear means the learner may not return to a screen they have left, and the server enforces it.',
    'vidyalms.quiz_settings.field.submission_mode' => 'Submission',
    'vidyalms.quiz_settings.field.shuffle_questions' => 'Shuffle questions',
    'vidyalms.quiz_settings.field.shuffle_options' => 'Shuffle options',
    'vidyalms.quiz_settings.field.one_per_page' => 'One question per page',
    'vidyalms.quiz_settings.field.opens_at' => 'Opens',
    'vidyalms.quiz_settings.field.closes_at' => 'Closes',
    'vidyalms.quiz_settings.field.expiry_policy' => 'When the time runs out',
    'vidyalms.quiz_settings.field.grace_seconds' => 'Grace period',
    'vidyalms.quiz_settings.field.grace_seconds.description' =>
        'Never below 60 seconds: a shorter one would refuse a submit that left the browser in time.',
    'vidyalms.quiz_settings.field.auto_submit' => 'Submit automatically at the deadline',
    'vidyalms.quiz_settings.field.cooldown_after_first_seconds' => 'Wait after the first attempt',
    'vidyalms.quiz_settings.field.cooldown_after_later_seconds' => 'Wait after later attempts',
    'vidyalms.quiz_settings.field.attempts_counted' => 'Attempts counted',
    'vidyalms.quiz_settings.field.attempt_aggregation' => 'Reported grade',
    'vidyalms.quiz_settings.field.negative_marking_policy' => 'Negative marking',
    'vidyalms.quiz_settings.field.negative_marking_policy.description' =>
        'Off by default. When it is on, the learner instructions say so before the attempt starts.',
    'vidyalms.quiz_settings.field.allow_quiz_negative' => 'Allow a negative quiz total',
    'vidyalms.quiz_settings.field.try_penalty' => 'Penalty per extra try',
    'vidyalms.quiz_settings.field.item_session_control' => 'Per-item rules',
    'vidyalms.quiz_settings.field.review_visibility' => 'What the learner may see',
    'vidyalms.quiz_settings.field.item_max_attempts' => 'Tries per item',
    'vidyalms.quiz_settings.field.item_show_feedback' => 'Show feedback',
    'vidyalms.quiz_settings.field.item_allow_review' => 'Allow review',
    'vidyalms.quiz_settings.field.item_show_solution' => 'Show the solution',
    'vidyalms.quiz_settings.field.item_allow_comment' => 'Allow a comment',
    'vidyalms.quiz_settings.field.item_allow_skipping' => 'Allow skipping',
    'vidyalms.quiz_settings.field.item_validate_responses' => 'Require a valid response to move on',
    'vidyalms.quiz_settings.option.none' => 'Off',
    'vidyalms.quiz_settings.option.linear' => 'Linear',
    'vidyalms.quiz_settings.option.nonlinear' => 'Free',
    'vidyalms.quiz_settings.option.individual' => 'Each answer as it is given',
    'vidyalms.quiz_settings.option.simultaneous' => 'All at the end',
    'vidyalms.quiz_settings.option.auto_submit' => 'Submit the attempt',
    'vidyalms.quiz_settings.option.grace_period' => 'Allow a late submit only',
    'vidyalms.quiz_settings.option.must_submit' => 'Do not count a late attempt',
    'vidyalms.quiz_settings.option.all' => 'Every attempt',
    'vidyalms.quiz_settings.option.first' => 'The first attempt',
    'vidyalms.quiz_settings.option.last' => 'The last attempt',
    'vidyalms.quiz_settings.unavailable' =>
        'This quiz is set to "{key}", which no installed package provides, so the attempt runs the default instead.',
    'vidyalms.quiz_settings.unavailable_package' =>
        'This quiz is set to "{key}", which came from {package}. That package is not installed, so the attempt runs the default instead.',
    'vidyalms.quiz_settings.try_penalty_ignored' =>
        'The "{behaviour}" behaviour gives one try per question, so a try penalty has nothing to charge for and is ignored on this quiz.',

    // What a regrade would not touch, and why. A regrade appends and never
    // mutates, and it never replaces a mark a person gave.
    'vidyalms.regrade.warning.human_grade_kept' =>
        'A mark given by a person was left as it was: a regrade never replaces human judgement.',
    'vidyalms.regrade.warning.pending_human_kept' =>
        'An item still waiting for a human grade was left as it was.',

    // Item analysis, when no package computes it.
    'vidyalms.report.item_analysis' => 'Item analysis',
    'vidyalms.report.item_analysis.not_computed' =>
        'No installed package computes item statistics. The inputs are recorded for every attempt, so installing one reports on the attempts already taken.',

    // Enrolment rules.
    'vidyalms.enrolment_rule.open' => 'Open enrolment',
    'vidyalms.enrolment_rule.manual' => 'Manual enrolment',

    // Commerce sources.
    'vidyalms.commerce_source.cms_shop' => 'CMS shop',

    // Notification channels.
    'vidyalms.channel.email' => 'Email',

    // Reports.
    'vidyalms.report.course_overview' => 'Course overview',
    'vidyalms.report.site_learning' => 'Course outcomes across the site',
    'vidyalms.report.enrolment_roster' => 'Enrolment roster',
    'vidyalms.report.org_access_log' => 'Organization access log',
    'vidyalms.report.org_overview' => 'Organization overview',
    'vidyalms.report.org_roster' => 'Organization roster',
    'vidyalms.report.ai_usage' => 'AI usage and oversight',
    'vidyalms.report.at_risk_learners' => 'Learners who may be falling behind',
    'vidyalms.report.at_risk_learners.advisory' =>
        'This report is advisory. It never gates content, revokes access or fails anyone by itself: '
        . 'every row names a reason and a person to contact, and a human decides what to do about it.',
    'vidyalms.report.at_risk_learners.reason.idle' => 'No activity on this course for longer than the set period',
    'vidyalms.report.at_risk_learners.reason.progress' => 'Progress is below the set floor for this course',
    'vidyalms.at_risk.column.enrolment_id' => 'Enrolment',
    'vidyalms.at_risk.column.user_id' => 'User',
    'vidyalms.at_risk.column.learner' => 'Learner',
    'vidyalms.at_risk.column.progress_percent' => 'Progress',
    'vidyalms.at_risk.column.last_seen_at' => 'Last seen',
    'vidyalms.at_risk.column.idle_days' => 'Days idle',
    'vidyalms.at_risk.column.reason' => 'Why this learner is flagged',
    'vidyalms.at_risk.column.contact_user_id' => 'Person to contact',
    'vidyalms.at_risk.column.acknowledged' => 'Acted on',
    'vidyalms.report.not_installed' =>
        'No installed package computes the "{report}" report on this site, so there is nothing to show yet.',
    'vidyalms.report.scope_unsupported' =>
        'The "{report}" report was not written to honour an organization scope, so it is not offered here.',
    // Report exports and delivery.
    'vidyalms.export.csv' => 'CSV',
    'vidyalms.export.json' => 'JSON',
    'vidyalms.report.delivery.download' => 'Download link',
    'vidyalms.report.delivery.notification_link' => 'Notify with a download link',
    'vidyalms.report.ready.subject' => 'Your {report.key} report is ready',
    'vidyalms.report.ready.body' =>
        'Your {report.key} report has finished with {report.row_count} rows. '
        . 'Download it here: {report.url}. The link expires at {report.url_expires_at}.',
    // Roster columns.
    'vidyalms.roster.column.enrolment_id' => 'Enrolment',
    'vidyalms.roster.column.user_id' => 'User',
    'vidyalms.roster.column.learner' => 'Learner',
    'vidyalms.roster.column.status' => 'Status',
    'vidyalms.roster.column.enrolled_at' => 'Enrolled',
    'vidyalms.roster.column.completed_at' => 'Completed',
    'vidyalms.roster.column.progress' => 'Progress',
    // Site learning columns.
    'vidyalms.site_learning.column.course_id' => 'Course',
    'vidyalms.site_learning.column.title' => 'Title',
    'vidyalms.site_learning.column.state' => 'State',
    'vidyalms.site_learning.column.enrolments' => 'Enrolments',
    'vidyalms.site_learning.column.active' => 'Active',
    'vidyalms.site_learning.column.completed' => 'Completed',
    'vidyalms.site_learning.column.completion_rate' => 'Completion rate',
    // Organization access log columns.
    'vidyalms.org_access_log.column.id' => 'Record',
    'vidyalms.org_access_log.column.occurred_at' => 'When',
    'vidyalms.org_access_log.column.event_type' => 'Event',
    'vidyalms.org_access_log.column.actor_id' => 'Read by',
    'vidyalms.org_access_log.column.actor_role_key' => 'Under role',
    'vidyalms.org_access_log.column.subject_user_id' => 'About',
    'vidyalms.org_access_log.column.resource' => 'Resource',
    'vidyalms.org_access_log.column.route' => 'Route',
    'vidyalms.org_access_log.column.outcome' => 'Outcome',
    'vidyalms.org_access_log.column.record_count' => 'Records read',
    // Organization roster columns. The roster is built on membership rather
    // than enrolment, so enrolment_count is the number a reader chases: a
    // member showing zero is a member nobody has given anything to do.
    'vidyalms.org_roster.column.user_id' => 'Person',
    'vidyalms.org_roster.column.display_name' => 'Name',
    'vidyalms.org_roster.column.email' => 'Email address',
    'vidyalms.org_roster.column.unit_path' => 'Unit',
    'vidyalms.org_roster.column.role_keys' => 'Roles',
    'vidyalms.org_roster.column.state' => 'Membership',
    'vidyalms.org_roster.column.joined_at' => 'Joined',
    'vidyalms.org_roster.column.provisioned_by' => 'Added by',
    'vidyalms.org_roster.column.directory_managed' => 'Directory managed',
    'vidyalms.org_roster.column.enrolment_count' => 'Enrolments',
    'vidyalms.org_roster.column.seat_state' => 'Seat',
    'vidyalms.org_roster.column.compliance_summary' => 'Compliance',
    // Field disclosure. The free policy discloses only what proves completion.
    'vidyalms.field_disclosure.org_minimal' => 'Only what proves completion',
    // Grade aggregation, seated ahead of the gradebook that calls it.
    'vidyalms.grade_aggregation.natural' => 'Natural (sum of points)',
    'vidyalms.grade_aggregation.mean' => 'Mean of grades',
    'vidyalms.grade_aggregation.weighted_mean' => 'Weighted mean of grades',
    'vidyalms.grade_aggregation.drop_lowest' => 'Mean of grades, lowest dropped',
    'vidyalms.grade_aggregation.extra_credit' => 'Sum of points with extra credit',
    // How a grade is written down, which display_mode has stored since the
    // gradebook shipped and nothing rendered.
    'vidyalms.grade_display.real' => 'The grade itself',
    'vidyalms.grade_display.percentage' => 'Percentage',
    'vidyalms.grade_display.letter' => 'Letter',
    // Statement forwarding.
    'vidyalms.statement_forwarder.log' => 'Write to the log',

    // Certificate renderers.
    'vidyalms.certificate_renderer.html' => 'HTML certificate',

    // Importers.
    'vidyalms.importer.json_course' => 'JSON course file',
    'vidyalms.importer.community_quiz' => 'Community Quiz (this site\'s database)',
    'vidyalms.importer.moodle_backup' => 'Moodle course backup (.mbz)',
    // The three WordPress source importers. Each label carries the same
    // warning its class docblock does, because the label is what an operator
    // reads on the Import screen and the docblock is not.
    'vidyalms.importer.learndash' => 'LearnDash (this site\'s database, unverified against a real install)',
    'vidyalms.importer.tutor_lms' => 'Tutor LMS (this site\'s database, unverified against a real install)',
    'vidyalms.importer.lifterlms' => 'LifterLMS (this site\'s database, unverified against a real install)',
    'vidyalms.importer.wordpress.final_section' => 'Final quizzes',
    // The three Joomla source importers, labelled the same way and for the
    // same reason. None of the three products is installed on either
    // development site, so none of the three importers has been run against a
    // real one, and the label is where an operator finds that out.
    'vidyalms.importer.shika' => 'Shika (this site\'s database, unverified against a real install)',
    'vidyalms.importer.guru' => 'Guru (this site\'s database, unverified against a real install)',
    'vidyalms.importer.oscampus' => 'OSCampus (this site\'s database, unverified against a real install)',
    // Importer options. Declared through ConfigurableImporterInterface, drawn
    // by the Import screen of each host, and read back out of the same free
    // key/value options map import() has always been handed.
    'vidyalms.importer.option.course_key' => 'Course key',
    'vidyalms.importer.option.course_key.help' =>
        'The identity a re-import finds this course by. Leave it empty when the document carries a "key" of '
        . 'its own. A document with neither is identified by a digest of its exact content, so a corrected '
        . 'copy imports as a second course.',
    'vidyalms.importer.option.enrolments' => 'Import enrolments as entitlements',
    'vidyalms.importer.option.enrolments.help' =>
        'Off by default. Lesson progress and quiz attempts are never imported either way.',
    'vidyalms.importer.option.content' => 'What to import',
    'vidyalms.importer.option.content.help' =>
        'The curriculum always comes across. Leave the quizzes out when you intend to rebuild the assessments '
        . 'here rather than carry them over.',
    'vidyalms.importer.option.content.curriculum_and_quizzes' => 'Courses, lessons and quizzes',
    'vidyalms.importer.option.content.curriculum_only' => 'Courses and lessons only',
    'vidyalms.importer.option.progress' => 'Import what learners have finished',
    'vidyalms.importer.option.progress.help' =>
        'Off by default. A replayed completion is dated today, not when it happened, and a course that '
        . 'completes issues today\'s certificate for work done years ago.',
    'vidyalms.importer.option.upsert' => 'What a second run does',
    'vidyalms.importer.option.upsert.help' =>
        'Moodle\'s four choices, because an importer that guesses eventually guesses wrong on live data.',
    'vidyalms.importer.option.upsert.create_only' => 'Create what is missing, change nothing',
    'vidyalms.importer.option.upsert.create_and_update' => 'Create what is missing and update the rest',
    'vidyalms.importer.option.upsert.update_only' => 'Update what is already here, create nothing',
    'vidyalms.importer.option.upsert.create_with_suffix' => 'Create a second copy every time',
    // The two free roster importers, and the role mapper behind both of them.
    // A roster load is an importer, so its label sits with the other importer
    // labels rather than in an organisations block of its own.
    'vidyalms.roster.org_members_csv' => 'Organisation members CSV',
    'vidyalms.roster.oneroster_csv_1_2' => 'OneRoster 1.2 CSV file set (zip)',
    'vidyalms.org_role_mapper.standard' => 'OneRoster, LTI and Canvas roles',
    'vidyalms.roster.error.organisation_column' =>
        'The column "{column}" in {file} names an organisation, and a roster file may not. The '
        . 'organisation comes from the console this load was started in, so that a file cannot move '
        . 'people into an organisation you do not administer. Delete the column and upload the file again.',
    'vidyalms.roster.error.orgs_scope' =>
        'This package carries orgs.csv, which defines the organisation tree. Loading it needs authority '
        . 'over the whole organisation, and yours covers one part of it. Ask an organisation '
        . 'administrator to load the package, or upload one without orgs.csv.',
    'vidyalms.roster.error.no_organisation' =>
        'This roster job names no organisation. A roster load takes its organisation from the console it '
        . 'was started in, so start it from the organisation\'s own import screen.',
    'vidyalms.roster.option.invite' => 'Invite addresses with no account',
    'vidyalms.roster.option.invite.help' =>
        'Off unless you tick it, because a roster load should not mail people nobody asked it to '
        . 'mail. With it on, a person with no account here is invited instead of being reported as '
        . 'skipped, and re-uploading a corrected file does not invite anybody twice.',
    'vidyalms.roster.option.role_map' => 'Role mapper',
    'vidyalms.roster.option.role_map.help' =>
        'Leave empty to use every registered mapper in order. Name one to use only that one, which is '
        . 'what a site with its own role vocabulary does.',
    'vidyalms.roster.option.sweep' => 'Close memberships this file does not mention',
    'vidyalms.roster.option.sweep.help' =>
        'Off by default, and it should usually stay off. A row missing from a file normally means the '
        . 'export was partial, not that the person left. It only ever considers memberships this '
        . 'importer wrote, and it refuses itself if it would close more than the threshold below.',
    'vidyalms.roster.option.threshold' => 'Refuse the sweep above this percentage',
    'vidyalms.roster.option.threshold.help' =>
        'Ten percent by default. If a sweep would close more of the roster than this, it closes none of '
        . 'it and says so, because a partial export is the usual cause and it is not recoverable.',
    'vidyalms.roster.option.tombstones' => 'Ignore tobedeleted rows',
    'vidyalms.roster.option.tombstones.help' =>
        'Leave this off. A row marked tobedeleted in a delta file closes that membership, and it is the '
        . 'only deletion instruction this build honours: an absent row never removes anybody. Tick it '
        . 'only to load a file for its additions while leaving its removals for later.',
    'vidyalms.importer.option.publish' => 'Publish the imported courses',
    'vidyalms.importer.option.publish.help' =>
        'Off by default: a migration lands as a draft for a person to review before learners see it.',
    'vidyalms.importer.option.table_prefix' => 'Source table prefix',
    'vidyalms.importer.option.table_prefix.help' =>
        'Which Community Quiz install to read, for a database holding more than one. It also names the '
        . 'source in the import map, so two installs are two migrations rather than one overwriting the other.',
    'vidyalms.importer.option.max_bytes' => 'Expanded size limit, in bytes',
    'vidyalms.importer.option.max_bytes.help' =>
        'Lowers the cap on how far the archive may expand for this run. Leave it empty for the default; a '
        . 'value above the build ceiling is ignored rather than obeyed.',
    'vidyalms.importer.option.max_entries' => 'Archive entry limit',
    'vidyalms.importer.option.max_entries.help' =>
        'Lowers the cap on how many files the archive may hold for this run. Leave it empty for the default; '
        . 'a value above the build ceiling is ignored rather than obeyed.',

    'vidyalms.importer.moodle.general_section' => 'General',
    'vidyalms.importer.moodle.topic' => 'Topic {number}',
    'vidyalms.importer.moodle.week' => 'Week {number}',

    // The advisory integrity observations. Every one of these has a
    // documented false positive rate against legitimate access tools, which
    // is why the labels describe what the browser saw rather than what it
    // might mean.
    'vidyalms.integrity.kind.window_blur' => 'The window lost focus',
    'vidyalms.integrity.kind.window_focus' => 'The window regained focus',
    'vidyalms.integrity.kind.visibility_hidden' => 'The page was hidden',
    'vidyalms.integrity.kind.visibility_visible' => 'The page became visible again',
    'vidyalms.integrity.kind.paste' => 'Text was pasted',
    'vidyalms.integrity.kind.copy' => 'Text was copied',
    'vidyalms.integrity.kind.fullscreen_exit' => 'Full screen was left',
    'vidyalms.integrity.kind.navigation_away' => 'The learner navigated away',

    // Publication checks, run on the draft to ready transition. The labels
    // name the check in the authoring UI; the messages are what an author
    // reads beside the item the check names.
    'vidyalms.publication.check_failed'
        => 'The "{check}" publication check could not run ({reason}), so it reported nothing about this quiz.',
    'vidyalms.publication.check.negative_marking' => 'Negative marks without a negative marking rule',
    'vidyalms.publication.check.min_choices' => 'Correct answers only with no minimum',
    'vidyalms.publication.check.positional_option' => 'Positional option in a shuffled set',
    'vidyalms.publication.check.guess_neutral_balance' => 'Guess neutral with fewer distractors than keys',
    'vidyalms.publication.check.ordering_in_key_order' => 'Ordering item already in its answer order',
    'vidyalms.publication.negative_without_policy'
        => 'This question can award a negative mark ({reason}), but the quiz does not enable negative marking. '
        . 'Turn negative marking on for the quiz, or remove the negative value.',
    'vidyalms.publication.min_choices_below_one'
        => 'This question is marked "correct answers only" with a minimum of {min_choices} selections, so a learner '
        . 'who selects nothing is paid the same as one who answers it. Require at least one selection.',
    'vidyalms.publication.positional_option_shuffled'
        => 'The option "{option}" refers to its own position, but this quiz shuffles options and the option is not '
        . 'fixed. Mark it fixed so it keeps its place.',
    'vidyalms.publication.guess_neutral_unbalanced'
        => 'This question has {keys} correct options and only {distractors} distractors, so under guess neutral one '
        . 'wrong selection costs {cost} of the mark and selecting everything cannot reach zero. Add distractors, or '
        . 'limit how many selections a learner may make.',
    'vidyalms.publication.ordering_in_key_order'
        => 'This ordering question is presented in its own answer order, so a learner who submits without touching '
        . 'it scores full marks. Author it out of order, or shuffle its options.',

    // ── Availability ─────────────────────────────────────────────────────
    //
    // Reason keys are translated by the host and never assembled in English
    // on the server, so every one of them arrives here with the parameters
    // its condition names.
    'vidyalms.availability.prerequisites' => 'Complete the required courses before starting this course.',
    'vidyalms.availability.category_access' => 'This course category is not available to your account.',
    'vidyalms.availability.condition.date' => 'Date',
    'vidyalms.availability.condition.completion' => 'Another item is complete',
    'vidyalms.availability.condition.score' => 'Score on another item',
    'vidyalms.availability.condition.role' => 'Held permission',
    'vidyalms.availability.condition.enrolment_source' => 'How the learner enrolled',

    'vidyalms.anchor.absolute' => 'A fixed date',
    'vidyalms.anchor.enrolment' => 'When this learner enrolled',
    'vidyalms.anchor.entitlement_start' => 'When this learner gained access',
    'vidyalms.anchor.course_start' => 'When the course starts',
    'vidyalms.anchor.later_of_enrolment_and_course_start' => 'Enrolment or course start, whichever is later',

    'vidyalms.availability.preset.date_from' => 'Available on a date',
    'vidyalms.availability.preset.date_until' => 'Available until a date',
    'vidyalms.availability.preset.after_item' => 'Available when an item is complete',
    'vidyalms.availability.preset.score_at_least' => 'Available at a score',
    'vidyalms.availability.preset.days_after_enrolment' => 'Available N days after enrolment',
    'vidyalms.availability.preset.field.from' => 'Available from',
    'vidyalms.availability.preset.field.until' => 'Available until',
    'vidyalms.availability.preset.field.tz' => 'Time zone',
    'vidyalms.availability.preset.field.target' => 'Item',
    'vidyalms.availability.preset.field.min' => 'Minimum percentage',
    'vidyalms.availability.preset.field.offset' => 'Delay',
    'vidyalms.availability.preset.field.offset_unit' => 'Delay unit',

    'vidyalms.availability.date.from' => 'Available on {from}',
    'vidyalms.availability.date.until' => 'This closed on {until}',
    'vidyalms.availability.date.not_from' => 'Not available from {from}',
    'vidyalms.availability.date.not_until' => 'Not available until {until}',
    'vidyalms.availability.date.unconfigured' => 'This restriction has no dates set',
    'vidyalms.availability.completion.required' => 'Complete "{title}" first',
    'vidyalms.availability.completion.forbidden' => 'Not available once "{title}" is complete',
    'vidyalms.availability.completion.target_missing' => 'This depends on an item that is no longer in the course',
    'vidyalms.availability.score.min' => 'Score at least {min} percent on "{title}"',
    'vidyalms.availability.score.max' => 'Score below {max} percent on "{title}"',
    'vidyalms.availability.score.band' => 'Score between {min} and {max} percent on "{title}"',
    'vidyalms.availability.score.outside' => 'Score outside {min} to {max} percent on "{title}"',
    'vidyalms.availability.score.target_missing' => 'This depends on a graded item that is no longer in the course',
    'vidyalms.availability.role.required' => 'This is for holders of a permission',
    'vidyalms.availability.role.forbidden' => 'This is hidden from holders of a permission',
    'vidyalms.availability.role.unconfigured' => 'This restriction names no permission',
    'vidyalms.availability.enrolment_source.required' => 'This is for learners who enrolled a particular way',
    'vidyalms.availability.enrolment_source.forbidden' => 'This is hidden from learners who enrolled a particular way',
    'vidyalms.availability.enrolment_source.unconfigured' => 'This restriction names no enrolment source',
    'vidyalms.availability.grant.denied' => 'An administrator has excused you from this',
    'vidyalms.availability.grant.denied_until' => 'An administrator has closed this until {until}',
    'vidyalms.availability.blocked' => 'Not available yet',
    'vidyalms.availability.provider_missing'
        => 'This restriction needs an extension that is not installed, so the item stays closed.',

    // ── Notifications ────────────────────────────────────────────────────
    'vidyalms.trigger.enrolled' => 'A learner enrols in a course',
    'vidyalms.trigger.course_completed' => 'A learner completes a course',
    'vidyalms.trigger.certificate_issued' => 'A certificate is issued',
    'vidyalms.trigger.submission_received' => 'An assignment is submitted',
    'vidyalms.trigger.attempt_graded' => 'An attempt is graded',
    'vidyalms.trigger.content_unlocked' => 'A scheduled item becomes available',
    'vidyalms.trigger.entitlement_expiring' => 'Access is about to end',
    'vidyalms.trigger.seats_limited' => 'A seat pool is running low',
    'vidyalms.trigger.seats_exhausted' => 'A seat pool has run out of seats',
    'vidyalms.trigger.seats_summary' => 'The scheduled seat summary',

    'vidyalms.invitation_match.strict_email' => 'The invited address must own the accepting account',

    'vidyalms.audience.learner' => 'The learner',
    'vidyalms.audience.course_instructors' => 'The course instructors',
    'vidyalms.audience.site_managers' => 'Site managers',
    'vidyalms.audience.org_admins' => 'The organisation administrators',
    'vidyalms.audience.org_unit_managers' => 'The unit managers',
    'vidyalms.audience.line_manager' => 'The learner\'s manager',

    'vidyalms.notification.category.course_progress' => 'Course progress',
    'vidyalms.notification.category.assessment' => 'Assessment',
    'vidyalms.notification.category.credential' => 'Credentials',
    'vidyalms.notification.category.account' => 'Account',
    'vidyalms.notification.category.announcements' => 'Announcements',
    'vidyalms.notification.category.marketing' => 'Marketing',

    'vidyalms.notification.schedule.immediate' => 'As soon as it happens',
    'vidyalms.notification.schedule.absolute' => 'On a fixed date',
    'vidyalms.notification.schedule.relative' => 'Before or after an anchor',
    'vidyalms.notification.schedule.recurring_window' => 'Every so often, bounded',

    'vidyalms.policy.preference' => 'Learner preference',
    'vidyalms.policy.consent' => 'Consent',
    'vidyalms.policy.suppression' => 'Address suppression',
    'vidyalms.policy.channel_confirmation' => 'Unconfirmed address',
    'vidyalms.policy.quiet_hours' => 'Quiet hours',
    'vidyalms.policy.frequency_cap' => 'Frequency cap',
    'vidyalms.policy.duplicate_window' => 'Duplicate window',
    'vidyalms.policy.cross_rule' => 'Cross-rule suppression',

    // Provider settings, declared by the providers that read them so a site
    // owner has somewhere to type a value that was previously reachable only
    // through the API.
    'vidyalms.settings.email.description' =>
        'Bounce handling and the inbound feedback route for the email channel.',
    'vidyalms.settings.email.envelope_domain' => 'Bounce domain',
    'vidyalms.settings.email.envelope_domain.help' =>
        'The domain the envelope sender is composed under, so an asynchronous bounce can be attributed. '
        . 'Leave empty to use the domain of the site\'s own from address.',
    'vidyalms.settings.email.envelope_prefix' => 'Bounce mailbox',
    'vidyalms.settings.email.envelope_prefix.help' =>
        'The local part before the tag, so reports arrive at a mailbox you own.',
    'vidyalms.settings.email.feedback_secret' => 'Feedback shared secret',
    'vidyalms.settings.email.sns_topic' => 'Amazon SNS topic (Pro)',
    'vidyalms.settings.email.sns_topic.help' => 'For SES delivery reports, enter the exact SNS topic ARN. Subscribe the HTTPS feedback address using Basic authentication: username vidyalms and your feedback secret as the password. Leave empty for other mail services.',
    'vidyalms.settings.email.feedback_secret.help' =>
        'Authenticates the inbound delivery feedback route for this channel. Written once and never shown again; '
        . 'with none set the route is closed.',
    'vidyalms.settings.email.soft_bounce_threshold' => 'Soft bounce threshold',
    'vidyalms.settings.email.soft_bounce_threshold.help' =>
        'How many soft bounces an address may collect before it is suppressed.',
    'vidyalms.settings.policy.consent.default_granted' => 'Treat unrecorded consent as granted',
    'vidyalms.settings.policy.consent.default_granted.help' =>
        'Off refuses every marketing message to a learner with no consent row.',
    'vidyalms.settings.policy.cross_rule.window_hours' => 'Suppression window (hours)',
    'vidyalms.settings.policy.cross_rule.window_hours.help' =>
        'How long one rule silences the rules it names for the same subject.',
    'vidyalms.settings.policy.duplicate_window.enabled' => 'Collapse duplicates',
    'vidyalms.settings.policy.duplicate_window.enabled.help' =>
        'Cancels a pending message that repeats one already queued for the same recipient.',
    'vidyalms.settings.policy.frequency_cap.max_per_day' => 'Messages per learner per day',
    'vidyalms.settings.policy.frequency_cap.max_per_day.help' =>
        'Transactional messages are never counted against this ceiling.',
    'vidyalms.settings.policy.quiet_hours.enabled' => 'Hold messages overnight',
    'vidyalms.settings.policy.quiet_hours.enabled.help' =>
        'Deferred rather than dropped, and read in the recipient\'s own timezone.',
    'vidyalms.settings.policy.quiet_hours.start' => 'Quiet from',
    'vidyalms.settings.policy.quiet_hours.start.help' => 'A 24-hour wall clock time, such as 22:00.',
    'vidyalms.settings.policy.quiet_hours.end' => 'Quiet until',
    'vidyalms.settings.policy.quiet_hours.end.help' => 'A 24-hour wall clock time, such as 07:00.',

    'vidyalms.feedback.rfc5965' => 'Abuse report (RFC 5965)',
    'vidyalms.late_policy.none' => 'No late penalty',
    'vidyalms.token.user' => 'The recipient',
    'vidyalms.token.course' => 'The course',
    'vidyalms.token.site' => 'The site',
    'vidyalms.token.cohort' => 'The run',

    // Certificate default template strings.
    'vidyalms.certificate.default_template_name' => 'Default certificate',
    'vidyalms.certificate.awarded_to' => 'This certificate is awarded to',
    'vidyalms.certificate.for_completing' => 'for successfully completing',
    'vidyalms.certificate.verify_hint' => 'Verify this certificate with code {verification_code} at {verification_url}',
    'vidyalms.certificate.issued_on' => 'Issued on',
    'vidyalms.trigger.certificate_reissued' => 'A certificate is corrected and replaced',
    'vidyalms.certificate.reissued.subject' => 'Your certificate for {course_title} has been reissued',
    'vidyalms.certificate.reissued.body' => 'Hello {learner_name},

Your certificate for {course_title} has been corrected and replaced. '
        . 'The replacement carries verification code {certificate.code}, and the previous code now points to it.
Anyone can verify it at {certificate.verification_url}.',
    'vidyalms.certificate.logo_alt' => 'Issuing organisation logo',

    // Credentials: the element catalogue, the credential formats, the credit
    // scheme, and the two miss messages that have to be visible rather than
    // silent.
    'vidyalms.certificate_renderer.elements' => 'Designed certificate',
    'vidyalms.certificate_renderer.missing' =>
        'This certificate was produced by the "{renderer}" renderer, which is not installed on this site.',
    'vidyalms.certificate_element.missing' =>
        'The "{element}" certificate element is not installed on this site.',
    'vidyalms.token.certificate' => 'The certificate',
    'vidyalms.token.credit' => 'Continuing education credit',
    'vidyalms.credit_scheme.contact_hours' => 'Contact hours',
    'vidyalms.credit_scheme.error.contact_minutes_required' => 'Record how many contact minutes this course carries.',
    'vidyalms.credit_scheme.error.contact_minutes_invalid' => 'Contact minutes must be a whole number above zero.',
    'vidyalms.credential_format.vidyalms_json' => 'VidyaLMS credential (unsigned JSON-LD)',
    'vidyalms.credential_format.openbadges2_baked_png' => 'Open Badges 2.0 baked image',
    'vidyalms.credential_format.openbadges2_assertion' => 'Open Badges 2.0 hosted assertion',
    'vidyalms.credential.default_criteria' => 'Awarded on completion of the course.',
    'vidyalms.credential.unnamed_issuer' => 'Issuer not named yet',
    'vidyalms.credential.evidence_name' => 'Public verification page',
    'vidyalms.expiry_policy.never' => 'Never expires',
    'vidyalms.expiry_policy.fixed' => 'Expires on a fixed date',
    'vidyalms.expiry_policy.days_after_issue' => 'Expires a number of days after issue',

    'vidyalms.certificate_element.text' => 'Text',
    'vidyalms.certificate_element.image' => 'Image',
    'vidyalms.certificate_element.background_image' => 'Background image',
    'vidyalms.certificate_element.border' => 'Border',
    'vidyalms.certificate_element.learner_name' => 'Learner name',
    'vidyalms.certificate_element.course_name' => 'Course name',
    'vidyalms.certificate_element.category_name' => 'Category name',
    'vidyalms.certificate_element.issue_date' => 'Issue date',
    'vidyalms.certificate_element.expiry_date' => 'Expiry date',
    'vidyalms.certificate_element.grade' => 'Grade',
    'vidyalms.certificate_element.verification_code' => 'Verification code',
    'vidyalms.certificate_element.qr_code' => 'QR code',
    'vidyalms.certificate_element.signature_image' => 'Signature',
    'vidyalms.certificate_element.instructor_name' => 'Instructor name',
    'vidyalms.certificate_element.user_field' => 'Any learner field',
    'vidyalms.certificate_element.course_field' => 'Any course field',
    'vidyalms.certificate_element.credit_statement' => 'Credit statement',

    'vidyalms.certificate_element.field.font' => 'Font',
    'vidyalms.certificate_element.field.family' => 'Font family',
    'vidyalms.certificate_element.field.size' => 'Font size',
    'vidyalms.certificate_element.field.weight' => 'Font weight',
    'vidyalms.certificate_element.field.colour' => 'Colour',
    'vidyalms.certificate_element.field.line_height' => 'Line height',
    'vidyalms.certificate_element.field.label' => 'Label',
    'vidyalms.certificate_element.field.fallback' => 'Shown when there is no value',
    'vidyalms.certificate_element.field.text' => 'Text',
    'vidyalms.certificate_element.field.max_width_mm' => 'Maximum width (mm)',
    'vidyalms.certificate_element.field.path' => 'File',
    'vidyalms.certificate_element.field.alt' => 'Alternative text',
    'vidyalms.certificate_element.field.width_mm' => 'Width (mm)',
    'vidyalms.certificate_element.field.height_mm' => 'Height (mm)',
    'vidyalms.certificate_element.field.size_mm' => 'Size (mm)',
    'vidyalms.certificate_element.field.fit' => 'Fit',
    'vidyalms.certificate_element.field.opacity' => 'Opacity',
    'vidyalms.certificate_element.field.border_style' => 'Border style',
    'vidyalms.certificate_element.field.border_width' => 'Border width (pt)',
    'vidyalms.certificate_element.field.inset_mm' => 'Inset (mm)',
    'vidyalms.certificate_element.field.radius_mm' => 'Corner radius (mm)',
    'vidyalms.certificate_element.field.token_field' => 'Field',
    'vidyalms.certificate_element.field.show_url' => 'Show the verification address as text',
    'vidyalms.certificate_element.field.signatory' => 'Signed by',
    'vidyalms.certificate_element.field.signatory_role' => 'Role',
    'vidyalms.certificate_element.field.credit_template' => 'Credit line',
    'vidyalms.certificate_element.field.show_field_of_study' => 'Show the field of study',

    'vidyalms.certificate_element.error.text_required' => 'Enter the text this element draws.',
    'vidyalms.certificate_element.error.path_required' => 'Choose the file this element draws.',
    'vidyalms.certificate_element.error.alt_required' =>
        'Describe this image for somebody who cannot see it. The verification page needs it.',
    'vidyalms.certificate_element.error.field_required' => 'Choose the field this element draws.',
    'vidyalms.certificate_element.warning.unresolved_token' =>
        'Nothing on this site answers {token}, so it will be printed as written.',

    'vidyalms.certificate_template.warning.no_elements' => 'This template draws nothing yet.',
    'vidyalms.certificate_template.error.no_readable_verification' =>
        'Add a verification code or a text element carrying the verification address. '
        . 'A QR code on its own cannot be read aloud.',
    'vidyalms.certificate_template.error.unknown_element' =>
        'The "{element}" element is not installed on this site.',
    'vidyalms.certificate_template.error.unsupported_payload_version' =>
        'The "{element}" element was saved by a newer version ({version}) than this site can read.',

    // Versioned editable certificate blueprint copy. This catalogue does not
    // seed, expose or publish the designs; clone materialization translates it.
    'vidyalms.certificate_blueprint.executive_classic.name' => 'Executive Classic',
    'vidyalms.certificate_blueprint.executive_classic.description' =>
        'A fine double frame, midnight-blue serif typography and a balanced ceremonial layout.',
    'vidyalms.certificate_blueprint.modern_professional.name' => 'Modern Professional',
    'vidyalms.certificate_blueprint.modern_professional.description' =>
        'Confident left-aligned typography, a quiet teal frame and generous space for achievement.',
    'vidyalms.certificate_blueprint.minimal_academic.name' => 'Minimal Academic',
    'vidyalms.certificate_blueprint.minimal_academic.description' =>
        'An unframed academic design with refined serif names, clear hierarchy and ample white space.',
    'vidyalms.certificate_blueprint.category.executive' => 'Executive',
    'vidyalms.certificate_blueprint.prototype_classic.name' => 'The Classic',
    'vidyalms.certificate_blueprint.prototype_classic.description' =>
        'Warm ivory, a delicate double gold frame, centered serif typography and a signature rule. Timeless recognition for every milestone.',
    'vidyalms.certificate_blueprint.prototype_modern.name' => 'Modern Scholar',
    'vidyalms.certificate_blueprint.prototype_modern.description' =>
        'A crisp white canvas, burnt-orange accent bands and bold, left-aligned Bricolage Grotesque. Made for modern learning.',
    'vidyalms.certificate_blueprint.prototype_executive.name' => 'Executive Edition',
    'vidyalms.certificate_blueprint.prototype_executive.description' =>
        'A full midnight canvas, fine gold frame and luminous ivory typography. Distinguished recognition for exceptional achievement.',
    'vidyalms.certificate_blueprint.achievement_title' => 'Certificate of achievement',
    'vidyalms.certificate_blueprint.proudly_presented' => 'Proudly presented to',
    'vidyalms.certificate_blueprint.layer.page_background' => 'Page background',
    'vidyalms.certificate_blueprint.layer.accent_band' => 'Accent band',
    'vidyalms.certificate_blueprint.layer.issuer' => 'Issuer name',
    'vidyalms.certificate_blueprint.layer.heading' => 'Certificate heading',
    'vidyalms.certificate_blueprint.layer.introduction' => 'Recipient introduction',
    'vidyalms.certificate_blueprint.layer.recipient' => 'Learner name',
    'vidyalms.certificate_blueprint.layer.completion' => 'Completion introduction',
    'vidyalms.certificate_blueprint.layer.course' => 'Course title',
    'vidyalms.certificate_blueprint.layer.signature_rule' => 'Signature rule',
    'vidyalms.certificate_blueprint.layer.signatory' => 'Authorized issuer',
    'vidyalms.certificate_blueprint.layer.date' => 'Issue date',
    'vidyalms.certificate_blueprint.layer.verification' => 'Verification code and address',
    'vidyalms.certificate_blueprint.layer.qr' => 'Verification QR',
    'vidyalms.certificate_element.colour_panel' => 'Colour panel',
    'vidyalms.certificate_element.error.panel_colour' => 'Use a six-digit hexadecimal colour and a rule width of 1-20 pt, or leave the width empty for a solid panel.',
    'vidyalms.certificate_element.field.rule_width' => 'Rule thickness (pt; empty for a filled panel)',
    'vidyalms.certificate_blueprint.aureate.name' => 'Aureate',
    'vidyalms.certificate_blueprint.aureate.description' =>
        'Midnight framing, fine gold detailing and a ceremonial serif name. A distinguished finish for executive learning.',
    'vidyalms.certificate_blueprint.evergreen.name' => 'Evergreen',
    'vidyalms.certificate_blueprint.evergreen.description' =>
        'Deep teal typography, a soft sage frame and confident left alignment. Made for modern professional milestones.',
    'vidyalms.certificate_blueprint.atelier.name' => 'Atelier',
    'vidyalms.certificate_blueprint.atelier.description' =>
        'Warm terracotta, an editorial two-column layout and expressive typography. A fresh take on academic recognition.',
    'vidyalms.certificate_blueprint.certificate_title' => 'Certificate',
    'vidyalms.certificate_blueprint.completion_kicker' => 'O F   C O M P L E T I O N',
    'vidyalms.certificate_blueprint.editorial_title' => "Certificate\nof completion",
    'vidyalms.certificate_blueprint.milestone' => 'A milestone worth celebrating.',
    'vidyalms.certificate_blueprint.category.professional' => 'Professional',
    'vidyalms.certificate_blueprint.category.academic' => 'Academic',
    'vidyalms.certificate_blueprint.completion_title' => 'Certificate of Completion',
    'vidyalms.certificate_blueprint.presented_to' => 'Presented to',
    'vidyalms.certificate_blueprint.completed_course' => 'For successfully completing',
    'vidyalms.certificate_blueprint.issued_on' => 'Issued on',
    'vidyalms.certificate_blueprint.qr_alt' => 'Scan to verify this certificate. The address is also printed as text.',
    'vidyalms.certificate_renderer.pdf' => 'PDF document',
    'vidyalms.certificate_pdf.unsupported' => 'PDF compatibility: {reason}',
    'vidyalms.certificate_pdf.reason.source' => 'Use an element template containing one to eight pages.',
    'vidyalms.certificate_pdf.reason.paper' => 'Use paper sizes of 50-420 mm and at most 100 elements per page.',
    'vidyalms.certificate_pdf.reason.element' => 'This element or payload version has no structured PDF support.',
    'vidyalms.certificate_pdf.reason.bounds' => 'An element extends beyond its page.',
    'vidyalms.certificate_pdf.reason.style' => 'An element has an unsupported PDF style, image path or QR size.',
    'vidyalms.certificate_pdf.reason.asset' => 'Image paths must identify local storage files, never URLs.',
    'vidyalms.certificate_pdf.reason.unavailable' =>
        'This site has no PDF renderer. Choose the Designed certificate renderer, then publish again.',

    // The AI seams' own strings: every provider label, and the three
    // disclosure audiences of every placement. Ninety-two keys resolved to
    // nothing before this: GET /ai/placements answered with the raw keys in
    // its disclosure block, so the Article 50 text a learner is owed before
    // the first interaction was a dotted identifier.
    'ai.acceptor.alt_text.label' => 'Write the draft as alternative text',
    'ai.acceptor.course_outline.label' => 'Write the draft as a course outline',
    'ai.acceptor.feedback_text.label' => 'Write the draft as feedback, never as a mark',
    'ai.acceptor.lesson.label' => 'Write the draft as a lesson',
    'ai.acceptor.module.label' => 'Write the draft as a module',
    'ai.acceptor.question.label' => 'Write the draft as a question',
    'ai.bridge.null.label' => 'No model (nothing leaves this site)',
    'ai.disclosure.admin_narrative.at_read_time' => 'This narrative was produced by AI from the report\'s own rows.',
    'ai.disclosure.admin_narrative.at_review' => 'This narrative was produced by AI. You are the person accepting it.',
    'ai.disclosure.admin_narrative.before_interaction' => 'Report narratives here are produced by AI from the report\'s own rows. Read them before you circulate anything.',
    'ai.disclosure.gradebook_feedback.at_read_time' => 'This feedback was drafted by AI and accepted by your marker.',
    'ai.disclosure.gradebook_feedback.at_review' => 'This feedback was drafted by AI. It carries no mark, and you are the person accepting it.',
    'ai.disclosure.gradebook_feedback.before_interaction' => 'Feedback drafted here is produced by AI and is never a mark. Read it before you accept it.',
    'ai.disclosure.gradebook_grouping.at_read_time' => 'Answers were grouped with the help of AI before a person marked them.',
    'ai.disclosure.gradebook_grouping.at_review' => 'This grouping was proposed by AI. You are the person confirming it.',
    'ai.disclosure.gradebook_grouping.before_interaction' => 'Answer groupings here are proposed by AI. Confirm them before you mark on them.',
    'ai.disclosure.import_review.at_read_time' => 'Some column mappings were suggested by AI and accepted by a person.',
    'ai.disclosure.import_review.at_review' => 'This suggestion was produced by AI. You are the person accepting it.',
    'ai.disclosure.import_review.before_interaction' => 'Mapping suggestions here are produced by AI. Check them before you run the import.',
    'ai.disclosure.player_explain_mistake.at_read_time' => 'This explanation was produced by AI.',
    'ai.disclosure.player_explain_mistake.at_review' => 'This explanation was produced by AI and shown to the learner.',
    'ai.disclosure.player_explain_mistake.before_interaction' => 'This explanation is produced by AI. It can be wrong, and it never changes your mark.',
    'ai.disclosure.workbench_curriculum.at_read_time' => 'Part of this curriculum was drafted by AI and accepted by a person.',
    'ai.disclosure.workbench_curriculum.at_review' => 'This draft was produced by AI. You are the person accepting it.',
    'ai.disclosure.workbench_curriculum.before_interaction' => 'Curriculum drafts on this screen are produced by AI. Read them before you accept anything.',
    'ai.disclosure.workbench_lesson_editor.at_read_time' => 'Part of this lesson was drafted by AI and accepted by a person.',
    'ai.disclosure.workbench_lesson_editor.at_review' => 'This draft was produced by AI. You are the person accepting it.',
    'ai.disclosure.workbench_lesson_editor.before_interaction' => 'Lesson drafts on this screen are produced by AI. Read them before you accept anything.',
    'ai.disclosure.workbench_question_bank.at_read_time' => 'Some questions here were drafted by AI and accepted by a person.',
    'ai.disclosure.workbench_question_bank.at_review' => 'This draft was produced by AI. You are the person accepting it.',
    'ai.disclosure.workbench_question_bank.before_interaction' => 'Question drafts on this screen are produced by AI. Read them before you accept anything.',
    'ai.disclosure.workbench_question_editor.at_read_time' => 'Part of this question was drafted by AI and accepted by a person.',
    'ai.disclosure.workbench_question_editor.at_review' => 'This field was filled by AI. You are the person accepting it.',
    'ai.disclosure.workbench_question_editor.before_interaction' => 'Fields filled on this screen are produced by AI. Read them before you save.',
    'ai.fact.assignment_brief.label' => 'The assignment brief',
    'ai.fact.attempt_closed.label' => 'Whether this attempt has been closed',
    'ai.fact.attempt_recorded_mark.label' => 'The mark recorded for this attempt',
    'ai.fact.bank_sibling_digests.label' => 'Digests of the other items in the bank',
    'ai.fact.bank_sibling_vectors.label' => 'Similarity vectors for the other items in the bank',
    'ai.fact.grade_recorded_mark.label' => 'The mark recorded for this answer',
    'ai.fact.item_author_rationale.label' => 'The author\'s own note about the answer',
    'ai.fact.item_definition.label' => 'The item definition',
    'ai.fact.item_discrimination.label' => 'How well the item separates learners',
    'ai.fact.item_facility.label' => 'How many learners answered the item correctly',
    'ai.fact.item_non_response.label' => 'How many learners left the item blank',
    'ai.fact.item_option_frequencies.label' => 'How often each option was chosen',
    'ai.fact.item_stem_redacted.label' => 'The item stem, with the answer removed',
    'ai.fact.report_dataset.label' => 'The rows of a report that has already run',
    'ai.fact.response_body.label' => 'What one learner wrote',
    'ai.fact.response_body_set.label' => 'What every learner wrote for this question',
    'ai.fact.submission_body.label' => 'The text of one assignment submission',
    'ai.fact.submission_rubric.label' => 'The rubric this submission is marked against',
    'ai.placement.admin_narrative.label' => 'Report narrative helpers',
    'ai.placement.admin_narrative.description' => 'Draft plain-language summaries of report data for you to review before sharing with stakeholders.',
    'ai.placement.gradebook_feedback.label' => 'Feedback helpers',
    'ai.placement.gradebook_feedback.description' => 'Suggest feedback on learner submissions for instructors to edit and save. Scores remain the instructor\'s decision.',
    'ai.placement.gradebook_grouping.label' => 'Answer grouping helpers',
    'ai.placement.gradebook_grouping.description' => 'Suggest groups of similar answers so instructors can review common patterns and assess responses more efficiently.',
    'ai.placement.import_review.label' => 'Import review helpers',
    'ai.placement.import_review.description' => 'Suggest how imported content maps to VidyaLMS fields and flag issues for review before you confirm an import.',
    'ai.placement.player_explain_mistake.label' => 'Explain my mistake',
    'ai.placement.player_explain_mistake.description' => 'Help learners understand a mistake when reviewing an attempt and suggest what to revisit. Explanations do not change their result.',
    'ai.placement.workbench_curriculum.label' => 'Curriculum helpers',
    'ai.placement.workbench_curriculum.description' => 'Suggest course outlines, modules and curriculum improvements for authors to review while planning a course.',
    'ai.placement.workbench_lesson_editor.label' => 'Lesson editor helpers',
    'ai.placement.workbench_lesson_editor.description' => 'Help authors draft and improve lesson content, adapt wording and suggest image descriptions. Authors review changes before publishing.',
    'ai.placement.workbench_question_bank.label' => 'Question bank helpers',
    'ai.placement.workbench_question_bank.description' => 'Help authors draft questions and rubrics, review question quality and check suggested additions before accepting them into a bank.',
    'ai.placement.workbench_question_editor.label' => 'Question editor helpers',
    'ai.placement.workbench_question_editor.description' => 'Suggest improvements to question wording, distractors and feedback. Authors choose what to keep and save the question themselves.',
    'ai.policy.attempt_state.label' => 'The attempt has been submitted and marked',
    'ai.policy.content_scope.label' => 'This person may already open this course',
    'ai.policy.per_principal_preview_rate.label' => 'Preview rate limit for one person',
    'ai.policy.per_principal_rate.label' => 'Rate limit for one person',
    'ai.policy.per_run_calls.label' => 'Call ceiling for one run',
    'ai.policy.per_run_cost.label' => 'Cost ceiling for one run',
    'ai.policy.per_scope_calls.label' => 'Call ceiling for this course or organisation',
    'ai.policy.per_scope_spend.label' => 'Spend ceiling for this course or organisation',
    'ai.policy.placement_enabled.label' => 'AI is enabled for this panel',
    'ai.policy.policy_accepted.label' => 'This person has accepted the data-use policy',
    'ai.policy.route_available.label' => 'A model is configured for this task',
    'ai.policy.subject_scope.label' => 'The item is inside the course this person was authorised for',
    'ai.recipe.structural_build.label' => 'Build a course outline and its lessons',
    'ai.redactor.strict.label' => 'Strict redaction',
    'ai.surface.admin.label' => 'Administration',
    'ai.surface.gradebook.label' => 'Gradebook',
    'ai.surface.import_review.label' => 'Import review',
    'ai.surface.instructor_workbench.label' => 'Instructor workbench',
    'ai.surface.learner_player.label' => 'Learner player',
    'ai.surface.question_bank.label' => 'Question bank',
    'ai.task.check_accessibility.label' => 'Check accessibility',
    'ai.task.check_answer_leak.label' => 'Check whether the stem gives the answer away',
    'ai.task.check_citation_coverage.label' => 'Check that claims carry citations',
    'ai.task.check_injection_markers.label' => 'Check for prompt injection markers',
    'ai.task.check_item_writing_flaws.label' => 'Check for item writing flaws',
    'ai.task.check_near_duplicate.label' => 'Check for near duplicates in the bank',
    'ai.task.check_readability.label' => 'Check readability',
    'ai.task.check_schema_validity.label' => 'Check the draft against its question type',
    'ai.task.grading_group_equivalent_answers.label' => 'Group equivalent answers',
    'ai.task.outline_from_headings.label' => 'Draft an outline from the headings',
    'ai.task.question_template_aig.label' => 'Generate questions from a template',
    'vidyalms.credit_scheme.field.contact_minutes' => 'Contact time (minutes)',
    'vidyalms.credit_scheme.field.awarding_body_id' => 'Awarding body ID',
    'vidyalms.credit_scheme.field.field_of_study' => 'Field of study',
    'vidyalms.credit_scheme.field.sponsor_id' => 'Sponsor ID',
    'vidyalms.credit_scheme.field.delivery_method' => 'Delivery method',
    'vidyalms.credit_scheme.field.increment' => 'Credit increment',
    'vidyalms.credit_scheme.field.amount' => 'Credit amount',
    'vidyalms.credit_scheme.field.credit_type' => 'Credit type',
    'vidyalms.credit_scheme.field.board' => 'Certifying board',
    'vidyalms.credit_scheme.option.group_live' => 'Group live',
    'vidyalms.credit_scheme.option.group_internet_based' => 'Group internet based',
    'vidyalms.credit_scheme.option.self_study' => 'Self study',
    'vidyalms.credit_scheme.option.nano_learning' => 'Nano learning',
    'vidyalms.credit_scheme.option.one_fifth' => 'One fifth of a credit',
    'vidyalms.credit_scheme.option.one_half' => 'One half of a credit',
    'vidyalms.credit_scheme.option.ABA' => 'ABA',
    'vidyalms.credit_scheme.option.ABIM' => 'ABIM',
    'vidyalms.credit_scheme.option.ABO' => 'ABO',
    'vidyalms.credit_scheme.option.ABOHNS' => 'ABOHNS',
    'vidyalms.credit_scheme.option.ABPath' => 'ABPath',
    'vidyalms.credit_scheme.option.ABP' => 'ABP',
    'vidyalms.credit_scheme.option.ABS' => 'ABS',
] + require __DIR__ . '/../src/I18n/ui.php';
