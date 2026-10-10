// The leaf module, never '@/shared/availability': that one imports this file to register the free renderers.
import { availabilityStrings, text, list } from '@/shared/availability-text';
import { REASON_RENDERER_CONTRACT } from './contract';
import type { ReasonRenderContext, ReasonRendererRegistryApi } from './contract';

/*
 * The eight free reason keys, registered through the same registry a paid or
 * third-party package registers into.
 *
 * They are registrations rather than a switch so that the free build's own
 * wording sits on exactly the seam every other package uses: nothing here is
 * privileged, replace() can decorate any one of them, and a package that adds
 * a condition adds its sentence the day it installs. The fallback is
 * unchanged and is still the honest default for a key nothing claims.
 */

function parameters(context: ReasonRenderContext): Record<string, unknown> {
    return (context.access.reason_parameters ?? {}) as Record<string, unknown>;
}

const renderers: Record<string, (context: ReasonRenderContext) => string | null> = {
    'availability.date.from': (context) =>
        context.opensAtLocal === null
            ? availabilityStrings.notYet
            : availabilityStrings.availableOn(context.opensAtLocal),

    'availability.date.until': () => availabilityStrings.closed,

    'availability.completion.required': (context) => {
        const bag = parameters(context);
        const title = text(bag, 'title');
        if (title === null) {
            return availabilityStrings.completionUnnamed;
        }
        return text(bag, 'state') === 'complete_pass'
            ? availabilityStrings.completionPass(title)
            : availabilityStrings.completion(title);
    },

    'availability.completion.target_missing': () => availabilityStrings.targetMissing,

    'availability.score.min': (context) => {
        const bag = parameters(context);
        const title = text(bag, 'title');
        const min = text(bag, 'min');
        return title === null || min === null
            ? availabilityStrings.scoreUnnamed
            : availabilityStrings.score(title, min);
    },

    'availability.role.required': () => availabilityStrings.role,

    'availability.enrolment_source.required': (context) => {
        const sources = list(parameters(context), 'sources');
        return sources === null
            ? availabilityStrings.enrolmentSourceUnnamed
            : availabilityStrings.enrolmentSource(sources);
    },

    'availability.provider_missing': () => availabilityStrings.providerMissing,
};

/**
 * Idempotent, because the free bundle and a test file may both install the
 * bridge and a second register() on a taken key throws by design.
 */
export function registerFreeReasonRenderers(registry: ReasonRendererRegistryApi): void {
    Object.entries(renderers).forEach(([key, render]) => {
        if (registry.has(key)) {
            return;
        }
        registry.register(key, { version: REASON_RENDERER_CONTRACT, render });
    });
}
