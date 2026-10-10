<?php

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Service\EasyCommerce;

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Joomla\Input\Input;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\ComponentLog;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\JsonEmitter;

/** Host catalogue configuration belongs to the component, not the event plugin. */
final class MappingApi
{
    public function __construct(private readonly CMSApplicationInterface $app, private readonly Input $input)
    {
    }

    public function serve(): void
    {
        try {
            $token = (string) $this->input->server->get('HTTP_X_CSRF_TOKEN', '', 'alnum');
            self::authorize(
                $this->app->isClient('administrator') && $this->app->getIdentity()?->authorise('core.manage', 'com_vidyalms') === true,
                $token !== '' && hash_equals(Session::getFormToken(), $token),
            );
            if (!ComponentHelper::isEnabled('com_easycommerce')) {
                throw ApiProblem::conflict(Text::_('COM_VIDYALMS_SHOP_MAPPING_SHOP_REQUIRED'));
            }
            $method = strtoupper($this->input->getMethod());
            $settings = Integration::settings();
            if ($method === 'GET') {
                $action = $this->input->getCmd('action', 'list');
                if ($action === 'list') {
                    $data = $settings->display();
                } elseif (in_array($action, ['products', 'variations', 'courses'], true)) {
                    $catalogue = new Catalogue(Factory::getContainer()->get(DatabaseInterface::class));
                    $data = $catalogue->search($action, mb_substr(trim($this->input->getString('search', '')), 0, 120),
                        max(1, min(5000, $this->input->getInt('page', 1))), max(0, $this->input->getInt('product_id', 0)));
                } else {
                    throw ApiProblem::notFound();
                }
            } elseif ($method === 'POST') {
                $raw = file_get_contents('php://input', false, null, 0, 65537);
                $body = is_string($raw) && strlen($raw) <= 65536 ? json_decode($raw, true) : null;
                self::apply($settings, $body);
                $data = $settings->display();
            } else {
                throw new ApiProblem(405, 'Method not allowed');
            }
            $response = new ApiResponse(200, $data);
        } catch (ConflictException $error) {
            $response = ApiProblem::conflict(Text::_('COM_VIDYALMS_SHOP_MAPPING_CONFLICT'))->toResponse();
        } catch (\InvalidArgumentException $error) {
            $response = (new ApiProblem(422, Text::_('COM_VIDYALMS_SHOP_MAPPING_INVALID'), Text::_($error->getMessage())))->toResponse();
        } catch (ApiProblem $error) {
            $response = $error->toResponse();
        } catch (\Throwable $error) {
            ComponentLog::error('Shop mapping settings: ' . $error->getMessage());
            $response = ApiProblem::internal()->toResponse();
        }
        (new JsonEmitter($this->app))->emit($response);
    }

    public static function authorize(bool $manager, bool $validToken): void
    {
        if (!$manager || !$validToken) {
            throw ApiProblem::forbidden();
        }
    }

    public static function apply(MappingSettings $settings, mixed $body): void
    {
        if (!is_array($body) || !in_array($body['action'] ?? '', ['create', 'update', 'remove'], true)
            || !is_int($body['expected_revision'] ?? null) || $body['expected_revision'] < 1
            || !is_int($body['product_id'] ?? null) || $body['product_id'] < 1
            || !is_int($body['variation_id'] ?? null) || $body['variation_id'] < 0
            || !is_array($body['courses'] ?? null) || !array_is_list($body['courses'])) {
            throw new \InvalidArgumentException('COM_VIDYALMS_SHOP_MAPPING_INVALID');
        }
        $settings->save($body['expected_revision'], $body['product_id'], $body['variation_id'], $body['courses'],
            $body['action'] === 'remove', $body['action'] === 'create');
    }
}
