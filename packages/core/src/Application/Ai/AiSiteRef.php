<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Port\Random\RandomPort;

/**
 * The salt every pseudonym on this site is minted with.
 *
 * A caller crosses a model boundary as hash(siteRef . userId) rather than as a
 * user id, a name or an email, and the salt is what stops a shared model host
 * correlating one learner across installations. A shipped constant would defeat
 * that entirely: every VidyaLMS site would mint the same digest for user 42, and
 * the pseudonym would be a global identifier wearing a hash.
 *
 * So it is minted once, per install, on first use, and stored in the settings
 * row. It is deliberately not derived from the site name or the site URL, both
 * of which are public and both of which change.
 *
 * It is also never rotated automatically. Rotating it would silently break every
 * stored inputs digest's relationship to the caller it names, which is the thing
 * an audit uses to prove who made a request; a site that genuinely needs a new
 * salt changes the setting and accepts that older runs pseudonymise under the
 * old one, which is a decision a person makes rather than a side effect of an
 * upgrade.
 */
final class AiSiteRef
{
    /** The settings key the salt is stored under. */
    public const SETTING = 'ai.site_ref';

    public function __construct(
        private readonly Settings $settings,
        private readonly RandomPort $random,
    ) {
    }

    public function value(): string
    {
        $stored = $this->settings->getString(self::SETTING, '');

        if ($stored !== '') {
            return $stored;
        }

        $minted = hash('sha256', $this->random->uuid());
        $this->settings->set(self::SETTING, $minted);

        return $minted;
    }
}
