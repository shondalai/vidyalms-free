<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\WordPress\Privacy;

use Shondalai\VidyaLMS\Core\Application\Organisation\SubjectOrganisationPrivacy;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Registry\Privacy\PrivacyContributorRegistry;

defined('ABSPATH') || exit;

final class Contributors
{
    public static function resolve(DatabasePort $database): SubjectOrganisationPrivacy
    {
        $kernel = function_exists('vidyalms_kernel') ? vidyalms_kernel() : null;
        // An unavailable kernel produces an explicit missing-contributor notice
        // on export and blocks erasure; it never falls back to a closed table list.
        $registry = $kernel instanceof CoreKernel ? $kernel->registries->privacyContributors : new PrivacyContributorRegistry();
        return new SubjectOrganisationPrivacy($registry, $database);
    }
}
