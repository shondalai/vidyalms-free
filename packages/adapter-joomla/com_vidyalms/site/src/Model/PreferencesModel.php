<?php
/**
 * @package     VidyaLMS
 * @subpackage  Site
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Site\Model;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRouteResult;

/**
 * The preference centre's two calls.
 *
 * The route is /me/notification-preferences and takes no user id, so this
 * model cannot read or write anybody else's rows however it is called. A guest
 * is answered 401 by the core rather than refused here, which keeps the one
 * rule in the one place.
 *
 * The write is a whole-list replacement, which is what makes it idempotent:
 * posting the same form twice leaves the same rows.
 *
 * @since 1.0.0
 */
final class PreferencesModel extends KernelModel
{
    private const PATH = '/me/notification-preferences';

    private const FREQUENCIES = ['immediate', 'daily', 'weekly', 'never'];

    private ?CoreRouteResult $centre = null;

    public function centre(): CoreRouteResult
    {
        if ($this->centre instanceof CoreRouteResult) {
            return $this->centre;
        }

        return $this->centre = CoreRoute::call('GET', self::PATH, $this->principal());
    }

    /**
     * Replaces the caller's rows and answers the centre as it now stands, so
     * the page redraws from the core's answer rather than from what it posted.
     *
     * @param list<array{category_key: string, channel_key: string, frequency: string}> $rows
     */
    public function replace(array $rows): CoreRouteResult
    {
        $result = CoreRoute::call('PUT', self::PATH, $this->principal(), ['rows' => $rows]);

        if ($result->ok()) {
            $this->centre = $result;
        }

        return $result;
    }

    /**
     * The rows the form posted, filtered to shapes the contract accepts.
     *
     * A frequency the contract does not name is dropped rather than sent: the
     * core answers 422 and writes nothing for the whole list, so one mangled
     * field would otherwise lose every other change on the form.
     *
     * @return list<array{category_key: string, channel_key: string, frequency: string}>
     */
    public function postedRows(): array
    {
        $raw = Factory::getApplication()->getInput()->post->get('preference', [], 'array');

        $rows = [];

        foreach ((array) $raw as $key => $frequency) {
            if (!\is_string($key) || !\is_string($frequency)) {
                continue;
            }

            if (!\in_array($frequency, self::FREQUENCIES, true)) {
                continue;
            }

            // The form names each control "preference[<category>|<channel>]",
            // because a nested name would arrive as an array of arrays and the
            // pair is one control's identity rather than two.
            $parts = explode('|', $key, 2);

            if (\count($parts) !== 2) {
                continue;
            }

            [$category, $channel] = $parts;

            if (preg_match('/^[a-z0-9_.-]{1,64}$/', $category) !== 1) {
                continue;
            }

            if (preg_match('/^[a-z0-9_.-]{1,64}$/', $channel) !== 1) {
                continue;
            }

            $rows[] = [
                'category_key' => $category,
                'channel_key'  => $channel,
                'frequency'    => $frequency,
            ];
        }

        return $rows;
    }
}
