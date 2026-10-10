<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationConsentRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationPreferenceRepository as Prefs;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationChannelRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTriggerRegistry;

/**
 * The lawful unsubscribe, which is free because it is an obligation and
 * cannot be sold.
 *
 * The landing page renders and acts on nothing. Mail security scanners follow
 * links, which is the whole reason the one-click mechanism is specified as a
 * POST, and a GET that unsubscribed would turn a scanner into an opt-out.
 *
 * The action writes a preference row and a consent row, and never a
 * suppression row. A category-scoped click producing an address-scoped block
 * would silence the next certificate mail, which is transactional and
 * something a site may be obliged to deliver; suppression is a deliverability
 * fact about an address and binds every class, which is exactly why a
 * preference opt-out may not land in it.
 *
 * Nothing beyond the token may be demanded and nothing may be charged.
 */
final class Unsubscribe
{
    public function __construct(
        private readonly UnsubscribeTokens $tokens,
        private readonly Prefs $preferences,
        private readonly SqlNotificationConsentRepository $consents,
        private readonly NotificationTriggerRegistry $triggers,
        private readonly NotificationChannelRegistry $channels,
        private readonly TranslatorPort $translator,
    ) {
    }

    /**
     * The landing page's payload. It reads the token and acts on nothing.
     *
     * @return array<string, mixed>
     */
    public function preview(string $token): array
    {
        $claim = $this->verify($token);
        $categoryKey = $claim['category_key'];
        $channels = $this->channels->keys();
        $already = true;

        foreach ($channels as $channelKey) {
            if ($this->preferences->frequencyFor($claim['user_id'], $categoryKey, $channelKey) !== Prefs::FREQUENCY_NEVER) {
                $already = false;
                break;
            }
        }

        return [
            'category_key' => $categoryKey,
            'category_label' => $this->translator->translate('vidyalms.notification.category.' . $categoryKey),
            'channels' => $channels,
            'already_unsubscribed' => $already,
            'keeps_receiving' => $this->keepsReceiving($categoryKey),
        ];
    }

    /**
     * @param list<string>|null $channels null means every channel of the token's category
     *
     * @return array<string, mixed>
     */
    public function execute(string $token, ?array $channels = null, ?string $ip = null): array
    {
        $claim = $this->verify($token);
        $categoryKey = $claim['category_key'];
        $registered = $this->channels->keys();

        $targets = $channels === null
            ? $registered
            : array_values(array_intersect($channels, $registered));

        // An empty target list is refused rather than answered
        // "unsubscribed". A body naming no channel, or only channels this
        // site does not have, wrote no preference row, appended a withdrawal
        // anyway and told the caller it had worked: the preference table said
        // no, the consent ledger said yes, and the ledger is the table an
        // audit reads.
        if ($targets === []) {
            throw ValidationException::forField(
                'channels',
                'Name at least one channel this site sends on, or omit the field for all of them.',
            );
        }

        foreach ($targets as $channelKey) {
            $this->preferences->setPreference(
                $claim['user_id'],
                $categoryKey,
                $channelKey,
                Prefs::FREQUENCY_NEVER,
            );
        }

        $this->consents->append(
            $claim['user_id'],
            $categoryKey,
            SqlNotificationConsentRepository::STATE_WITHDRAWN,
            SqlNotificationConsentRepository::BASIS_CONSENT,
            SqlNotificationConsentRepository::SOURCE_UNSUBSCRIBE,
            ['channels' => $targets, 'issued_at' => $claim['issued_at']],
            null,
            $ip,
        );

        return [
            'unsubscribed' => true,
            'category_key' => $categoryKey,
            'channels' => $targets,
            'consent_recorded' => true,
            // Returned so the asymmetry is visible rather than assumed.
            'suppression_written' => false,
            'still_receiving' => $this->keepsReceiving($categoryKey),
        ];
    }

    /**
     * @return array{user_id: int, category_key: string, issued_at: int}
     */
    private function verify(string $token): array
    {
        $claim = $this->tokens->verify($token);

        if ($claim === null) {
            throw EntityNotFoundException::for('UnsubscribeToken', 0);
        }

        return $claim;
    }

    /**
     * The categories this action does not touch **and the learner really does
     * still receive**, named so nobody believes an unsubscribe stopped their
     * certificate mail.
     *
     * Both arms of the class test used to keep the category, so the landing
     * page listed every other category including ones the learner had already
     * unsubscribed from, which is the opposite of the reassurance the line
     * exists to give.
     *
     * @return list<string>
     */
    private function keepsReceiving(string $categoryKey): array
    {
        $kept = [];

        foreach ($this->triggers->categories() as $key => $category) {
            if ($key === $categoryKey) {
                continue;
            }

            if (NotificationClass::from($category['class'])->bypassesPreference()) {
                $kept[] = $key;
            }
        }

        return $kept;
    }
}
