<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Availability;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;

/**
 * The reader over the preloaded progress fact set.
 *
 * The shape is fixed here rather than in each condition, so the loader and
 * the two conditions that read it cannot disagree about it. It carries a
 * state per item, a title per item so a reason can name what is missing, and
 * the course's item order so an id of -1 can resolve to the previous item.
 */
final class ProgressFacts
{
    public const KEY_STATES = 'states';
    public const KEY_TITLES = 'titles';
    public const KEY_ORDER = 'order';

    /**
     * @param array<string, array<int, string>> $states
     * @param array<string, array<int, string>> $titles
     * @param array<string, list<int>>          $order
     */
    private function __construct(
        private readonly array $states,
        private readonly array $titles,
        private readonly array $order,
    ) {
    }

    public static function of(AvailabilityContext $context): self
    {
        if (!$context->hasFact(AvailabilityContext::FACT_PROGRESS)) {
            return new self([], [], []);
        }

        $raw = $context->factRows(AvailabilityContext::FACT_PROGRESS);

        /** @var array<string, array<int, string>> $states */
        $states = \is_array($raw[self::KEY_STATES] ?? null) ? $raw[self::KEY_STATES] : [];
        /** @var array<string, array<int, string>> $titles */
        $titles = \is_array($raw[self::KEY_TITLES] ?? null) ? $raw[self::KEY_TITLES] : [];
        /** @var array<string, list<int>> $order */
        $order = \is_array($raw[self::KEY_ORDER] ?? null) ? $raw[self::KEY_ORDER] : [];

        return new self($states, $titles, $order);
    }

    /**
     * @param array<string, array<int, string>> $states
     * @param array<string, array<int, string>> $titles
     * @param array<string, list<int>>          $order
     *
     * @return array<string, mixed>
     */
    public static function pack(array $states, array $titles = [], array $order = []): array
    {
        return [self::KEY_STATES => $states, self::KEY_TITLES => $titles, self::KEY_ORDER => $order];
    }

    public function knows(GateSubject $subject): bool
    {
        return isset($this->states[$subject->type][$subject->id]);
    }

    public function stateOf(GateSubject $subject): string
    {
        return $this->states[$subject->type][$subject->id] ?? CompletionCondition::STATE_INCOMPLETE;
    }

    public function titleOf(GateSubject $subject): ?string
    {
        return $this->titles[$subject->type][$subject->id] ?? null;
    }

    /**
     * The item before this one in the course's own order, which is what an id
     * of -1 means. A subject in the first position resolves to nothing and is
     * treated as a missing target, never as satisfied.
     */
    public function previousOf(?GateSubject $subject): ?GateSubject
    {
        if ($subject === null) {
            return null;
        }

        $sequence = $this->order[$subject->type] ?? [];
        $position = array_search($subject->id, $sequence, true);

        if (!\is_int($position) || $position < 1) {
            return null;
        }

        return new GateSubject($subject->type, $sequence[$position - 1]);
    }
}
