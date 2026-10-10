<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationTemplateRepository;
use Shondalai\VidyaLMS\Core\Registry\Context\TokenContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Notification;
use Shondalai\VidyaLMS\Core\Registry\Notification\RenderedMessage;
use Shondalai\VidyaLMS\Core\Registry\Token\TokenResolverRegistry;

/**
 * Turns a template row and a notification into a finished message.
 *
 * There is deliberately no general template engine. Admin-editable bodies
 * rendered through a full engine on a shared-hosting CMS is a server-side
 * template injection surface, so the vocabulary is closed and resolved by
 * registered token providers, and an unknown placeholder is a save-time error
 * rather than a send-time one. Structured published sources fail closed when
 * a required value cannot resolve and omit optional misses. Legacy parts keep
 * their historical visible-placeholder miss direction for diagnostics.
 *
 * Two obligations are enforced here and no adapter may weaken them: the plain
 * part precedes the HTML part, per the rule that multipart alternatives are
 * ordered by increasing faithfulness, and both unsubscribe header names are
 * present on every non-transactional message.
 */
final class NotificationRenderer
{
    private readonly TemplatePlaceholderParser $parser;

    public function __construct(
        private readonly SqlNotificationTemplateRepository $templates,
        private readonly TokenResolverRegistry $tokens,
        private readonly ?NotificationTemplateCompiler $compiler = null,
    ) {
        $this->parser = new TemplatePlaceholderParser();
    }

    /**
     * @param list<string>          $localeChain
     * @param array<string, string> $headers
     */
    public function render(
        Notification $notification,
        string $channelKey,
        array $localeChain,
        array $headers = [],
        ?string $envelopeSender = null,
        ?int $courseId = null,
        string $timezone = 'UTC',
    ): ?RenderedMessage {
        $template = $this->templates->resolve($notification->templateKey, $channelKey, $localeChain);

        if ($template === null) {
            return null;
        }

        $locale = (string) ($template['locale'] ?? ($localeChain[0] ?? 'en-GB'));
        $context = TokenContext::create(
            // The category travels with the parameters so a resolver that
            // needs it (the unsubscribe link is issued per user and category)
            // does not need a second context member for one provider. The
            // rule's own parameters win, so a trigger that already carries a
            // category_key is not overwritten.
            $notification->parameters + ['category_key' => $notification->categoryKey],
            $locale,
            $notification->recipientUserId,
            $courseId,
            $timezone,
        );

        $publishedSource = $template['published_source'] ?? null;

        if ($this->compiler !== null && \is_array($publishedSource)) {
            $compiled = $this->compiler->compile(
                $notification->templateKey,
                $publishedSource,
                $channelKey,
                TemplateRenderContext::real($context),
            );
            $subject = $compiled->subject;
            $bodyText = $compiled->bodyText;
            $bodyHtml = $compiled->bodyHtml;
            $bodyShort = $compiled->bodyShort;
            $locale = $compiled->locale;
        } else {
            $subject = $this->sanitizeSubject($this->resolveLegacy((string) ($template['subject'] ?? ''), $context));
            $bodyText = $this->resolveLegacy((string) ($template['body_text'] ?? ''), $context);
            $bodyHtml = $template['body_html'] === null
                ? null
                : $this->resolveLegacy((string) $template['body_html'], $context, true);
            $bodyShort = $template['body_short'] === null
                ? null
                : $this->resolveLegacy((string) $template['body_short'], $context);
        }

        // Schedule authors can supply plain-text report copy. Restrict these
        // overrides to our report template; other message producers cannot
        // replace a template by smuggling similarly named parameters.
        if ($notification->templateKey === \Shondalai\VidyaLMS\Core\Provider\ReportDelivery\NotificationLinkDelivery::TEMPLATE_KEY) {
            $reportSubject = $notification->parameters['_report_message_subject'] ?? null;
            $reportBody = $notification->parameters['_report_message_body'] ?? null;
            if (is_string($reportSubject) && trim($reportSubject) !== '') {
                $subject = $this->sanitizeSubject($this->resolveLegacy($reportSubject, $context));
            }
            if (is_string($reportBody) && trim($reportBody) !== '') {
                $bodyText = $this->resolveLegacy($reportBody, $context);
                $bodyHtml = null;
                $bodyShort = null;
            }
        }

        // RFC 3834: every message the platform originates is stamped, so a
        // downstream auto-responder knows not to answer it.
        $headers[RenderedMessage::HEADER_AUTO_SUBMITTED] ??= 'auto-generated';

        return new RenderedMessage(
            $subject,
            $bodyText,
            $bodyHtml === '' ? null : $bodyHtml,
            $bodyShort === '' ? null : $bodyShort,
            $headers,
            $envelopeSender,
            $locale,
            $notification->templateKey,
            $notification->parameters,
        );
    }

    /**
     * Every placeholder in a body, which is what the save-time validation
     * checks against the trigger's declared vocabulary.
     *
     * @return list<string>
     */
    public function placeholdersIn(string $body): array
    {
        return $this->parser->tokensIn($body);
    }

    /**
     * Legacy stored parts predate structured sources. They still use the
     * same one-pass placeholder parser, but an unresolved value remains
     * visible. HTML token values are escaped at insertion while the trusted
     * stored markup around them is retained.
     */
    private function resolveLegacy(string $body, TokenContext $context, bool $html = false): string
    {
        if ($body === '') {
            return '';
        }

        $resolved = $this->parser->replace(
            $body,
            function (string $token) use ($context, $html): ?string {
                $value = str_contains($token, '.')
                    ? $this->tokens->resolverFor($token)?->resolve($token, $context)
                    : $context->string($token);

                if ($value === null || !$html) {
                    return $value;
                }

                return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
            },
            true,
        );

        return $resolved['value'];
    }

    private function sanitizeSubject(string $subject): string
    {
        $sanitized = preg_replace('/[\r\n]+/', ' ', $subject);

        return trim(\is_string($sanitized) ? $sanitized : $subject);
    }
}
