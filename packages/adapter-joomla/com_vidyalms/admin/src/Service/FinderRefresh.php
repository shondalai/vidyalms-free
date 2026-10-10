<?php

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Event\GenericEvent;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\DispatcherInterface;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Middleware;

/** @license GNU General Public License version 3 or later; see LICENSE */
final class FinderRefresh implements Middleware
{
    public function __construct(private readonly DispatcherInterface $dispatcher)
    {
    }

    public function process(ApiRequest $request, callable $next): ApiResponse
    {
        $response = $next($request);
        // Availability edits have no course domain event. Refresh only after
        // an authorized write succeeds; failed requests must not affect search.
        if ($response->status < 200 || $response->status >= 300
            || !in_array($request->method, ['PUT', 'DELETE'], true)
            || !preg_match('~^/manage/courses/([1-9][0-9]*)/availability/?$~D', $request->path, $match)) {
            return $response;
        }
        try {
            PluginHelper::importPlugin('finder', 'vidyalms');
            $name = 'onVidyaLmsCourseIndexChanged';
            $this->dispatcher->dispatch($name, new GenericEvent($name, ['payload' => ['course_id' => (int) $match[1]]]));
        } catch (\Throwable $failure) {
            Log::add('VidyaLMS Finder refresh failed: ' . get_class($failure), Log::ERROR, 'com_vidyalms');
        }
        return $response;
    }
}
