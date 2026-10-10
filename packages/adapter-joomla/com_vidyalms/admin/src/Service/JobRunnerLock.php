<?php

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

/** @license GNU General Public License version 3 or later; see LICENSE */
final class JobRunnerLock
{
    public function __construct(private readonly DatabaseInterface $db, private readonly string $siteRoot)
    {
    }

    /** @param \Closure(): int $work */
    public function run(\Closure $work): ?int
    {
        $name = 'vlms-jobs-' . sha1($this->siteRoot . ':' . $this->db->replacePrefix('#__vidyalms_joomla_jobs'));
        $query = $this->db->createQuery()->select('GET_LOCK(:lock, 0)')->bind(':lock', $name);
        if ((int) $this->db->setQuery($query)->loadResult() !== 1) {
            return null;
        }
        try {
            return $work();
        } finally {
            $query = $this->db->createQuery()->select('RELEASE_LOCK(:lock)')->bind(':lock', $name);
            $this->db->setQuery($query)->loadResult();
        }
    }
}
