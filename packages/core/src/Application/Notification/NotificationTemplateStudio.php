<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationTemplateRepository;
use Shondalai\VidyaLMS\Core\Port\I18n\LocalePort;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationChannelRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;
use Shondalai\VidyaLMS\Core\Registry\Notification\SupportsHtmlBody;
use Shondalai\VidyaLMS\Core\Registry\Notification\SupportsListHeaders;
use Shondalai\VidyaLMS\Core\Registry\Notification\SupportsShortForm;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTemplateBlueprintCatalogue;

/** Authoring never replaces the live message until an explicit, revision-checked publish. */
final class NotificationTemplateStudio
{
    public function __construct(
        private readonly SqlNotificationTemplateRepository $templates,
        private readonly NotificationTemplateBlueprintCatalogue $blueprints,
        private readonly NotificationTemplateCompiler $compiler,
        private readonly NotificationChannelRegistry $channels,
        private readonly AccessGuard $guard,
        private readonly LocalePort $locale,
        private readonly TranslatorPort $translator,
        private readonly EnsureDefaultNotifications $defaults,
        private readonly string $siteUrl = '',
    ) {
    }

    /**
     * @param array<string, string> $filters
     * @return array<string, mixed>
     */
    public function list(Principal $principal, array $filters, int $page, int $perPage): array
    {
        $this->authorize($principal);
        $search = $this->optional($filters['search'] ?? '');
        if ($search !== null && mb_strlen($search) > 200) {
            throw ValidationException::forField('search', 'Use at most 200 characters.');
        }
        $this->defaults->execute();
        $page = min(1000000, max(1, $page));
        $perPage = min(100, max(1, $perPage));
        $status = $this->optional($filters['status'] ?? '');
        if ($status !== null && !\in_array($status, ['published', 'draft_changes', 'draft_only'], true)) {
            throw ValidationException::forField('status', 'Choose published, draft_changes or draft_only.');
        }
        $templateKey = $this->optional($filters['template_key'] ?? '');
        $triggerKey = $this->optional($filters['trigger_key'] ?? '');
        if ($triggerKey !== null) {
            $matched = null;
            foreach ($this->blueprints->all() as $registration) {
                if ($registration->trigger->key() === $triggerKey) {
                    $matched = $registration->trigger->defaultTemplateKey();
                    break;
                }
            }
            if ($matched === null || ($templateKey !== null && $matched !== $templateKey)) {
                return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
            }
            $templateKey = $matched;
        }
        $filterLocale = $this->optional($filters['locale'] ?? '');
        $result = $this->templates->page(
            $templateKey,
            $this->optional($filters['channel_key'] ?? ''),
            $perPage,
            ($page - 1) * $perPage,
            $filterLocale === null ? null : NotificationTemplateLocale::canonicalize($filterLocale),
            $search,
            $status,
        );
        return [
            'items' => array_map($this->summary(...), $result['items']),
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Principal $principal, string $key, string $locale, string $channel): array
    {
        $this->authorize($principal);
        $locale = $this->identity($key, $locale, $channel);
        $registration = $this->blueprints->forTemplate($key);
        $row = $this->templates->find($key, $locale, $channel);
        $resolved = $this->templates->resolve($key, $channel, [$locale, $this->locale->current()]);
        $summary = $row === null ? [
            'id' => null, 'template_key' => $key, 'locale' => $locale, 'channel_key' => $channel,
            'state' => 'draft', 'status' => 'unpublished', 'body_version' => 0, 'draft_revision' => 0,
            'subject' => null, 'has_draft' => false, 'updated_at' => null,
        ] : $this->summary($row);
        return $summary + [
            'trigger_key' => $registration->trigger->key(),
            'label' => $this->translator->translate($registration->trigger->label()),
            'category_key' => $registration->trigger->categoryKey(),
            'class' => $registration->trigger->messageClass()->value,
            'available' => $this->unavailableReason($channel, $registration->trigger->messageClass()) === null,
            'unavailable_reason' => $this->unavailableReason($channel, $registration->trigger->messageClass()),
            'current_blueprint_version' => $registration->blueprint->version,
            'published_blueprint_version' => null,
            'update_available' => false,
            'published_source' => $row['published_source'] ?? null,
            'draft_source' => $row['draft_source'] ?? null,
            'draft_base_version' => isset($row['draft_base_version']) ? (int) $row['draft_base_version'] : null,
            'draft_origin' => $row['draft_origin'] ?? null,
            'published_at' => $row['published_at'] ?? null,
            'draft_updated_at' => $row['draft_updated_at'] ?? null,
            'legacy_published' => $row !== null && $row['state'] === 'published'
                && ($row['published_source'] ?? null) === null,
            'default_source' => $registration->blueprint->source,
            'blueprint_version' => $registration->blueprint->version,
            'generic_blueprint' => $registration->generic,
            'tokens' => $this->compiler->tokensForTemplate($key),
            'channel_capabilities' => $this->capabilities($channel),
            'resolved_locale' => $resolved['locale'] ?? null,
            'using_fallback' => $resolved !== null && $resolved['locale'] !== $locale,
            'live_parts' => $row === null || $row['state'] !== 'published' ? null : $this->parts($row),
        ] + $this->deliveryReadiness();
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public function saveDraft(
        Principal $principal,
        string $key,
        string $locale,
        string $channel,
        array $source,
        int $expectedRevision,
    ): array {
        $this->authorize($principal);
        $locale = $this->identity($key, $locale, $channel);
        $this->revision($expectedRevision);
        $validated = TemplateSource::fromArray($source);
        $this->compiler->compileForPublication($key, $validated, $channel);
        $this->templates->saveDraft($key, $locale, $channel, $validated->toArray(), $expectedRevision, $principal->userId);
        return $this->detail($principal, $key, $locale, $channel);
    }

    /** @return array<string, mixed> */
    public function discardDraft(
        Principal $principal,
        string $key,
        string $locale,
        string $channel,
        int $expectedRevision,
    ): array {
        $this->authorize($principal);
        $locale = $this->identity($key, $locale, $channel);
        $this->revision($expectedRevision);
        $this->templates->discardDraft($key, $locale, $channel, $expectedRevision);
        return $this->detail($principal, $key, $locale, $channel);
    }

    /** @return array<string, mixed> */
    public function publish(
        Principal $principal,
        string $key,
        string $locale,
        string $channel,
        int $expectedRevision,
    ): array {
        $this->authorize($principal);
        $locale = $this->identity($key, $locale, $channel);
        $this->revision($expectedRevision);
        $row = $this->templates->find($key, $locale, $channel);
        if ($row === null || !\is_array($row['draft_source'] ?? null)) {
            throw new ConflictException('There is no draft to publish.');
        }
        if ((int) $row['draft_revision'] !== $expectedRevision) {
            throw new ConflictException('The draft changed. Reload before publishing.');
        }
        $compiled = $this->compiler->compileForPublication($key, $row['draft_source'], $channel);
        $this->templates->publishDraft(
            $key,
            $locale,
            $channel,
            $expectedRevision,
            $compiled->parts(),
            $principal->userId,
            $this->blueprints->forTemplate($key)->blueprint->version,
        );
        return $this->detail($principal, $key, $locale, $channel);
    }

    /**
     * Pure synthetic preview: no settings, template writes, queues, tokens issued or learner reads.
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public function preview(Principal $principal, string $key, string $locale, string $channel, array $source): array
    {
        $this->authorize($principal);
        $locale = $this->identity($key, $locale, $channel);
        $registration = $this->blueprints->forTemplate($key);
        $compiled = $this->compiler->compile(
            $key,
            $source,
            $channel,
            TemplateRenderContext::synthetic($registration->blueprint->syntheticValues, $locale),
        );
        return $compiled->parts() + $this->deliveryReadiness() + [
            'locale' => $locale,
            'sample_data' => true,
            'used_tokens' => $compiled->usedTokens,
            'unresolved_tokens' => $compiled->unresolvedTokens,
            'class' => $registration->trigger->messageClass()->value,
        ];
    }

    public function authorize(Principal $principal): void
    {
        $this->guard->requireAny($principal, Capability::MANAGE, Capability::ADMIN);
    }

    /**
     * Configuration preflight only, not a promise of transport or inbox delivery.
     * Synthetic preview/test values cannot establish real site URL readiness.
     * @return array{delivery_ready:bool,delivery_warnings:list<array{code:string,message:string,blocking:bool}>}
     */
    public function deliveryReadiness(): array
    {
        $ready = filter_var($this->siteUrl, FILTER_VALIDATE_URL) !== false
            // Portable PHP 8.1+ core parses URL components identically on Joomla and WordPress; it does not render them.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
            && strtolower((string) parse_url($this->siteUrl, PHP_URL_SCHEME)) === 'https';
        return [
            'delivery_ready' => $ready,
            'delivery_warnings' => $ready ? [] : [[
                'code' => 'https_required_for_runtime_urls',
                'message' => 'This site does not have an HTTPS public URL. Live messages using site or course links '
                    . 'cannot pass secure URL validation. Previews and test emails use sample links; their success '
                    . 'does not confirm live delivery readiness. Configure HTTPS before enabling those notifications.',
                'blocking' => true,
            ]],
        ];
    }

    private function identity(string $key, string $locale, string $channel): string
    {
        if (\strlen($key) > 100 || preg_match('/^[A-Za-z0-9_.:-]+$/D', $key) !== 1) {
            throw ValidationException::forField('template_key', 'A valid template key is required.');
        }
        $found = false;
        foreach ($this->blueprints->all() as $registration) {
            if ($registration->trigger->defaultTemplateKey() === $key) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('NotificationTemplate', $key);
        }
        if (!$this->channels->has($channel)) {
            throw ValidationException::forField('channel_key', 'Unknown channel.');
        }
        return NotificationTemplateLocale::canonicalize($locale);
    }

    private function revision(int $expectedRevision): void
    {
        if ($expectedRevision < 0) {
            throw ValidationException::forField('expected_revision', 'A non-negative draft revision is required.');
        }
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function summary(array $row): array
    {
        $hasDraft = \is_array($row['draft_source'] ?? null);
        $published = $row['state'] === SqlNotificationTemplateRepository::STATE_PUBLISHED;
        $result = [
            'id' => (int) $row['id'],
            'template_key' => (string) $row['template_key'],
            'locale' => (string) $row['locale'],
            'channel_key' => (string) $row['channel_key'],
            'subject' => $row['subject'] ?? null,
            'state' => (string) $row['state'],
            'status' => $published ? ($hasDraft ? 'draft_changes' : 'published')
                : ($hasDraft ? 'draft_only' : 'unpublished'),
            'body_version' => (int) $row['body_version'],
            'draft_revision' => (int) ($row['draft_revision'] ?? 0),
            'has_draft' => $hasDraft,
            'updated_at' => $row['updated_at'] ?? null,
            'available' => false,
            'unavailable_reason' => 'The notification provider is not installed.',
            'current_blueprint_version' => null,
            'published_blueprint_version' => $published && \is_array($row['published_source'] ?? null)
                ? (int) ($row['blueprint_version'] ?? 1) : null,
            'update_available' => false,
        ] + $this->deliveryReadiness();
        foreach ($this->blueprints->all() as $registration) {
            if ($registration->trigger->defaultTemplateKey() === $row['template_key']) {
                $result['trigger_key'] = $registration->trigger->key();
                $result['label'] = $this->translator->translate($registration->trigger->label());
                $result['category_key'] = $registration->trigger->categoryKey();
                $result['class'] = $registration->trigger->messageClass()->value;
                $result['unavailable_reason'] = $this->unavailableReason(
                    (string) $row['channel_key'],
                    $registration->trigger->messageClass(),
                );
                $result['available'] = $result['unavailable_reason'] === null;
                $result['current_blueprint_version'] = $registration->blueprint->version;
                $result['update_available'] = $result['published_blueprint_version'] !== null
                    && $result['published_blueprint_version'] < $registration->blueprint->version;
                break;
            }
        }
        return $result;
    }

    private function unavailableReason(string $channel, NotificationClass $class): ?string
    {
        if (!$this->channels->has($channel)) {
            return 'The notification channel is not installed.';
        }
        if ($class !== NotificationClass::Transactional && !$this->channels->get($channel) instanceof SupportsListHeaders) {
            return 'This message class requires a channel with unsubscribe headers.';
        }
        return null;
    }

    /** @return list<string> */
    private function capabilities(string $key): array
    {
        $channel = $this->channels->get($key);
        $result = [];
        if ($channel instanceof SupportsHtmlBody) {
            $result[] = 'html_body';
        }
        if ($channel instanceof SupportsShortForm) {
            $result[] = 'short_form';
        }
        if ($channel instanceof SupportsListHeaders) {
            $result[] = 'list_headers';
        }
        return $result;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function parts(array $row): array
    {
        return array_intersect_key($row, array_flip(['subject', 'body_text', 'body_html', 'body_short']));
    }

    private function optional(string $value): ?string
    {
        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
