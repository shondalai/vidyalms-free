import type {
    BankEntry,
    BankEntryVersion,
    ItemAnalysisReport,
    ItemAnalysisRow,
    QuestionCategory,
    QuizSettingsSchema,
    QuizSlot,
    QuizSlotTree,
    RegradeResult,
    Rubric,
    RubricVersion,
} from '../lib/assessment-types';

/*
 * Wave 3b fixtures, shaped exactly like the contract's answers.
 *
 * Kept beside the shared harness rather than inside it, so this wave adds
 * fixtures without editing a file every other wave is also writing to.
 *
 * The settings schema fixture matters most: it is what proves the policy
 * form is drawn from the registry rather than from a field list, so it
 * carries one field of every type the contract declares, one enum whose
 * chosen option takes parameters, and one field whose stored provider this
 * site no longer has.
 */

export function makeSettingsSchema(overrides: Partial<QuizSettingsSchema> = {}): QuizSettingsSchema {
    return {
        quiz_id: 21,
        groups: [
            {
                key: 'behaviour',
                label: 'Marking behaviour',
                description: 'How and when answers are marked.',
                fields: [
                    {
                        key: 'behaviour',
                        label: 'Behaviour',
                        description: 'Everything is marked when the learner submits.',
                        type: 'enum',
                        default: 'deferred_feedback',
                        current: 'deferred_feedback',
                        options: [
                            { key: 'deferred_feedback', label: 'Marked on submission', group: 'Standard' },
                            { key: 'manual', label: 'Marked by a person', group: 'Standard' },
                        ],
                    },
                    {
                        key: 'navigation_mode',
                        label: 'Navigation',
                        type: 'enum',
                        default: 'nonlinear',
                        current: 'nonlinear',
                        options: [
                            { key: 'nonlinear', label: 'Free movement' },
                            { key: 'linear', label: 'Forward only' },
                        ],
                    },
                    {
                        key: 'shuffle_questions',
                        label: 'Shuffle the questions',
                        type: 'boolean',
                        default: false,
                        current: true,
                    },
                ],
            },
            {
                key: 'attempts',
                label: 'Attempts',
                fields: [
                    {
                        key: 'attempt_aggregation',
                        label: 'Reportable grade',
                        type: 'enum',
                        default: 'highest',
                        current: 'average_best_n',
                        params_key: 'attempt_aggregation_params',
                        options: [
                            { key: 'highest', label: 'Highest attempt' },
                            {
                                key: 'average_best_n',
                                label: 'Average of the best n',
                                params_schema: {
                                    type: 'object',
                                    properties: {
                                        n: { type: 'integer', title: 'How many', minimum: 1 },
                                    },
                                },
                            },
                        ],
                    },
                    {
                        key: 'negative_marking_policy',
                        label: 'Negative marking',
                        type: 'enum',
                        default: null,
                        current: 'formula_scoring',
                        available: false,
                        unavailable_reason:
                            'The package that provides "formula_scoring" is not installed on this site.',
                        options: [{ key: 'none', label: 'Off' }],
                    },
                    {
                        key: 'grace_seconds',
                        label: 'Grace after the deadline',
                        type: 'duration',
                        default: 60,
                        current: 120,
                        minimum: 60,
                    },
                    {
                        key: 'closes_at',
                        label: 'Closes at',
                        type: 'datetime',
                        default: null,
                        current: null,
                    },
                    {
                        key: 'item_session_control',
                        label: 'Per question',
                        type: 'object',
                        fields: [
                            {
                                key: 'max_attempts',
                                label: 'Tries per question',
                                type: 'integer',
                                default: 1,
                                current: 1,
                                minimum: 0,
                                maximum: 10,
                            },
                            {
                                key: 'allow_skipping',
                                label: 'Allow skipping',
                                type: 'boolean',
                                default: true,
                                current: true,
                            },
                        ],
                    },
                ],
            },
        ],
        ...overrides,
    };
}

export function makeBankVersion(overrides: Partial<BankEntryVersion> = {}): BankEntryVersion {
    return {
        stimulus_id: null,
        id: 501,
        bank_entry_id: 301,
        version: 2,
        question_id: 901,
        status: 'ready',
        type: 'single_choice',
        definition_version: 1,
        points: 1,
        definition: { prompt: 'Which WCAG level does the directive name?' },
        attempts_served: 12,
        created_at: '2026-08-01 09:00:00',
        ...overrides,
    };
}

export function makeBankEntry(overrides: Partial<BankEntry> = {}): BankEntry {
    return {
        id: 301,
        bank_id: 11,
        owner_scope: 'course',
        owner_id: 1,
        category_id: 41,
        status: 'ready',
        difficulty_hint: 'medium',
        estimated_time_seconds: 90,
        created_by: 7,
        created_at: '2026-07-01 09:00:00',
        updated_at: '2026-08-01 09:00:00',
        latest_version: makeBankVersion(),
        versions_count: 2,
        usage_count: 1,
        tags: ['wcag', 'directive'],
        objectives: [],
        ...overrides,
    };
}

export function makeCategories(): QuestionCategory[] {
    return [
        {
            id: 41,
            bank_id: 11,
            owner_scope: 'course',
            owner_id: 1,
            parent_id: null,
            name: 'Perceivable',
            position: 1,
            entry_count: 4,
            children: [
                {
                    id: 42,
                    bank_id: 11,
                    owner_scope: 'course',
                    owner_id: 1,
                    parent_id: 41,
                    name: 'Captions',
                    position: 1,
                    entry_count: 2,
                    children: [],
                },
            ],
        },
    ];
}

export function makeSlot(overrides: Partial<QuizSlot> & { id: number }): QuizSlot {
    return {
        quiz_id: 21,
        parent_id: null,
        position: 1,
        kind: 'question',
        title: null,
        bank_entry_id: 301,
        question_version_id: null,
        question: makeBankVersion(),
        points: 1,
        weight: 1,
        required: false,
        fixed: false,
        visible: true,
        keep_together: true,
        selection: null,
        ordering: null,
        pool: null,
        policy_key: null,
        policy_params: null,
        children: [],
        ...overrides,
    };
}

export function makeSlotTree(overrides: Partial<QuizSlotTree> = {}): QuizSlotTree {
    return {
        quiz_id: 21,
        slots: [
            makeSlot({
                id: 601,
                kind: 'section',
                title: 'Warm up',
                position: 1,
                bank_entry_id: null,
                question: null,
                selection: { select: 2, with_replacement: false },
                ordering: { shuffle: true },
                children: [
                    makeSlot({ id: 602, position: 1, parent_id: 601 }),
                    makeSlot({
                        id: 603,
                        position: 2,
                        parent_id: 601,
                        kind: 'pool',
                        bank_entry_id: null,
                        question: null,
                        pool: {
                            count: 2,
                            strategy: 'uniform',
                            query: { kind: 'tag', operator: 'eq', value: 'wcag' },
                        },
                    }),
                ],
            }),
        ],
        warnings: [
            {
                check: 'pool_size',
                message: 'The pool holds fewer than three times the draw count.',
                severity: 'warning',
                slot_id: 603,
            },
            {
                check: 'cooldown_on_single_attempt',
                message: 'A cooldown is configured on a quiz that allows one attempt.',
                severity: 'warning',
                slot_id: null,
            },
        ],
        // The three provider-keyed fields as the site serves them, so no key
        // on the editing screen is typed from memory.
        vocabularies: {
            selection_strategies: [
                { key: 'uniform', label: 'Draw uniformly' },
                { key: 'stratified_by_difficulty', label: 'Draw a set number from each difficulty band' },
            ],
            exposure_controls: [{ key: 'randomesque', label: 'Draw from a rotating window of the pool' }],
            pool_predicates: [
                { key: 'tag', label: 'Tag' },
                { key: 'irt_difficulty_between', label: 'Measured difficulty between' },
            ],
        },
        ...overrides,
    };
}

export function makeRubricVersion(overrides: Partial<RubricVersion> = {}): RubricVersion {
    return {
        id: 71,
        rubric_id: 61,
        version: 3,
        created_at: '2026-08-20 09:00:00',
        max_points: 6,
        criteria: [
            {
                id: 81,
                position: 1,
                description: 'Accuracy',
                weight: 1,
                levels: [
                    { id: 91, position: 1, points: 0, descriptor: 'Not shown' },
                    { id: 92, position: 2, points: 2, descriptor: 'Mostly right' },
                    { id: 93, position: 3, points: 3, descriptor: 'Right throughout' },
                ],
            },
            {
                id: 82,
                position: 2,
                description: 'Clarity',
                weight: 1,
                levels: [
                    { id: 94, position: 1, points: 0, descriptor: 'Hard to follow' },
                    { id: 95, position: 2, points: 3, descriptor: 'Clear' },
                ],
            },
        ],
        ...overrides,
    };
}

export function makeRubric(overrides: Partial<Rubric> = {}): Rubric {
    return {
        id: 61,
        owner_scope: 'course',
        owner_id: 1,
        name: 'Short written answer',
        kind: 'analytic',
        min_score_is_zero: true,
        latest_version: makeRubricVersion(),
        versions_count: 3,
        ...overrides,
    };
}

export function makeRegradeResult(overrides: Partial<RegradeResult> = {}): RegradeResult {
    return {
        policy: 'both_keys',
        dry_run: true,
        attempts_examined: 12,
        attempts_changed: 2,
        grades_appended: 0,
        questions_regraded: 1,
        items: [
            {
                attempt_id: 71,
                user_id: 41,
                attempt_number: 1,
                score_before: 6,
                score_after: 8,
                percentage_before: 60,
                percentage_after: 80,
                passed_before: false,
                passed_after: true,
                questions_changed: 1,
            },
        ],
        ...overrides,
    };
}

export function makeItemAnalysisRow(overrides: Partial<ItemAnalysisRow> = {}): ItemAnalysisRow {
    return {
        question_id: 901,
        question_version_id: 501,
        bank_entry_id: 301,
        version: 2,
        quiz_id: 21,
        position: 1,
        type: 'single_choice',
        prompt: 'Which WCAG level does the directive name?',
        policy_key: 'match_correct',
        n: 42,
        facility: 71.4,
        facility_ci_low: 62,
        facility_ci_high: 80,
        standard_deviation: 45.2,
        discrimination: 41.8,
        discrimination_band: 'excellent',
        discriminative_efficiency: 62,
        intended_weight: 10,
        effective_weight: 12,
        below_threshold: false,
        distractors: [
            {
                option_key: 'aa',
                label: 'Level AA',
                keyed: true,
                rate_upper: 92,
                rate_lower: 41,
                point_biserial: 0.44,
                flagged: false,
            },
            {
                option_key: 'a',
                label: 'Level A',
                keyed: false,
                rate_upper: 8,
                rate_lower: 12,
                point_biserial: 0.21,
                flagged: true,
                flag_reason: 'Positive correlation with the total score.',
            },
        ],
        ...overrides,
    };
}

export function makeItemAnalysis(overrides: Partial<ItemAnalysisReport> = {}): ItemAnalysisReport {
    return {
        computed: true,
        computed_by: 'classical',
        reason: null,
        min_responses: 30,
        internal_consistency: 78.3,
        items: [makeItemAnalysisRow()],
        total: 1,
        page: 1,
        per_page: 25,
        ...overrides,
    };
}
