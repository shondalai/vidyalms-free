<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Platform\GetPlatformSettings;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * GET /admin/settings, PUT /admin/settings
 *
 * Runtime configuration, resolved host facts, credential issuer and commerce
 * background actor. Each PUT updates one record. Runtime configuration writes
 * require the revision the administrator edited to prevent lost updates.
 */
final class GetPlatformSettingsHandler implements HandlerInterface
{
    public function __construct(
        private readonly GetPlatformSettings $getPlatformSettings,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        if ($request->method === 'PUT') {
            if (\array_key_exists('publish_learner_name', $request->body)) {
                $publish = $request->body['publish_learner_name'];
                if (!\is_bool($publish) || array_keys($request->body) !== ['publish_learner_name']) {
                    throw ApiProblem::badRequest('publish_learner_name must be a boolean.');
                }
                return ApiResponse::ok($this->presenters->platform->platformSettings(
                    $this->getPlatformSettings->setPublishLearnerName($request->principal, $publish),
                ));
            }
            if (\array_key_exists('configuration', $request->body)) {
                $revision = $request->body['expected_revision'] ?? null;
                $values = $request->body['configuration'];
                if (
                    !\is_int($revision) || $revision < 1 || !\is_array($values)
                    || array_diff(array_keys($request->body), ['configuration', 'expected_revision']) !== []
                ) {
                    throw ApiProblem::badRequest('A configuration object and its expected_revision are required.');
                }
                return ApiResponse::ok($this->presenters->platform->platformSettings(
                    $this->getPlatformSettings->saveConfiguration($request->principal, $revision, $values),
                ));
            }
            // One record per PUT, named by which key the body carries. A body
            // carrying both would be two decisions in one write, and a body
            // carrying neither is a caller who has not said what to change.
            if (\array_key_exists('background_actor_user_id', $request->body)) {
                $actor = $request->body['background_actor_user_id'];

                if ($actor !== null && !\is_int($actor)) {
                    throw ApiProblem::badRequest('background_actor_user_id is a user id or null.');
                }

                return ApiResponse::ok($this->presenters->platform->platformSettings(
                    $this->getPlatformSettings->setBackgroundActor($request->principal, $actor),
                ));
            }

            $issuer = $request->body['issuer'] ?? null;

            if (!\is_array($issuer)) {
                throw ApiProblem::badRequest('An issuer object is required.');
            }

            /** @var array<string, mixed> $issuer */
            return ApiResponse::ok($this->presenters->platform->platformSettings(
                $this->getPlatformSettings->setIssuer($request->principal, $issuer),
            ));
        }

        return ApiResponse::ok($this->presenters->platform->platformSettings(
            $this->getPlatformSettings->execute($request->principal),
        ));
    }
}
