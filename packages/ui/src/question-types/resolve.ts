import type { QuestionDefinition, QuestionTypeClientModule, QuestionTypeRegistryApi } from './contract';
import { fallbackModule } from './fallback';
import type { ResponseShape } from './shape';

/*
 * Resolution, in one place, because every consumer of the registry has to
 * agree on what a miss is. There are two misses and they are the same path:
 *
 *   an unregistered key
 *   a registered key whose renderer cannot read the served definition
 *   version
 *
 * The second is the one that would bite hardest and the one four server call
 * sites cannot see: a decorated key always resolves, so nothing throws, and
 * a renderer written for schema_version 1 would quietly read fields a
 * version 2 definition no longer has.
 */

export type ResolutionSource = 'registry' | 'fallback';

export interface ResolvedModule {
    module: QuestionTypeClientModule;
    source: ResolutionSource;
    /** True when the registered module declared no conformance kit result. */
    flagged: boolean;
    /** Set when a registered renderer was passed over for its version. */
    unsupportedVersion: number | null;
}

function definitionVersion(definition: QuestionDefinition, explicitVersion?: number | null): number {
    if (typeof explicitVersion === 'number' && Number.isInteger(explicitVersion) && explicitVersion > 0) {
        return explicitVersion;
    }
    const raw = definition.schema_version;
    return typeof raw === 'number' && Number.isInteger(raw) && raw > 0 ? raw : 1;
}

export function resolveQuestionModule(
    registry: QuestionTypeRegistryApi,
    typeId: string,
    definition: QuestionDefinition,
    shape: ResponseShape | null,
    explicitVersion?: number | null,
): ResolvedModule {
    const entry = registry.entry(typeId);
    if (entry === null) {
        return { module: fallbackModule(typeId, shape), source: 'fallback', flagged: false, unsupportedVersion: null };
    }
    const version = unsupportedVersionOf(registry, typeId, definition, explicitVersion);
    if (version !== null) {
        return {
            module: fallbackModule(typeId, shape),
            source: 'fallback',
            flagged: false,
            unsupportedVersion: version,
        };
    }
    return { module: entry.module, source: 'registry', flagged: entry.flagged, unsupportedVersion: null };
}

/**
 * The version a registered module cannot read, or null when it can.
 *
 * Split out because the authoring side needs the same answer and cannot use
 * the shape-keyed fallback: an authoring block that reads a definition it
 * does not understand shows the author the wrong form and then writes back
 * whatever that form produced, so the honest answer there is no block at all.
 */
export function unsupportedVersionOf(
    registry: QuestionTypeRegistryApi,
    typeId: string,
    definition: QuestionDefinition,
    explicitVersion?: number | null,
): number | null {
    const entry = registry.entry(typeId);
    if (entry === null) {
        return null;
    }
    const supported = entry.module.supportedDefinitionVersions?.() ?? [1];
    const version = definitionVersion(definition, explicitVersion);
    return supported.includes(version) ? null : version;
}

/** What the authoring side gets: a module, or the reason there is not one. */
export interface ResolvedAuthoring {
    module: QuestionTypeClientModule | null;
    flagged: boolean;
    /** Set when a registered module was passed over for its version. */
    unsupportedVersion: number | null;
}

/**
 * The authoring half of the same gate.
 *
 * Resolving straight off the registry meant supportedDefinitionVersions was
 * never consulted on the authoring side, which opens the case the gate exists
 * for: a decorated key always resolves, so after the newer client bundle is
 * removed the older module reads a definition written in a shape it does not
 * know, shows the author an empty or wrong block, and hands its
 * fromFormValues a definition stripped of everything the newer shape carried.
 */
export function resolveAuthoringModule(
    registry: QuestionTypeRegistryApi,
    typeId: string,
    definition: QuestionDefinition,
    explicitVersion?: number | null,
): ResolvedAuthoring {
    const entry = registry.entry(typeId);
    if (entry === null) {
        return { module: null, flagged: false, unsupportedVersion: null };
    }
    const version = unsupportedVersionOf(registry, typeId, definition, explicitVersion);
    if (version !== null) {
        return { module: null, flagged: false, unsupportedVersion: version };
    }
    return { module: entry.module, flagged: entry.flagged, unsupportedVersion: null };
}
