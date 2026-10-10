<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * The question types this site can author, present and mark.
 *
 * **Accessibility is enforced here, not in the browser.** ADR 0011 states the
 * three-mode rule as a property of the extension point: the registry refuses a
 * type that declares pointer dragging without a non-drag pointer mode and a
 * keyboard mode. Implementing it only in the client renderer registry meant it
 * ran in the learner's browser, only for types whose renderer happened to be
 * in that bundle, and never at all for a server-registered type with no client
 * module, which is a registration a third party can perfectly well make. A
 * refusal at registration is the only place the rule is a property of the seam
 * rather than of one consumer of it.
 *
 * The conformance half is not a refusal. A type with no verified kit result
 * registers in a visibly flagged state, which the ledger stores so a site
 * owner can see which installed types are unverified.
 *
 * @extends AbstractProviderRegistry<QuestionTypeInterface>
 */
final class QuestionTypeRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return QuestionTypeInterface::class;
    }

    public function register(ProviderInterface $provider): void
    {
        self::guardAccessibility($provider);

        parent::register($provider);
    }

    public function replace(ProviderInterface $provider): ProviderInterface
    {
        self::guardAccessibility($provider);

        return parent::replace($provider);
    }

    /**
     * Whether this key registered without a verified conformance kit result.
     *
     * A type that declares nothing is flagged, because declaring nothing is
     * exactly the state the flag exists to report.
     */
    public function isFlagged(string $key): bool
    {
        if (!$this->has($key)) {
            return true;
        }

        $provider = $this->get($key);

        if (!$provider instanceof AccessibilityDeclaringInterface) {
            return true;
        }

        $result = $provider->conformanceResult();

        return $result === null || trim($result) === '';
    }

    public function conformanceResultOf(string $key): ?string
    {
        if (!$this->has($key)) {
            return null;
        }

        $provider = $this->get($key);

        if (!$provider instanceof AccessibilityDeclaringInterface) {
            return null;
        }

        $result = $provider->conformanceResult();

        return $result === null || trim($result) === '' ? null : $result;
    }

    private static function guardAccessibility(ProviderInterface $provider): void
    {
        if (!$provider instanceof AccessibilityDeclaringInterface) {
            // A type that declares nothing is taken to be operable by pointer
            // and keyboard without dragging, which is what every non-spatial
            // type is. It cannot have declared dragging, so there is nothing
            // to refuse.
            return;
        }

        $modes = $provider->interactionModes();

        if (!\in_array(AccessibilityDeclaringInterface::POINTER_DRAG, $modes, true)) {
            return;
        }

        $missing = array_values(array_diff(
            [AccessibilityDeclaringInterface::POINTER_NO_DRAG, AccessibilityDeclaringInterface::KEYBOARD],
            $modes,
        ));
        $justification = $provider->essentialDraggingJustification();

        if ($missing === [] || ($justification !== null && trim($justification) !== '')) {
            return;
        }

        throw new AccessibilityRefusedException(sprintf(
            'Question type "%s" declares POINTER_DRAG without %s and without an essential dragging '
            . 'justification, so it cannot be registered.',
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            $provider->key(),
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            implode(' and ', $missing),
        ));
    }
}
