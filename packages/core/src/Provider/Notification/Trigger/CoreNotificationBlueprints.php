<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Trigger;

use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTemplateBlueprint;

/**
 * Designed structured defaults for every Core notification trigger.
 *
 * **Changing a word here means changing the version beside it.** A site's
 * seeded row keeps the copy it was seeded with, and NotificationTemplateStudio
 * offers an update only where published_blueprint_version is below the
 * registration's version, so a correction landed without a bump reaches
 * exactly nobody: the source is right, the mail is still wrong, and the
 * administrator is never told a newer default exists. The version defaults to
 * 1 and is stated per definition; BlueprintVersionDriftTest holds a digest of
 * every source against its version and fails when one moves without the
 * other.
 */
final class CoreNotificationBlueprints
{
    public static function for(string $templateKey, NotificationClass $class): NotificationTemplateBlueprint
    {
        $definition = match ($templateKey) {
            EnrolledTrigger::KEY => [
                'subject' => 'Welcome to {course.title}',
                'preheader' => 'Your place in {course.title} is ready.',
                'heading' => 'You’re enrolled, {user.first_name}',
                'paragraphs' => [
                    'Your place in {course.title} is confirmed.',
                    'Everything is ready whenever you are. Open the course to see your learning path and begin.',
                ],
                'action_label' => 'Open course',
                'action_url' => '{course.url}',
                'short_body' => 'You’re enrolled in {course.title}. Open the course when you’re ready.',
                'required' => ['user.first_name', 'course.title', 'course.url', 'site.name'],
                'samples' => [],
            ],
            CourseCompletedTrigger::KEY => [
                'subject' => 'You completed {course.title}',
                'preheader' => 'A milestone worth celebrating.',
                'heading' => 'Beautiful work, {user.first_name}',
                'paragraphs' => [
                    'You completed {course.title}.',
                    'Your progress and results are ready on your learning page. Take a moment to celebrate what you achieved.',
                ],
                'action_label' => 'View your learning',
                'action_url' => '{course.url}',
                'short_body' => 'You completed {course.title}. Your results are ready.',
                'required' => ['user.first_name', 'course.title', 'course.url', 'site.name'],
                'samples' => [],
            ],
            CertificateIssuedTrigger::KEY => [
                'subject' => 'Your certificate for {course.title}',
                'preheader' => 'Your achievement is ready to share.',
                'heading' => 'Your certificate is ready',
                'paragraphs' => [
                    'Congratulations, {user.first_name}. Your certificate for {course.title} has been issued.',
                    'Verification code: {certificate.code}. Keep it with your records or share the verification page.',
                ],
                'action_label' => 'View certificate',
                'action_url' => '{certificate.verification_url}',
                'short_body' => 'Your certificate for {course.title} is ready. Code: {certificate.code}.',
                'required' => [
                    'user.first_name',
                    'course.title',
                    'certificate.code',
                    'certificate.verification_url',
                    'site.name',
                ],
                'samples' => [
                    'certificate.code' => 'VLM-8F4K-2P9Q',
                    'certificate.verification_url' => 'https://example.invalid/certificates/VLM-8F4K-2P9Q/verify',
                ],
            ],
            CertificateReissuedTrigger::KEY => [
                'subject' => 'Your {course.title} certificate was updated',
                'preheader' => 'A corrected certificate now replaces the earlier copy.',
                'heading' => 'Your corrected certificate is ready',
                'paragraphs' => [
                    'Hello {user.first_name}. Your certificate for {course.title} has been corrected and reissued.',
                    'The replacement verification code is {certificate.code}. The previous code now points to this updated record.',
                ],
                'action_label' => 'View replacement',
                'action_url' => '{certificate.verification_url}',
                'short_body' => 'Your {course.title} certificate was reissued. New code: {certificate.code}.',
                'required' => [
                    'user.first_name',
                    'course.title',
                    'certificate.code',
                    'certificate.verification_url',
                    'site.name',
                ],
                'samples' => [
                    'certificate.code' => 'VLM-3R7M-5D2N',
                    'certificate.verification_url' => 'https://example.invalid/certificates/VLM-3R7M-5D2N/verify',
                ],
            ],
            SubmissionReceivedTrigger::KEY => [
                'subject' => 'New work is ready to review in {course.title}',
                'preheader' => '{learner_name} submitted work for review.',
                'heading' => 'A submission is waiting',
                'paragraphs' => [
                    '{learner_name} submitted new work in {course.title}.',
                    'Open your instructor workspace when you are ready to review and provide feedback.',
                ],
                'action_label' => 'Open instructor workspace',
                'action_url' => '{site.url}',
                'short_body' => '{learner_name} submitted work in {course.title}.',
                'required' => ['learner_name', 'course.title', 'site.url', 'site.name'],
                'samples' => ['learner_name' => 'Avery Morgan'],
            ],
            AttemptGradedTrigger::KEY => [
                'subject' => 'Your result for {course.title}',
                'preheader' => 'Your latest assessment result is available.',
                'heading' => 'Your result is ready',
                'paragraphs' => [
                    'Hello {user.first_name}. Your assessment in {course.title} has been marked.',
                    'Your score is {percentage}%. Open your learning page to review the result and feedback.',
                ],
                'action_label' => 'Review result',
                'action_url' => '{site.url}',
                'short_body' => 'Your {course.title} result is ready: {percentage}%.',
                'required' => ['user.first_name', 'course.title', 'percentage', 'site.url', 'site.name'],
                'samples' => ['percentage' => '86'],
            ],
            'vidyalms.availability.unlocked' => [
                // Version 2: the second paragraph lost an em-dash in R0. Every
                // site seeded before that still holds the dashed sentence.
                'version' => 2,
                'subject' => 'Something new is available in {course.title}',
                'preheader' => 'The next part of your course is open.',
                'heading' => 'Your next step is ready',
                'paragraphs' => [
                    'Hello {user.first_name}. A new part of {course.title} is now available.',
                    'Continue when the moment feels right, your progress is waiting for you.',
                ],
                'action_label' => 'Continue course',
                'action_url' => '{course.url}',
                'short_body' => 'A new part of {course.title} is now available.',
                'required' => ['user.first_name', 'course.title', 'course.url', 'site.name'],
                'samples' => [],
            ],
            EntitlementExpiringSweep::KEY => [
                'subject' => 'Your access to {course.title} ends soon',
                'preheader' => 'A quick reminder before your course access ends.',
                'heading' => 'Your access ends on {expires_at}',
                'paragraphs' => [
                    'Hello {user.first_name}. Your access to {course.title} ends on {expires_at}.',
                    'Finish anything outstanding before then. Your learning site will keep showing the latest access details.',
                ],
                'action_label' => 'Open learning site',
                'action_url' => '{site.url}',
                'short_body' => 'Access to {course.title} ends on {expires_at}.',
                'required' => ['user.first_name', 'course.title', 'expires_at', 'site.url', 'site.name'],
                'samples' => ['expires_at' => '18 September 2026'],
            ],
            // The three seat threshold alerts. They are addressed to an
            // organisation administrator rather than to a learner, so they
            // name no person and use none of the learner placeholders: the
            // recipient is whoever administers the organisation, and a
            // message opening with somebody else's first name is how an
            // operational alert reads as a mistake.
            SeatThresholdTrigger::KEY_LIMITED => [
                'subject' => 'Seats are running low for {course.title}',
                'preheader' => '{seats_remaining} of {seats_capacity} seats are left.',
                'heading' => 'Seats are running low',
                'paragraphs' => [
                    'The seat pool {pool_name} for {course.title} has {seats_remaining} of {seats_capacity} seats left.',
                    'Add capacity or reclaim unused seats before the next person you invite is turned away.',
                ],
                'action_label' => 'Review seats',
                'action_url' => '{site.url}',
                'short_body' => '{pool_name} has {seats_remaining} of {seats_capacity} seats left.',
                'required' => ['pool_name', 'course.title', 'seats_remaining', 'seats_capacity', 'site.name'],
                'samples' => ['pool_name' => 'Autumn intake', 'seats_remaining' => '3', 'seats_capacity' => '40'],
            ],
            SeatThresholdTrigger::KEY_EXHAUSTED => [
                'subject' => 'No seats left for {course.title}',
                'preheader' => 'Every seat in {pool_name} is now taken.',
                'heading' => 'This pool is out of seats',
                'paragraphs' => [
                    'All {seats_capacity} seats in {pool_name} for {course.title} are now committed.',
                    'Anybody you invite or assign from this pool will be refused until capacity is added '
                        . 'or a seat is returned.',
                ],
                'action_label' => 'Review seats',
                'action_url' => '{site.url}',
                'short_body' => '{pool_name} has no seats left.',
                'required' => ['pool_name', 'course.title', 'seats_capacity', 'site.name'],
                'samples' => ['pool_name' => 'Autumn intake', 'seats_remaining' => '0', 'seats_capacity' => '40'],
            ],
            SeatThresholdTrigger::KEY_PERIODIC => [
                'subject' => 'Seat summary for {pool_name}',
                'preheader' => '{seats_remaining} of {seats_capacity} seats are available.',
                'heading' => 'Your seat summary',
                'paragraphs' => [
                    '{pool_name} for {course.title} has {seats_remaining} of {seats_capacity} seats available.',
                    'This is the scheduled summary. It is sent only while the pool is enabled for it.',
                ],
                'action_label' => 'Review seats',
                'action_url' => '{site.url}',
                'short_body' => '{pool_name}: {seats_remaining} of {seats_capacity} seats available.',
                'required' => ['pool_name', 'course.title', 'seats_remaining', 'seats_capacity', 'site.name'],
                'samples' => ['pool_name' => 'Autumn intake', 'seats_remaining' => '12', 'seats_capacity' => '40'],
            ],
            default => null,
        };

        if ($definition === null) {
            return self::generic($class);
        }

        $source = self::source($definition);
        $samples = self::samples($definition['samples']);

        if ($class !== NotificationClass::Transactional) {
            $samples['unsubscribe.url'] = 'https://example.invalid/notifications/unsubscribe/sample';
        }

        return new NotificationTemplateBlueprint(
            (int) ($definition['version'] ?? 1),
            $source,
            $samples,
            $definition['required'],
        );
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private static function source(array $definition): array
    {
        return [
            'subject' => $definition['subject'],
            'preheader' => $definition['preheader'],
            'heading' => $definition['heading'],
            'paragraphs' => $definition['paragraphs'],
            'action_label' => $definition['action_label'],
            'action_url' => $definition['action_url'],
            'footer' => 'Sent with care by {site.name}.',
            'logo_url' => '{site.logo_url}',
            'logo_alt' => '{site.name}',
            'accent_color' => '#3155A6',
            'short_body' => $definition['short_body'],
        ];
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, string>
     */
    private static function samples(array $extra): array
    {
        return $extra + [
            'user.name' => 'Avery Morgan',
            'user.first_name' => 'Avery',
            'user.email' => 'avery.morgan@example.invalid',
            'site.name' => 'Vidya Learning',
            'site.url' => 'https://example.invalid',
            // Optional sample branding is deliberately absent; the authored
            // placeholder above still resolves the configured site logo.
            'site.logo_url' => '',
            'course.title' => 'Inclusive Leadership',
            'course.url' => 'https://example.invalid/courses/inclusive-leadership',
            // The seat alerts are addressed to an administrator about a pool,
            // so their sample values belong beside the shared ones rather than
            // in each of the three definitions.
            'pool_name' => 'Autumn intake',
            'seats_remaining' => '3',
            'seats_capacity' => '40',
        ];
    }

    private static function generic(NotificationClass $class): NotificationTemplateBlueprint
    {
        $samples = $class === NotificationClass::Transactional
            ? []
            : ['unsubscribe.url' => 'https://example.invalid/notifications/unsubscribe/sample'];

        return new NotificationTemplateBlueprint(1, [
            'subject' => 'Learning update',
            'preheader' => 'There is an update from your learning site.',
            'heading' => 'Learning update',
            'paragraphs' => ['Sign in to your learning site to review the latest update.'],
            'action_label' => null,
            'action_url' => null,
            'footer' => 'This message was sent by your learning site.',
            'logo_url' => null,
            'logo_alt' => null,
            'accent_color' => '#3155A6',
            'short_body' => 'There is a new learning update.',
        ], $samples, [], true);
    }
}
