<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Credential;

use Shondalai\VidyaLMS\Core\Application\Credentials\Png\PngBaker;
use Shondalai\VidyaLMS\Core\Domain\Credentials\IssuanceRecord;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;
use Shondalai\VidyaLMS\Core\Registry\Certificate\RenderedArtefact;
use Shondalai\VidyaLMS\Core\Registry\Context\CredentialContext;
use Shondalai\VidyaLMS\Core\Registry\Credential\CredentialArtefact;
use Shondalai\VidyaLMS\Core\Registry\Credential\CredentialFormatInterface;

/**
 * Embeds the complete Open Badges 2.0 assertion in an uncompressed openbadges
 * iTXt chunk. Uses the configured badge image or a generated canvas.
 */
final class OpenBadges2BakedPngFormat implements CredentialFormatInterface
{
    public const KEY = 'openbadges2.baked-png';

    public function __construct(
        private readonly TranslatorPort $translator,
        private readonly PngBaker $baker = new PngBaker(),
        private readonly ?StoragePort $storage = null,
        private readonly ?string $imagePath = null,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.credential_format.openbadges2_baked_png');
    }

    public function mimeType(): string
    {
        return 'image/png';
    }

    public function build(IssuanceRecord $record, CredentialContext $context): CredentialArtefact
    {
        $assertion = json_encode(
            (new OpenBadges2AssertionFormat($this->translator))->body($record, $context),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        $baked = $this->baker->bake($this->image(), $assertion);

        return CredentialArtefact::of(
            RenderedArtefact::of($baked, $this->mimeType(), 'badge-' . $record->code . '.png'),
            self::KEY,
        );
    }

    private function image(): string
    {
        if ($this->storage === null || $this->imagePath === null || $this->imagePath === '') {
            return $this->baker->canvas();
        }

        try {
            $bytes = $this->storage->readPrivate($this->imagePath);
        } catch (\Throwable) {
            // A configured image that has been deleted is a site problem and
            // not a reason to refuse a credential somebody has earned.
            return $this->baker->canvas();
        }

        return str_starts_with($bytes, "\x89PNG\r\n\x1a\n") ? $bytes : $this->baker->canvas();
    }
}
