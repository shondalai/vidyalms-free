<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ReportDelivery;

/**
 * This delivery provider puts the artefact itself in the message.
 *
 * Neither shipped provider does: `download` hands back a signed expiring URL and
 * `notification-link` mails one. The marker exists before the first attaching
 * provider does, because the refusal it drives has to be true on the day such a
 * provider is registered rather than added afterwards, and because a provider
 * registered by a package this repository does not build is exactly the one that
 * would otherwise arrive with no rule in front of it.
 *
 * Default when absent: the provider is treated as link-delivering, which is what
 * the signed expiring URL is for. Revoking an organisation administrator's access
 * revokes the report; an attachment sitting in their inbox is not revoked by
 * anything.
 */
interface AttachesArtefact
{
}
