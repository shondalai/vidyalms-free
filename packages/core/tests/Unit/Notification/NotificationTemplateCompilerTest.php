<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationTemplateCompiler;
use Shondalai\VidyaLMS\Core\Application\Notification\TemplatePlaceholderParser;
use Shondalai\VidyaLMS\Core\Application\Notification\TemplateRenderContext;
use Shondalai\VidyaLMS\Core\Application\Notification\TemplateSource;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Registry\Context\TokenContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationChannelRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTemplateBlueprint;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTemplateBlueprintCatalogue;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTriggerRegistry;
use Shondalai\VidyaLMS\Core\Registry\Token\NotificationTokenType;
use Shondalai\VidyaLMS\Core\Registry\Token\TokenResolverRegistry;
use Shondalai\VidyaLMS\Core\Tests\Support\Notification\BlueprintTrigger;
use Shondalai\VidyaLMS\Core\Tests\Support\Notification\CompilerChannel;
use Shondalai\VidyaLMS\Core\Tests\Support\Notification\CompilerTokenResolver;
use Shondalai\VidyaLMS\Core\Tests\Support\Notification\GenericTrigger;

final class NotificationTemplateCompilerTest extends TestCase
{
    public function testLongRuntimeNamesDoNotPoisonAValidTemplate(): void
    {
        $compiled = $this->compiler()->compile(BlueprintTrigger::TEMPLATE_KEY, $this->source(), 'email',
            TemplateRenderContext::real(TokenContext::create(['user.name' => str_repeat('Å', 2000)])));
        self::assertLessThanOrEqual(255, mb_strlen($compiled->subject));
        self::assertStringContainsString('Å', $compiled->bodyText);
    }

    public function testTemplateSourceAcceptsExactBoundsAndKeepsParagraphsAsAList(): void
    {
        $source = TemplateSource::fromArray([
            'subject' => str_repeat('s', 255),
            'preheader' => str_repeat('p', 255),
            'heading' => str_repeat('h', 255),
            'paragraphs' => [str_repeat('b', 5000), ...array_fill(0, 19, 'Paragraph')],
            'action_label' => str_repeat('a', 120),
            'action_url' => str_repeat('u', 2048),
            'footer' => str_repeat('f', 1000),
            'logo_url' => str_repeat('l', 2048),
            'logo_alt' => str_repeat('t', 255),
            'accent_color' => '#a1b2c3',
            'short_body' => str_repeat('m', 500),
        ]);

        self::assertCount(20, $source->paragraphs);
        self::assertSame(5000, mb_strlen($source->paragraphs[0]));
        self::assertSame('#A1B2C3', $source->accentColor);
    }

    public function testTemplateSourceRejectsUnknownFieldsInvalidListsAndValuesPastTheirBounds(): void
    {
        $cases = [
            'unknown' => [$this->source() + ['raw_html' => '<b>no</b>'], 'source.raw_html'],
            'empty list' => [$this->source(['paragraphs' => []]), 'paragraphs'],
            'not a list' => [$this->source(['paragraphs' => ['intro' => 'Hello']]), 'paragraphs'],
            'subject maximum' => [$this->source(['subject' => str_repeat('s', 256)]), 'subject'],
            'paragraph maximum' => [$this->source(['paragraphs' => [str_repeat('p', 5001)]]), 'paragraphs.0'],
            'paragraph count maximum' => [$this->source(['paragraphs' => array_fill(0, 21, 'Paragraph')]), 'paragraphs'],
            'short body maximum' => [$this->source(['short_body' => str_repeat('m', 501)]), 'short_body'],
        ];

        foreach ($cases as $name => [$input, $field]) {
            $failure = $this->validation(
                static fn (): TemplateSource => TemplateSource::fromArray($input),
                $name,
            );

            self::assertArrayHasKey($field, $failure->errors, $name);
        }
    }

    public function testParserRecognisesBareAndDottedTokensOnlyOnceAndDeduplicatesThem(): void
    {
        $parser = new TemplatePlaceholderParser();

        self::assertSame(
            ['user.name', 'learner_name', 'deep.path.value'],
            $parser->tokensIn('{user.name} {learner_name} {user.name} {deep.path.value} {not-a-token}'),
        );

        $result = $parser->replace(
            '{user.name} / {learner_name}',
            static fn (string $token): ?string => match ($token) {
                'user.name' => '{learner_name}<script>',
                'learner_name' => 'Ada',
                default => null,
            },
        );

        self::assertSame('{learner_name}<script> / Ada', $result['value']);
        self::assertSame(['user.name', 'learner_name'], $result['used']);
        self::assertSame([], $result['unresolved']);
    }

    public function testCompilerRejectsUnknownAndMissingRequiredTokensWithFieldErrors(): void
    {
        $compiler = $this->compiler();
        $unknown = $this->source(['paragraphs' => ['Hello {rogue.value}.']]);

        $failure = $this->validation(
            fn () => $compiler->compile(
                BlueprintTrigger::TEMPLATE_KEY,
                $unknown,
                'email',
                TemplateRenderContext::synthetic($this->samples()),
            ),
        );
        self::assertSame('Unknown placeholder {rogue.value}.', $failure->errors['paragraphs.0']);

        $missing = $this->source([
            'subject' => 'Learning update',
            'heading' => 'Learning update',
            'short_body' => 'Learning update',
        ]);
        $failure = $this->validation(
            fn () => $compiler->compile(
                BlueprintTrigger::TEMPLATE_KEY,
                $missing,
                'email',
                TemplateRenderContext::synthetic($this->samples()),
            ),
        );

        self::assertStringContainsString('{user.name}', $failure->errors['required_tokens']);
    }

    public function testUrlFieldsRequireHttpsAndUrlTypedPlaceholders(): void
    {
        $compiler = $this->compiler();

        $textToken = $this->validation(fn () => $compiler->compile(
            BlueprintTrigger::TEMPLATE_KEY,
            $this->source([
                'action_label' => 'Open',
                'action_url' => '{user.name}',
            ]),
            'email',
            TemplateRenderContext::synthetic($this->samples()),
        ));
        self::assertStringContainsString('text, not a URL', $textToken->errors['action_url']);

        $literal = $this->validation(fn () => $compiler->compile(
            BlueprintTrigger::TEMPLATE_KEY,
            $this->source([
                'action_label' => 'Open',
                'action_url' => 'http://example.test/course',
            ]),
            'email',
            TemplateRenderContext::synthetic($this->samples()),
        ));
        self::assertSame('An HTTPS address is required.', $literal->errors['action_url']);

        $unsafeValue = $this->samples();
        $unsafeValue['course.url'] = 'javascript:alert(1)';
        $resolved = $this->validation(fn () => $compiler->compile(
            BlueprintTrigger::TEMPLATE_KEY,
            $this->source([
                'action_label' => 'Open',
                'action_url' => '{course.url}',
            ]),
            'email',
            TemplateRenderContext::synthetic($unsafeValue),
        ));
        self::assertStringContainsString('HTTPS address', $resolved->errors['action_url']);
    }

    public function testCompilerRejectsLiteralAndResolvedLineBreaksInSubjects(): void
    {
        $compiler = $this->compiler();

        foreach (
            [
                'literal' => ["Hello\r\nBcc: attacker@example.test", $this->samples()],
                'resolved' => ['Hello {user.name}', $this->samples(['user.name' => "Ada\r\nBcc: attacker@example.test"])],
            ] as $name => [$subject, $samples]
        ) {
            $failure = $this->validation(fn () => $compiler->compile(
                BlueprintTrigger::TEMPLATE_KEY,
                $this->source(['subject' => $subject]),
                'email',
                TemplateRenderContext::synthetic($samples),
            ));

            self::assertSame('The subject cannot contain line breaks.', $failure->errors['subject'], $name);
        }
    }

    public function testHtmlValuesAreEscapedAndReplacementTextIsNeverParsedAgain(): void
    {
        $compiler = $this->compiler();
        $source = $this->source([
            'subject' => 'Welcome {user.name}',
            'heading' => '{user.name}',
            'paragraphs' => ['Hello {user.name}.'],
            'footer' => 'VidyaLMS',
            'short_body' => 'Welcome {user.name}',
        ]);
        $samples = $this->samples([
            'user.name' => '<img src=x onerror=alert(1)>{site.name}',
            'site.name' => 'MUST NOT BE RECURSIVELY EXPANDED',
        ]);

        $compiled = $compiler->compile(
            BlueprintTrigger::TEMPLATE_KEY,
            $source,
            'email',
            TemplateRenderContext::synthetic($samples),
        );

        self::assertNotNull($compiled->bodyHtml);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;{site.name}', $compiled->bodyHtml);
        self::assertStringNotContainsString('<img', $compiled->bodyHtml);
        self::assertStringNotContainsString('MUST NOT BE RECURSIVELY EXPANDED', $compiled->bodyHtml);
        self::assertStringContainsString('<img src=x onerror=alert(1)>{site.name}', $compiled->bodyText);
    }

    public function testRealRendersFailClosedForRequiredTokensAndOmitOptionalMisses(): void
    {
        $compiler = $this->compiler();
        $source = $this->source([
            'paragraphs' => ['Hello {user.name}; first name: {user.first_name}.'],
        ]);

        $required = $this->validation(fn () => $compiler->compile(
            BlueprintTrigger::TEMPLATE_KEY,
            $source,
            'email',
            TemplateRenderContext::real(TokenContext::create()),
        ));
        self::assertStringContainsString('Required placeholder {user.name}', $required->errors['subject']);

        $compiled = $compiler->compile(
            BlueprintTrigger::TEMPLATE_KEY,
            $source,
            'email',
            TemplateRenderContext::real(TokenContext::create(['user.name' => 'Ada Lovelace'])),
        );

        self::assertStringContainsString('Hello Ada Lovelace; first name: .', $compiled->bodyText);
        self::assertStringNotContainsString('{user.first_name}', $compiled->bodyText);
        self::assertSame(['user.first_name'], $compiled->unresolvedTokens);
    }

    public function testPublicationPreservesPlaceholdersAndNeverStoresSyntheticPeople(): void
    {
        $compiler = $this->compiler();
        $source = $this->source([
            'preheader' => 'A message for {user.name}',
            'paragraphs' => ['Visit {site.name} to continue.'],
            'action_label' => 'Open course',
            'action_url' => '{course.url}',
            'footer' => 'Sent by {site.name}',
            'logo_url' => '{site.logo_url}',
            'logo_alt' => '{site.name}',
        ]);

        $compiled = $compiler->compileForPublication(BlueprintTrigger::TEMPLATE_KEY, $source, 'email');
        $stored = implode("\n", array_filter($compiled->parts(), 'is_string'));

        self::assertStringContainsString('{user.name}', $compiled->subject);
        self::assertStringContainsString('{course.url}', $compiled->bodyText);
        self::assertStringContainsString('href="{course.url}"', (string) $compiled->bodyHtml);
        self::assertStringContainsString('src="{site.logo_url}"', (string) $compiled->bodyHtml);
        self::assertStringNotContainsString('Avery', $stored);
        self::assertSame([], $compiled->unresolvedTokens);
    }

    public function testAnExplicitlyEmptyOptionalSyntheticLogoIsOmittedWithoutAnUnresolvedWarning(): void
    {
        $source = $this->source(['logo_url' => '{site.logo_url}', 'logo_alt' => '{site.name}']);
        $compiled = $this->compiler()->compile(
            BlueprintTrigger::TEMPLATE_KEY,
            $source,
            'email',
            TemplateRenderContext::synthetic($this->samples(['site.logo_url' => ''])),
        );

        self::assertStringNotContainsString('<img', (string) $compiled->bodyHtml);
        self::assertStringNotContainsString('src=""', (string) $compiled->bodyHtml);
        self::assertContains('site.logo_url', $compiled->usedTokens);
        self::assertSame([], $compiled->unresolvedTokens);
    }

    public function testOptionalSyntheticLogoAbsenceDoesNotRelaxMissingUnsafeRequiredOrMalformedValues(): void
    {
        $compiler = $this->compiler();
        $source = $this->source(['logo_url' => '{site.logo_url}', 'logo_alt' => '{site.name}']);
        $missing = $this->samples();
        unset($missing['site.logo_url']);

        foreach (
            [$missing, $this->samples(['site.logo_url' => ' ']),
                $this->samples(['site.logo_url' => 'http://example.test/logo.png']),
                $this->samples(['site.logo_url' => 'javascript:alert(1)'])] as $samples
        ) {
            $failure = $this->validation(fn () => $compiler->compile(
                BlueprintTrigger::TEMPLATE_KEY,
                $source,
                'email',
                TemplateRenderContext::synthetic($samples),
            ));
            self::assertArrayHasKey('logo_url', $failure->errors);
        }

        $requiredCompiler = $this->compiler(requiredTokens: ['user.name', 'site.logo_url']);
        $required = $this->validation(fn () => $requiredCompiler->compile(
            BlueprintTrigger::TEMPLATE_KEY,
            $source,
            'email',
            TemplateRenderContext::synthetic($this->samples(['site.logo_url' => ''])),
        ));
        self::assertStringContainsString('Required placeholder {site.logo_url}', $required->errors['logo_url']);

        foreach (['{site.logo_url}/extra', '{user.name}'] as $authoredLogo) {
            $malformed = $this->validation(fn () => $compiler->compile(
                BlueprintTrigger::TEMPLATE_KEY,
                $this->source(['logo_url' => $authoredLogo, 'logo_alt' => '{site.name}']),
                'email',
                TemplateRenderContext::synthetic($this->samples(['site.logo_url' => ''])),
            ));
            self::assertArrayHasKey('logo_url', $malformed->errors);
        }

        $emptyAction = $this->validation(fn () => $compiler->compile(
            BlueprintTrigger::TEMPLATE_KEY,
            $this->source(['action_url' => '{course.url}', 'action_label' => 'Open course']),
            'email',
            TemplateRenderContext::synthetic($this->samples(['course.url' => ''])),
        ));
        self::assertArrayHasKey('action_url', $emptyAction->errors);
    }

    public function testRealConfiguredLogosStillRenderAndAbsentRealLogosKeepTheirUnresolvedContract(): void
    {
        $compiler = $this->compiler();
        $source = $this->source(['logo_url' => '{site.logo_url}', 'logo_alt' => 'Learning academy']);
        $configured = $compiler->compile(
            BlueprintTrigger::TEMPLATE_KEY,
            $source,
            'email',
            TemplateRenderContext::real(TokenContext::create([
                'user.name' => 'Ada Lovelace',
                'site.logo_url' => 'https://learning.example.test/brand/logo.png',
            ])),
        );
        self::assertStringContainsString(
            'src="https://learning.example.test/brand/logo.png"',
            (string) $configured->bodyHtml,
        );
        self::assertStringContainsString('alt="Learning academy"', (string) $configured->bodyHtml);
        self::assertSame([], $configured->unresolvedTokens);

        $absent = $compiler->compile(
            BlueprintTrigger::TEMPLATE_KEY,
            $source,
            'email',
            TemplateRenderContext::real(TokenContext::create(['user.name' => 'Ada Lovelace'])),
        );
        self::assertStringNotContainsString('<img', (string) $absent->bodyHtml);
        self::assertSame(['site.logo_url'], $absent->unresolvedTokens);
    }

    public function testNonTransactionalLayoutsAlwaysCarryTheFixedComplianceFooter(): void
    {
        $compiler = $this->compiler(NotificationClass::Operational);
        $compiled = $compiler->compileForPublication(BlueprintTrigger::TEMPLATE_KEY, $this->source(), 'email');

        self::assertStringContainsString(
            'You are receiving this learning notification based on your notification settings.',
            $compiled->bodyText,
        );
        self::assertStringContainsString('{unsubscribe.url}', $compiled->bodyText);
        self::assertStringContainsString(
            'Manage or unsubscribe from notifications',
            (string) $compiled->bodyHtml,
        );
        self::assertStringContainsString('href="{unsubscribe.url}"', (string) $compiled->bodyHtml);

        $tokens = array_column($compiler->tokensForTemplate(BlueprintTrigger::TEMPLATE_KEY), null, 'key');
        self::assertSame('url', $tokens['unsubscribe.url']['type']);
        self::assertTrue($tokens['unsubscribe.url']['required']);
    }

    public function testThirdPartyTriggersReceiveASafeTokenFreeGenericBlueprint(): void
    {
        $triggers = new NotificationTriggerRegistry();
        $triggers->register(new GenericTrigger('third_party.trigger', 'third_party.template'));
        $catalogue = new NotificationTemplateBlueprintCatalogue($triggers);
        $registration = $catalogue->forTemplate('third_party.template');

        self::assertTrue($registration->generic);
        self::assertSame([], $registration->blueprint->requiredTokens);
        self::assertSame([], (new TemplatePlaceholderParser())->tokensIn(
            json_encode($registration->blueprint->source, \JSON_THROW_ON_ERROR),
        ));

        $compiler = $this->compilerFromCatalogue($catalogue);
        $compiled = $compiler->compileForPublication(
            'third_party.template',
            $registration->blueprint->source,
            'email',
        );

        self::assertSame('Learning update', $compiled->subject);
        self::assertStringContainsString('Manage or unsubscribe', $compiled->bodyText);
    }

    public function testDuplicateTemplateOwnershipFailsLoudly(): void
    {
        $triggers = new NotificationTriggerRegistry();
        $triggers->register(new GenericTrigger('one.trigger', 'shared.template'));
        $triggers->register(new GenericTrigger('two.trigger', 'shared.template'));
        $catalogue = new NotificationTemplateBlueprintCatalogue($triggers);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('shared.template');
        $catalogue->all();
    }

    /** @param list<string> $requiredTokens */
    private function compiler(
        NotificationClass $class = NotificationClass::Transactional,
        array $requiredTokens = ['user.name'],
    ): NotificationTemplateCompiler {
        $trigger = new BlueprintTrigger(
            $class,
            new NotificationTemplateBlueprint(
                1,
                $this->source(),
                $this->samples(),
                $requiredTokens,
            ),
        );
        $triggers = new NotificationTriggerRegistry();
        $triggers->register($trigger);

        return $this->compilerFromCatalogue(new NotificationTemplateBlueprintCatalogue($triggers));
    }

    private function compilerFromCatalogue(
        NotificationTemplateBlueprintCatalogue $catalogue,
    ): NotificationTemplateCompiler {
        $tokens = new TokenResolverRegistry();
        $tokens->register(new CompilerTokenResolver('user', [
            'user.name' => NotificationTokenType::Text,
            'user.first_name' => NotificationTokenType::Text,
        ]));
        $tokens->register(new CompilerTokenResolver('course', [
            'course.url' => NotificationTokenType::Url,
        ]));
        $tokens->register(new CompilerTokenResolver('site', [
            'site.name' => NotificationTokenType::Text,
            'site.logo_url' => NotificationTokenType::Url,
        ]));
        $tokens->register(new CompilerTokenResolver('unsubscribe', [
            'unsubscribe.url' => NotificationTokenType::Url,
        ]));

        $channels = new NotificationChannelRegistry();
        $channels->register(new CompilerChannel());

        return new NotificationTemplateCompiler($catalogue, $tokens, $channels);
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function source(array $changes = []): array
    {
        return array_replace([
            'subject' => 'Welcome {user.name}',
            'preheader' => 'Your learning is ready.',
            'heading' => 'Welcome {user.name}',
            'paragraphs' => ['Your course is ready to begin.'],
            'action_label' => null,
            'action_url' => null,
            'footer' => 'VidyaLMS',
            'logo_url' => null,
            'logo_alt' => null,
            'accent_color' => '#3155A6',
            'short_body' => 'Welcome {user.name}',
        ], $changes);
    }

    /**
     * @param array<string, string> $changes
     *
     * @return array<string, string>
     */
    private function samples(array $changes = []): array
    {
        return array_replace([
            'user.name' => 'Avery Learner',
            'user.first_name' => 'Avery',
            'course.url' => 'https://example.test/course/one',
            'site.name' => 'VidyaLMS Academy',
            'site.logo_url' => 'https://example.test/logo.png',
            'unsubscribe.url' => 'https://example.test/unsubscribe/sample',
        ], $changes);
    }

    /** @param callable(): mixed $operation */
    private function validation(callable $operation, string $description = ''): ValidationException
    {
        try {
            $operation();
        } catch (ValidationException $failure) {
            return $failure;
        }

        self::fail($description === '' ? 'Expected validation to fail.' : 'Expected validation to fail: ' . $description);
    }
}
