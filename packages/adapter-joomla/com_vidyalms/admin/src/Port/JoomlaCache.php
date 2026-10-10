<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Port;

defined('_JEXEC') or die;

use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Cache\Controller\OutputController;
use Joomla\CMS\Factory;
use Shondalai\VidyaLMS\Core\Port\Cache\CachePort;

/**
 * Read-through caching on Joomla's cache, group com_vidyalms.
 *
 * Joomla answers a miss with boolean false, which is also a legitimate cached
 * value, so every entry is stored inside a one-key envelope: the envelope's
 * presence, not the value, distinguishes a hit from a miss.
 *
 * Joomla expresses cache lifetimes in minutes; the port speaks seconds.
 *
 * **Caching is forced on for this group, and the site's own Caching setting
 * does not switch it off.** `Cache::__construct()` reads the global `caching`
 * option, which is `0` on a default Joomla, and then `store()` writes nothing
 * and `get()` answers false forever. Everything that reads this port then
 * degrades quietly, and one of those readers is `RateLimitMiddleware`: the
 * counter it keeps for the verification page, the credential routes and the
 * unsubscribe pair never increments, so the control looks present and refuses
 * nothing. A security control that silently does nothing is worse than one
 * that is absent, because nobody goes looking for it.
 *
 * The site's Caching setting is about serving whole pages from a cache, which
 * is a performance decision an administrator owns. It is not a statement that
 * an extension may not keep a counter for a minute, and `CachePort` promises
 * storage rather than storage-if-enabled. A storage handler is supplied for
 * the same reason: with none, `Cache` calls `setCaching(false)` on itself and
 * the promise breaks again.
 *
 * @since 1.0.0
 */
final class JoomlaCache implements CachePort
{
    public const GROUP = 'com_vidyalms';

    private const ENVELOPE = 'value';

    /**
     * Joomla's own default handler, used only when the site names none.
     */
    private const FALLBACK_STORAGE = 'file';

    public function __construct(
        private readonly CacheControllerFactoryInterface $controllers,
    ) {
    }

    public function get(string $key): mixed
    {
        return $this->envelope($key)[self::ENVELOPE] ?? null;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->controller($ttlSeconds)->store([self::ENVELOPE => $value], $this->id($key), self::GROUP, false);
    }

    public function delete(string $key): void
    {
        $this->controller()->remove($this->id($key), self::GROUP);
    }

    public function remember(string $key, int $ttlSeconds, callable $producer): mixed
    {
        $stored = $this->envelope($key);

        if ($stored !== null) {
            return $stored[self::ENVELOPE];
        }

        $produced = $producer();
        $this->set($key, $produced, $ttlSeconds);

        return $produced;
    }

    /**
     * @return array{value: mixed}|null
     */
    private function envelope(string $key): ?array
    {
        $stored = $this->controller()->get($this->id($key), self::GROUP);

        if (!\is_array($stored) || !\array_key_exists(self::ENVELOPE, $stored)) {
            return null;
        }

        /** @var array{value: mixed} $stored */
        return $stored;
    }

    private function controller(?int $ttlSeconds = null): OutputController
    {
        $options = [
            'defaultgroup' => self::GROUP,
            'caching'      => true,
            'storage'      => self::storage(),
        ];

        if ($ttlSeconds !== null) {
            $options['lifetime'] = max(1, (int) ceil($ttlSeconds / 60));
        }

        /** @var OutputController $controller */
        $controller = $this->controllers->createCacheController('output', $options);

        return $controller;
    }

    /**
     * The site's own handler where it names one, and the file handler where it
     * does not. Never a handler this build prefers over the administrator's:
     * a site running Redis or Memcached keeps it.
     */
    private static function storage(): string
    {
        $configured = trim((string) Factory::getApplication()->get('cache_handler', ''));

        return $configured !== '' ? $configured : self::FALLBACK_STORAGE;
    }

    private function id(string $key): string
    {
        return md5($key);
    }
}
