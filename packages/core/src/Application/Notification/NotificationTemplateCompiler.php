<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationChannelRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;
use Shondalai\VidyaLMS\Core\Registry\Notification\SupportsHtmlBody;
use Shondalai\VidyaLMS\Core\Registry\Notification\SupportsListHeaders;
use Shondalai\VidyaLMS\Core\Registry\Notification\SupportsShortForm;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTemplateBlueprintCatalogue;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\TemplateBlueprintRegistration;
use Shondalai\VidyaLMS\Core\Registry\Token\DescribesNotificationTokens;
use Shondalai\VidyaLMS\Core\Registry\Token\NotificationTokenType;
use Shondalai\VidyaLMS\Core\Registry\Token\TokenResolverRegistry;

/** Validates, resolves and lays out a closed structured notification source. */
final class NotificationTemplateCompiler
{
    private const UNSUBSCRIBE_TOKEN = 'unsubscribe.url';

    private readonly TemplatePlaceholderParser $parser;
    private readonly NotificationEmailLayout $layout;

    public function __construct(
        private readonly NotificationTemplateBlueprintCatalogue $blueprints,
        private readonly TokenResolverRegistry $tokens,
        private readonly NotificationChannelRegistry $channels,
        ?TemplatePlaceholderParser $parser = null,
        ?NotificationEmailLayout $layout = null,
    ) {
        $this->parser = $parser ?? new TemplatePlaceholderParser();
        $this->layout = $layout ?? new NotificationEmailLayout();
    }

    /**
     * Compiles a strict synthetic preview or a real delivery.
     *
     * Required values fail closed in both modes. Optional synthetic misses
     * are authoring errors; optional real misses are omitted and reported,
     * never delivered as a visible brace placeholder.
     *
     * @param TemplateSource|array<string, mixed> $source
     */
    public function compile(
        string $templateKey,
        TemplateSource|array $source,
        string $channelKey,
        TemplateRenderContext $context,
    ): CompiledNotificationTemplate {
        $registration = $this->blueprints->forTemplate($templateKey);
        $source = $source instanceof TemplateSource ? $source : TemplateSource::fromArray($source);

        if (!$this->channels->has($channelKey)) {
            throw ValidationException::forField('channel_key', 'Unknown notification channel.');
        }

        return $this->compileSource($registration, $source, $channelKey, $context);
    }

    /**
     * Validates with provider-owned synthetic data, then emits stored parts
     * with their placeholders intact. Demo identities can therefore never
     * leak into the legacy/downgrade delivery path.
     *
     * @param TemplateSource|array<string, mixed> $source
     */
    public function compileForPublication(
        string $templateKey,
        TemplateSource|array $source,
        string $channelKey,
    ): CompiledNotificationTemplate {
        $registration = $this->blueprints->forTemplate($templateKey);
        $source = $source instanceof TemplateSource ? $source : TemplateSource::fromArray($source);

        $this->compile(
            $templateKey,
            $source,
            $channelKey,
            TemplateRenderContext::synthetic($registration->blueprint->syntheticValues),
        );

        return $this->compileSource(
            $registration,
            $source,
            $channelKey,
            TemplateRenderContext::publication(),
        );
    }

    /**
     * The exact insert-menu catalogue for one template identity.
     *
     * @return list<array{key:string,type:string,required:bool,sample:string}>
     */
    public function tokensForTemplate(string $templateKey): array
    {
        $registration = $this->blueprints->forTemplate($templateKey);
        $keys = [];

        foreach ($registration->trigger->declaredPlaceholders() as $token) {
            $keys[$token] = true;
        }

        foreach (array_keys($registration->blueprint->syntheticValues) as $token) {
            $keys[$token] = true;
        }

        if ($registration->trigger->messageClass() !== NotificationClass::Transactional) {
            $keys[self::UNSUBSCRIBE_TOKEN] = true;
        }

        $required = array_fill_keys($registration->blueprint->requiredTokens, true);

        if ($registration->trigger->messageClass() !== NotificationClass::Transactional) {
            $required[self::UNSUBSCRIBE_TOKEN] = true;
        }

        $names = array_keys($keys);
        sort($names);

        return array_map(fn (string $token): array => [
            'key' => $token,
            'type' => $this->typeOf($token)->value,
            'required' => isset($required[$token]),
            'sample' => $registration->blueprint->syntheticValues[$token]
                ?? ($token === self::UNSUBSCRIBE_TOKEN ? 'https://example.invalid/notifications/unsubscribe/sample' : ''),
        ], $names);
    }

    private function compileSource(
        TemplateBlueprintRegistration $registration,
        TemplateSource $source,
        string $channelKey,
        TemplateRenderContext $context,
    ): CompiledNotificationTemplate {
        $allowed = array_fill_keys($registration->trigger->declaredPlaceholders(), true);

        // Bare legacy parameters become closed vocabulary only when the
        // provider's blueprint names and samples them explicitly.
        foreach (array_keys($registration->blueprint->syntheticValues) as $token) {
            $allowed[$token] = true;
        }

        $includeCompliance = $registration->trigger->messageClass() !== NotificationClass::Transactional;
        $channel = $this->channels->get($channelKey);

        if ($includeCompliance && !$channel instanceof SupportsListHeaders) {
            throw ValidationException::forField(
                'channel_key',
                'This channel cannot carry the unsubscribe controls required for this notification.',
            );
        }

        if ($includeCompliance) {
            $allowed[self::UNSUBSCRIBE_TOKEN] = true;
        }

        $required = array_fill_keys($registration->blueprint->requiredTokens, true);
        $fields = $source->textFields();
        $occurrences = [];
        $errors = [];

        foreach ($fields as $field => $value) {
            foreach ($this->parser->tokensIn($value) as $token) {
                $occurrences[$token][] = $field;

                if (!isset($allowed[$token])) {
                    $errors[$field] = sprintf('Unknown placeholder {%s}.', $token);
                }
            }
        }

        foreach (array_keys($required) as $token) {
            if (!isset($occurrences[$token])) {
                $errors['required_tokens'] = $this->appendProblem(
                    $errors['required_tokens'] ?? '',
                    sprintf('Required placeholder {%s} is missing.', $token),
                );
            }
        }

        if ($errors !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new ValidationException($errors);
        }

        $used = [];
        $unresolved = [];
        $resolved = $source->toArray();

        foreach ($fields as $field => $value) {
            if ($field === 'action_url' || $field === 'logo_url') {
                continue;
            }

            $expansion = $this->expand($value, $context);
            $this->collect($used, $expansion['used']);
            $this->collect($unresolved, $expansion['unresolved']);
            $this->unresolvedErrors($field, $expansion['unresolved'], $required, $context, $errors);
            $this->put($resolved, $field, $expansion['value']);
        }

        foreach (['action_url', 'logo_url'] as $field) {
            $value = $fields[$field] ?? null;

            if ($value === null) {
                continue;
            }

            $url = $this->resolveUrl(
                $field,
                $value,
                $context,
                $required,
                $used,
                $unresolved,
                $errors,
            );
            $resolved[$field] = $url;

            if ($url === null) {
                $resolved[$field === 'action_url' ? 'action_label' : 'logo_alt'] = null;
            }
        }

        // A runtime-optional label/alternative can disappear even when its
        // paired address resolves. Omit the whole optional element so a
        // recipient never sees an empty link or an image with no text
        // alternative.
        foreach ([['action_label', 'action_url'], ['logo_alt', 'logo_url']] as [$textField, $urlField]) {
            if (trim((string) ($resolved[$textField] ?? '')) === '') {
                $resolved[$textField] = null;
                $resolved[$urlField] = null;
            }
        }

        if ($context->tokenContext !== null) {
            // Authoring limits apply to the template. A long account name or
            // course title must not poison every queued delivery using it.
            $resolved['subject'] = str_replace(["\r", "\n"], ' ', (string) $resolved['subject']);
            foreach (['subject' => 255, 'preheader' => 255, 'heading' => 255, 'footer' => 1000,
                'action_label' => 120, 'logo_alt' => 255, 'short_body' => 500] as $field => $limit) {
                if (is_string($resolved[$field] ?? null) && mb_strlen($resolved[$field]) > $limit) {
                    $resolved[$field] = mb_substr($resolved[$field], 0, $limit - 1) . '…';
                }
            }
            if (is_array($resolved['paragraphs'] ?? null)) {
                $resolved['paragraphs'] = array_map(static fn (string $text): string =>
                    mb_strlen($text) > 5000 ? mb_substr($text, 0, 4999) . '…' : $text, $resolved['paragraphs']);
            }
        }
        if (str_contains((string) $resolved['subject'], "\r") || str_contains((string) $resolved['subject'], "\n")) {
            $errors['subject'] = 'The subject cannot contain line breaks.';
        }

        $complianceUrl = null;

        if ($includeCompliance) {
            $compliance = $this->expand('{' . self::UNSUBSCRIBE_TOKEN . '}', $context);
            $this->collect($used, $compliance['used']);
            $this->collect($unresolved, $compliance['unresolved']);

            if ($compliance['unresolved'] !== [] && !$context->preservesPlaceholders()) {
                $errors['compliance'] = 'The required unsubscribe address cannot be resolved.';
            } else {
                $complianceUrl = $context->preservesPlaceholders()
                    ? '{' . self::UNSUBSCRIBE_TOKEN . '}'
                    : $this->httpsUrlOrNull($compliance['value']);

                if ($complianceUrl === null) {
                    $errors['compliance'] = 'The unsubscribe address must use HTTPS.';
                }
            }
        }

        if ($errors !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new ValidationException($errors);
        }

        $resolvedSource = TemplateSource::fromArray($resolved);
        $parts = $this->layout->render(
            $resolvedSource,
            $channel instanceof SupportsHtmlBody,
            $includeCompliance,
            $complianceUrl,
            $context->locale,
        );
        $bodyShort = $channel instanceof SupportsShortForm ? $resolvedSource->shortBody : null;

        $usedTokens = array_keys($used);
        $unresolvedTokens = $context->preservesPlaceholders() ? [] : array_keys($unresolved);
        sort($usedTokens);
        sort($unresolvedTokens);

        return new CompiledNotificationTemplate(
            (string) $resolved['subject'],
            $parts['body_text'],
            $parts['body_html'],
            $bodyShort,
            $usedTokens,
            $unresolvedTokens,
            $context->locale,
        );
    }

    /**
     * @return array{value:string,used:list<string>,unresolved:list<string>}
     */
    private function expand(string $value, TemplateRenderContext $context): array
    {
        return $this->parser->replace(
            $value,
            fn (string $token): ?string => $this->valueOf($token, $context),
            $context->preservesPlaceholders(),
        );
    }

    private function valueOf(string $token, TemplateRenderContext $context): ?string
    {
        if (array_key_exists($token, $context->syntheticValues)) {
            return $this->nonEmpty($context->syntheticValues[$token]);
        }

        if ($context->tokenContext === null) {
            return null;
        }

        if (!str_contains($token, '.')) {
            return $this->nonEmpty($context->tokenContext->string($token));
        }

        $resolver = $this->tokens->resolverFor($token);

        return $this->nonEmpty($resolver?->resolve($token, $context->tokenContext));
    }

    /**
     * @param array<string, bool>   $required
     * @param array<string, bool>   $used
     * @param array<string, bool>   $unresolved
     * @param array<string, string> $errors
     */
    private function resolveUrl(
        string $field,
        string $value,
        TemplateRenderContext $context,
        array $required,
        array &$used,
        array &$unresolved,
        array &$errors,
    ): ?string {
        $tokens = $this->parser->tokensIn($value);

        if ($tokens !== []) {
            $token = $tokens[0];

            if (\count($tokens) !== 1 || $value !== '{' . $token . '}') {
                $errors[$field] = 'A URL field must be one HTTPS address or one URL placeholder.';

                return null;
            }

            if ($this->typeOf($token) !== NotificationTokenType::Url) {
                $errors[$field] = sprintf('Placeholder {%s} is text, not a URL.', $token);

                return null;
            }

            // An explicitly empty optional synthetic logo is intentional
            // absence, not missing fixture data. Other synthetic misses
            // remain strict: missing samples, required logos and unsafe
            // nonempty addresses still fail. This narrow preview contract
            // neither changes real delivery nor removes the placeholder
            // from the separately compiled publication representation.
            if (
                $field === 'logo_url'
                && $context->strict
                && !isset($required[$token])
                && array_key_exists($token, $context->syntheticValues)
                && $context->syntheticValues[$token] === ''
            ) {
                $used[$token] = true;

                return null;
            }

            $expansion = $this->expand($value, $context);
            $this->collect($used, $expansion['used']);
            $this->collect($unresolved, $expansion['unresolved']);
            $this->unresolvedErrors($field, $expansion['unresolved'], $required, $context, $errors);

            if ($context->preservesPlaceholders()) {
                return $value;
            }

            if ($expansion['unresolved'] !== []) {
                return null;
            }

            $safe = $this->httpsUrlOrNull($expansion['value']);

            if ($safe === null) {
                if (isset($required[$token]) || $context->strict) {
                    $errors[$field] = sprintf('Placeholder {%s} did not resolve to an HTTPS address.', $token);
                } else {
                    $unresolved[$token] = true;
                }
            }

            return $safe;
        }

        $safe = $this->httpsUrlOrNull($value);

        if ($safe === null) {
            $errors[$field] = 'An HTTPS address is required.';
        }

        return $safe;
    }

    private function typeOf(string $token): NotificationTokenType
    {
        $resolver = $this->tokens->resolverFor($token);

        if (!$resolver instanceof DescribesNotificationTokens) {
            return NotificationTokenType::Text;
        }

        return $resolver->notificationTokenTypes()[$token] ?? NotificationTokenType::Text;
    }

    /**
     * @param list<string>          $tokens
     * @param array<string, bool>   $required
     * @param array<string, string> $errors
     */
    private function unresolvedErrors(
        string $field,
        array $tokens,
        array $required,
        TemplateRenderContext $context,
        array &$errors,
    ): void {
        // Publication follows a separate strict synthetic validation pass
        // and deliberately emits the original placeholders for runtime.
        if ($context->preservesPlaceholders()) {
            return;
        }

        foreach ($tokens as $token) {
            if (!isset($required[$token]) && !$context->strict) {
                continue;
            }

            $errors[$field] = $this->appendProblem(
                $errors[$field] ?? '',
                sprintf(
                    '%s placeholder {%s} cannot be resolved.',
                    isset($required[$token]) ? 'Required' : 'Optional',
                    $token,
                ),
            );
        }
    }

    /** @param array<string, mixed> $resolved */
    private function put(array &$resolved, string $field, string $value): void
    {
        if (!str_starts_with($field, 'paragraphs.')) {
            $resolved[$field] = $value;

            return;
        }

        $index = (int) substr($field, strlen('paragraphs.'));
        $resolved['paragraphs'][$index] = $value;
    }

    /**
     * @param array<string, bool> $target
     * @param list<string>        $tokens
     */
    private function collect(array &$target, array $tokens): void
    {
        foreach ($tokens as $token) {
            $target[$token] = true;
        }
    }

    private function httpsUrlOrNull(string $value): ?string
    {
        if (filter_var($value, \FILTER_VALIDATE_URL) === false) {
            return null;
        }

        // Portable PHP 8.1+ core parses URL components identically on Joomla and WordPress; it does not render them.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
        $scheme = parse_url($value, \PHP_URL_SCHEME);
        // Portable PHP 8.1+ core parses URL components identically on Joomla and WordPress; it does not render them.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
        $host = parse_url($value, \PHP_URL_HOST);

        return \is_string($scheme)
            && strtolower($scheme) === 'https'
            && \is_string($host)
            && $host !== ''
                ? $value
                : null;
    }

    private function appendProblem(string $current, string $problem): string
    {
        return $current === '' ? $problem : $current . ' ' . $problem;
    }

    private function nonEmpty(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}
