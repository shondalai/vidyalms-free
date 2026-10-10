<?php

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\EasyCommerce\MappingApi;

final class ShopController extends BaseController
{
    public function mappings(): void
    {
        (new MappingApi($this->app, $this->input))->serve();
    }
}
