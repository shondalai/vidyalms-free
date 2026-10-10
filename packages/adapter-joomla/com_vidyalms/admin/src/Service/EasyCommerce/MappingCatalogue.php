<?php

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Service\EasyCommerce;

defined('_JEXEC') or die;

/** Read-only catalogue used when an LMS administrator connects shop products. */
interface MappingCatalogue
{
    /** @return array<int, array<string, mixed>> Indexed by ID. */
    public function find(string $kind, array $ids): array;

    /** @return array{items: list<array<string, mixed>>, more: bool} */
    public function search(string $kind, string $search, int $page, int $productId = 0): array;
}
