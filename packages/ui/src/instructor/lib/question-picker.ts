import type { components, QuestionTypeInfo } from '@vidyalms/api-sdk';
import type { QuestionDefinition } from '@/question-types';

/**
 * The preset shape as the contract states it. Named off `components` rather
 * than through a convenience alias, because the SDK's aliases are generated
 * and this file does not need one to be regenerated to compile.
 */
export type QuestionPresetInfo = components['schemas']['QuestionPresetInfo'];

/*
 * What the "Add question" picker offers, built from exactly what
 * GET /question-types answered: the registered types, and the presets
 * registered over them.
 *
 * A preset is the author-facing half of the catalogue. It creates no
 * provider key and stores no value in questions.type; it names a type, the
 * marking rule a new item of it gets, and a partial definition the form
 * starts from. That is what lets the picker say "Cloze" and "Matching" while
 * the stored type stays composite and associate, and it is the direct fix
 * for the failure every surveyed product committed: Moodle has three
 * calculated types for one idea, Canvas shipped three blank types before
 * merging them.
 *
 * Nothing here is Pro-aware and nothing here is a list. A free site answers
 * six types and their six aliases and the picker shows six entries; a site
 * with Pro answers more from the same route, which is why a type that is not
 * installed simply does not appear.
 */

/** The eight families, in the order the picker shows them. */
export const PICKER_FAMILIES = [
    'selection',
    'text_entry',
    'association',
    'graphic',
    'numeric',
    'artifact',
    'composite',
    'control',
] as const;

export type PickerFamily = (typeof PICKER_FAMILIES)[number];

export interface PickerEntry {
    /** The preset key, or the type key when the entry is the type itself. */
    key: string;
    label: string;
    family: string;
    /** What questions.type gets, which is always a registered type key. */
    type: string;
    /** The partial definition a new question of this entry starts from. */
    skeleton: QuestionDefinition;
    /** The policy a question created from this entry gets, or null for the type's own default. */
    policy: string | null;
    /**
     * The definition key the base type keeps its marking rule under.
     *
     * Asked of the type rather than assumed. A container type keeps its own
     * under "aggregation", because each part carries a "policy" of its own,
     * so seeding every preset's rule into "policy" wrote a field the
     * composite schema forbids and the composite type ignores: all five
     * composite presets named the rule twice, once where the engine reads it
     * and once where nothing does.
     */
    policyField: string;
}

export interface PickerGroup {
    family: string;
    entries: PickerEntry[];
}

/**
 * The entries, grouped by family and ordered inside it.
 *
 * Three rules, and each of them is a decision the API cannot make for us:
 *
 * A preset over a type this site does not have is dropped. The server does
 * not register one, but a client that trusted the pairing would open an
 * authoring form for a question the site cannot create.
 *
 * A preset whose key is its own type key is that type's author-facing name
 * (the free build registers six of them, one per type) and stands in for the
 * type rather than beside it, so a free site shows six entries and not
 * twelve. When a type has named presets of its own, that alias keeps its
 * skeleton and its marking rule but takes the type's own label back: two
 * entries reading "Sequencing" over one type, differing only in a marking
 * rule the picker does not show, is a picker nobody can use.
 *
 * Order inside a family is the type's declared order first, then the order
 * the presets arrived in. Presets have no order of their own on the wire,
 * and the registry hands them over in registration order, which is the
 * design's own table order.
 */
export function pickerGroups(types: QuestionTypeInfo[], presets: QuestionPresetInfo[]): PickerGroup[] {
    const byKey = new Map(types.map((type) => [type.key, type] as const));
    const overType = new Map<string, QuestionPresetInfo[]>();

    for (const preset of presets) {
        if (!byKey.has(preset.base_type_key)) {
            continue;
        }
        const existing = overType.get(preset.base_type_key);
        if (existing === undefined) {
            overType.set(preset.base_type_key, [preset]);
        } else {
            existing.push(preset);
        }
    }

    const ranked: Array<{ entry: PickerEntry; order: number; position: number }> = [];

    for (const type of types) {
        const own = overType.get(type.key) ?? [];
        const named = own.filter((preset) => preset.preset_key !== type.key);
        const alias = own.find((preset) => preset.preset_key === type.key) ?? null;

        // The alias keeps its skeleton and its marking rule either way; what
        // it gives up next to named presets is only the name, because the
        // name is what would collide.
        const head =
            alias === null
                ? entryOfType(type)
                : named.length === 0
                  ? entryOf(alias, type)
                  : { ...entryOf(alias, type), label: type.label };
        ranked.push({ entry: head, order: type.order, position: -1 });

        for (const preset of named) {
            ranked.push({ entry: entryOf(preset, type), order: type.order, position: presets.indexOf(preset) });
        }
    }

    const groups: PickerGroup[] = [];

    for (const family of familiesOf(ranked.map((row) => row.entry))) {
        const entries = ranked
            .filter((row) => row.entry.family === family)
            .sort((left, right) => left.order - right.order || left.position - right.position)
            .map((row) => row.entry);
        groups.push({ family, entries });
    }

    return groups;
}

/** Every entry in one flat list, in the same order the groups render. */
export function pickerEntries(types: QuestionTypeInfo[], presets: QuestionPresetInfo[]): PickerEntry[] {
    return pickerGroups(types, presets).flatMap((group) => group.entries);
}

export function findPickerEntry(entries: PickerEntry[], key: string): PickerEntry | null {
    return entries.find((entry) => entry.key === key) ?? null;
}

/**
 * The definition a new question of this entry starts from: the skeleton, and
 * the marking rule the preset names.
 *
 * The policy travels as a definition field because that is where the engine
 * reads it: a definition naming no policy grades under its type's legacy pin
 * rather than under the rule the author picked, which is how an item authored
 * as an opinion scale would come back marked.
 */
export function seedDefinition(entry: PickerEntry): QuestionDefinition {
    const seed: QuestionDefinition = { ...entry.skeleton };
    if (entry.policy === null) {
        return seed;
    }
    // Into the field the type named, and only when the skeleton has not
    // already written the rule there: a kprime skeleton carries its own
    // aggregation, and stamping the preset's default over it would name the
    // rule twice with nothing saying which wins.
    if (seed[entry.policyField] === undefined) {
        seed[entry.policyField] = { key: entry.policy, params: {} };
    }
    return seed;
}

/** The policy key under which a question scores nothing. */
export const SCORES_NOTHING = 'none';

/**
 * Whether a question created from this entry counts towards the attempt
 * totals.
 *
 * "scored" is a questions column rather than a definition field, and the
 * server pairs it with the policy: a definition resolving to `none` is
 * refused unless it is authored unscored. The picker is the only place that
 * knows the marking rule an entry names, so it is the picker that says an
 * opinion scale is unscored, and the add request carries scored false beside
 * the policy the seed already writes. An entry naming no policy of its own
 * grades under its type's default, and no free type defaults to `none`.
 */
export function seedScored(entry: PickerEntry): boolean {
    return entry.policy !== SCORES_NOTHING;
}

function entryOf(preset: QuestionPresetInfo, type: QuestionTypeInfo | undefined): PickerEntry {
    return {
        key: preset.preset_key,
        label: preset.label,
        family: preset.group,
        type: preset.base_type_key,
        skeleton: (preset.definition_skeleton ?? {}) as QuestionDefinition,
        policy: preset.default_policy,
        policyField: policyFieldOf(type),
    };
}

/** The rule field the type declares, defaulting to the near-universal one. */
function policyFieldOf(type: QuestionTypeInfo | undefined): string {
    const declared = (type as { policy_field?: unknown } | undefined)?.policy_field;
    return typeof declared === 'string' && declared !== '' ? declared : 'policy';
}

function entryOfType(type: QuestionTypeInfo): PickerEntry {
    return {
        key: type.key,
        label: type.label,
        family: type.group,
        type: type.key,
        skeleton: {},
        policy: null,
        policyField: policyFieldOf(type),
    };
}

/** The known families in their declared order, then anything a later package added. */
function familiesOf(entries: PickerEntry[]): string[] {
    const present = new Set(entries.map((entry) => entry.family));
    const known = PICKER_FAMILIES.filter((family) => present.has(family));
    const rest = [...present].filter((family) => !known.includes(family as PickerFamily)).sort();
    return [...known, ...rest];
}
