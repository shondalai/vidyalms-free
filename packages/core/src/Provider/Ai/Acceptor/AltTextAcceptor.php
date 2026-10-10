<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Acceptor;

use Shondalai\VidyaLMS\Core\Application\Curriculum\GetAuthoringLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateLesson;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Registry\Ai\AcceptedArtefact;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraft;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraftAcceptorInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\AiAcceptContext;

/**
 * Alternative text becomes part of the lesson it describes, through the
 * ordinary lesson update.
 *
 * It reads the stored content first and writes it back with one key changed,
 * rather than writing a content payload of its own. That is the difference
 * between accepting a suggestion and replacing a lesson: an author who
 * accepts alt text for one image has not asked for the rest of their page to
 * be rewritten by whatever the draft happened to contain.
 *
 * The lowest risk tier in the catalogue is the one this serves, and it is
 * still a person who presses accept: no tier permits automatic acceptance,
 * and a light review panel is the whole difference the tier makes.
 */
final class AltTextAcceptor implements AiDraftAcceptorInterface
{
    public const KEY = 'alt_text';

    public const PAYLOAD_LESSON_ID = 'lesson_id';
    public const PAYLOAD_ASSET_KEY = 'asset_key';
    public const PAYLOAD_ALT_TEXT = 'alt_text';

    /** The content key alternative text is stored under, keyed by asset. */
    public const CONTENT_KEY = 'alt_text';

    public function __construct(
        private readonly GetAuthoringLesson $lessons,
        private readonly UpdateLesson $updateLesson,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'ai.acceptor.alt_text.label';
    }

    public function accept(AiDraft $draft, AiAcceptContext $context): AcceptedArtefact
    {
        $lessonId = $draft->payload[self::PAYLOAD_LESSON_ID] ?? null;
        $assetKey = $draft->payload[self::PAYLOAD_ASSET_KEY] ?? null;
        $altText = $draft->payload[self::PAYLOAD_ALT_TEXT] ?? null;

        if (!\is_int($lessonId) || $lessonId <= 0) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField(self::PAYLOAD_LESSON_ID, 'An accepted alt text names its lesson.');
        }

        if (!\is_string($assetKey) || $assetKey === '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField(self::PAYLOAD_ASSET_KEY, 'An accepted alt text names its image.');
        }

        if (!\is_string($altText) || trim($altText) === '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField(self::PAYLOAD_ALT_TEXT, 'An accepted alt text has text in it.');
        }

        $authoring = $this->lessons->execute($context->accepter, $lessonId);
        $content = $authoring->content;
        $existing = $content[self::CONTENT_KEY] ?? [];
        $map = \is_array($existing) ? $existing : [];
        $map[$assetKey] = trim($altText);
        $content[self::CONTENT_KEY] = $map;

        $this->updateLesson->execute($context->accepter, $lessonId, ['content' => $content]);

        return AcceptedArtefact::of(
            $context->targetExtensionPoint,
            $context->targetProviderKey,
            'lesson',
            $lessonId,
            ['asset_key' => $assetKey],
        );
    }
}
