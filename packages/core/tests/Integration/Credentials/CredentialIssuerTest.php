<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Application\Credentials\IssuerIdentity;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialUrlUnavailable;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Provider\Credential\VidyaLmsJsonFormat;
use Shondalai\VidyaLMS\Core\Registry\Context\CredentialContext;
use Shondalai\VidyaLMS\Core\Registry\Context\TokenContext;
use Shondalai\VidyaLMS\Core\Provider\Token\SiteTokenResolver;

/**
 * Who issued this, and where the answer comes from.
 *
 * Every shipped site emitted `"issuer": {"id": "", "type": ["Profile"],
 * "name": ""}` inside its credentials and `"issuer": []` on its verification
 * page, because the `certificates.issuer` record was written by nothing: no
 * adapter seeded it, no screen set it, no route accepted it. An empty string
 * is not an IRI and both members are required, so the document failed the
 * very schema it claims to be one signature away from.
 *
 * The same gap left `{site.name}` and `{site.logo_url}` unresolved, so the
 * seeded free template rendered those literal strings on every install.
 */
final class CredentialIssuerTest extends CredentialsTestCase
{
    public function testAnUnconfiguredSiteStillEmitsAnIssuerIdAndAName(): void
    {
        $identity = new IssuerIdentity($this->settings(), $this->ports->translator);

        $resolved = $identity->resolve();

        self::assertNotSame('', $resolved['id']);
        self::assertNotSame('', $resolved['name']);
        self::assertSame(IssuerIdentity::FALLBACK_ID, $resolved['id']);
    }

    public function testTheHostFactsAnswerBeforeAnyAdministratorHasTyped(): void
    {
        $identity = new IssuerIdentity(
            $this->settings(),
            $this->ports->translator,
            'Northlight College',
            'https://northlight.example/',
            'https://northlight.example/logo.png',
        );

        self::assertSame([], $identity->stored(), 'nothing has been written yet');
        self::assertSame([
            'id' => 'https://northlight.example',
            'name' => 'Northlight College',
            'url' => 'https://northlight.example',
            'image' => 'https://northlight.example/logo.png',
        ], $identity->resolve());
    }

    public function testTheStoredRecordOverridesTheHostFactsFieldByField(): void
    {
        $identity = new IssuerIdentity(
            $this->settings(),
            $this->ports->translator,
            'Host Name',
            'https://host.example',
        );

        $identity->save(['name' => 'Northlight', 'nonsense' => 'ignored']);

        $resolved = $identity->resolve();

        self::assertSame('Northlight', $resolved['name']);
        self::assertSame('https://host.example', $resolved['url']);
        self::assertSame(['name' => 'Northlight'], $identity->stored());
    }

    public function testAnIssuerAddressThatIsNotAWebAddressIsRefusedAtTheWrite(): void
    {
        $identity = new IssuerIdentity($this->settings(), $this->ports->translator);

        // The url becomes the credential's issuer IRI and the href on the
        // public verification page, where htmlspecialchars restricts no
        // scheme. WordPress's esc_url strips it and Joomla's escape did not,
        // so the two hosts disagreed about the same stored payload.
        $this->expectException(ValidationException::class);

        $identity->save([
            'name' => 'Northlight',
            'url' => 'javascript:alert(document.domain)',
        ]);
    }

    public function testAnIssuerImageMustBeAWebAddressToo(): void
    {
        $identity = new IssuerIdentity($this->settings(), $this->ports->translator);

        try {
            $identity->save(['name' => 'Northlight', 'image' => 'data:image/png;base64,AAAA']);
            self::fail('a data URI was accepted as the logo on every rendered certificate');
        } catch (ValidationException $refusal) {
            self::assertArrayHasKey('image', $refusal->errors);
        }

        self::assertCount(0, $identity->stored(), 'a refused write stored nothing');

        $stored = $identity->save([
            'name' => 'Northlight',
            'url' => 'https://northlight.example',
            'image' => 'https://northlight.example/logo.png',
        ]);

        self::assertSame('https://northlight.example/logo.png', $stored['image'] ?? null);
    }

    public function testTheCredentialNeverCarriesAnEmptyIssuerIdOrName(): void
    {
        $format = new VidyaLmsJsonFormat($this->ports->translator);
        $certificate = $this->writeCertificate(5, 7, 10, 'ISSUERCODE01');

        $body = $format->body(
            $certificate->toIssuanceRecord('CertificateOfCompletion', []),
            CredentialContext::create(
                $this->ports->clock->now(),
                (new IssuerIdentity($this->settings(), $this->ports->translator))->asMap(),
                'https://example.test/api/v1/credentials',
            ),
        );

        $issuer = $body['issuer'];

        self::assertIsArray($issuer);
        self::assertNotSame('', $issuer['id']);
        self::assertNotSame('', $issuer['name']);
    }

    public function testTheSiteTokensResolveFromTheIssuerWhenTheSiteRowsAreUnset(): void
    {
        $identity = new IssuerIdentity(
            $this->settings(),
            $this->ports->translator,
            'Northlight College',
            'https://northlight.example',
            'https://northlight.example/logo.png',
        );

        $resolver = new SiteTokenResolver($this->settings(), $identity);
        $context = TokenContext::create();

        // The seeded free template names all three, and nothing outside the
        // tests ever wrote a site.* row, so every install rendered the
        // literal placeholder and an img tag pointing at "{site.logo_url}".
        self::assertSame('Northlight College', $resolver->resolve('site.name', $context));
        self::assertSame('https://northlight.example', $resolver->resolve('site.url', $context));
        self::assertSame('https://northlight.example/logo.png', $resolver->resolve('site.logo_url', $context));
    }

    public function testAnExplicitSiteRowStillWins(): void
    {
        $this->settings()->set(SiteTokenResolver::SETTING_NAME, 'Explicitly Set');

        $resolver = new SiteTokenResolver(
            $this->settings(),
            new IssuerIdentity($this->settings(), $this->ports->translator, 'Host Name'),
        );

        self::assertSame(
            'Explicitly Set',
            $resolver->resolve('site.name', TokenContext::create()),
        );
    }

    public function testMintingACredentialIdWithNoConfiguredBaseIsANamedRefusal(): void
    {
        $context = CredentialContext::create($this->ports->clock->now());

        self::assertFalse($context->hasCredentialBaseUrl());

        // Never composed from the request: issuance and bulk reissue are
        // scheduled work, and a credential minted by cron used to carry an
        // unresolvable id for ever inside a document that cannot be corrected.
        $this->expectException(CredentialUrlUnavailable::class);
        $this->expectExceptionMessage('credential_base_url');

        $context->credentialUrl('some-uuid');
    }

    public function testMintingAStatusListAddressWithNoConfiguredBaseIsANamedRefusal(): void
    {
        $context = CredentialContext::create(
            $this->ports->clock->now(),
            [],
            'https://example.test/api/v1/credentials',
        );

        self::assertTrue($context->hasCredentialBaseUrl());
        self::assertFalse($context->hasStatusListBaseUrl());

        $this->expectException(CredentialUrlUnavailable::class);
        $this->expectExceptionMessage('status_list_base_url');

        $context->statusListUrl('list-uuid');
    }

    public function testTheRefusalNamesTheOptionTheHostHasToSupply(): void
    {
        self::assertSame(
            'credential_base_url',
            CredentialUrlUnavailable::credentialBase()->option,
        );
        self::assertSame(
            'status_list_base_url',
            CredentialUrlUnavailable::statusListBase()->option,
        );
    }
}
