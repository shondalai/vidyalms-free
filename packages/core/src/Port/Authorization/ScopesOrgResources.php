<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Authorization;

/**
 * This host genuinely creates a per-organisation asset, so an "org:{id}"
 * question is a real answer rather than a site-wide one wearing a scope.
 *
 * An empty marker beside AuthorizationPort, on the same terms as
 * HoldersOfCapability and for the same reason: adding a required method to a
 * shipped port turns a core upgrade into a white screen on every site whose
 * adapter has not been rebuilt in the same minute.
 *
 * **It exists because a bare boolean cannot say "I do not scope this kind of
 * resource".** Both shipped adapters resolve an unrecognised resource string
 * to a refusal, which is right, but a refusal is indistinguishable from a
 * genuine no. An earlier draft of the organisations design made a positive
 * "org:{id}" answer a necessary condition and would therefore have refused
 * every caller on both hosts from the first boot, including the organisation's
 * own owner. So the question is not asked at all unless the port declares this
 * marker, and only its negative answer narrows.
 *
 * The core-owned org_role_assignments predicate remains the boundary in every
 * edition. This can tighten it and can never widen it.
 *
 * **Declared and unprobed today**, in the same words the empty registries use.
 * No use case anywhere asks the authorization port an "org:" question yet, so
 * nothing performs the instanceof this marker exists to gate, and the wave
 * that needs a host to narrow an organisation-owned asset is the wave that
 * writes the probe. Stated here rather than described as a mechanism, because
 * a capability interface with no probe is not a capability interface.
 *
 * Absent, which is what both shipped adapters are: the port is never asked an
 * "org:" question, and its answer would not be trusted if it gave one.
 */
interface ScopesOrgResources
{
}
