<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\Credit\CreditSchemeRegistry;
use Shondalai\VidyaLMS\Core\Registry\Credit\DescribesCreditSettings;
use Shondalai\VidyaLMS\Core\Registry\Settings\DeclaresSettings;
use Shondalai\VidyaLMS\Core\Registry\Settings\SettingsDeclarationContext;

/**
 * GET /manage/credit-schemes
 *
 * What this site can record credit under, enumerated from the registry. The
 * free build answers one; a site that installs the continuing-education
 * package sees four, with no core edit between the two states.
 *
 * The learner fields a scheme declares travel with it, because a compliance
 * export refuses a row whose holder never supplied a certifying board id and
 * an author needs to know that before the reporting window closes rather than
 * after.
 */
final class ListCreditSchemesHandler implements HandlerInterface
{
    public function __construct(
        private readonly CreditSchemeRegistry $schemes,
        private readonly AccessGuard $guard,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $this->guard->require($request->principal, Capability::MANAGE);

        $items = [];

        foreach ($this->schemes->all() as $scheme) {
            $items[] = [
                'key' => $scheme->key(),
                'available' => true,
                'edition' => $scheme instanceof DescribesCreditSettings ? $scheme->edition() : 'free',
                'groups' => $scheme instanceof DeclaresSettings
                    ? [$scheme->settingsGroup(SettingsDeclarationContext::create('credit_schemes', 'course'))]
                    : [],
                'label' => $scheme->label(),
                'unit' => $scheme->unit(),
                'required_learner_fields' => $scheme->requiredLearnerFields(),
                'retention_years' => $scheme->retentionYears(),
                'params_version' => $scheme->currentShape(),
            ];
        }

        return ApiResponse::ok(['items' => $items]);
    }
}
