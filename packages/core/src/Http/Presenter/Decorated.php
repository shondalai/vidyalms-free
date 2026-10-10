<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Presenter;

/**
 * The decorator chain, shared by every presenter.
 *
 * One object rather than a base class, because the presenters differ in what
 * they depend on and a base class would put inheritance in the way of that
 * for the sake of one method.
 */
final class Decorated
{
    /** @var list<ShapeDecorator> */
    private array $decorators = [];

    public function add(ShapeDecorator $decorator): void
    {
        $this->decorators[] = $decorator;
    }

    /**
     * @param array<string, mixed> $emitted
     *
     * @return array<string, mixed>
     */
    public function apply(string $shape, array $emitted, object $source): array
    {
        foreach ($this->decorators as $decorator) {
            if ($decorator->supports($shape)) {
                $emitted = $decorator->decorate($emitted, $source);
            }
        }

        return $emitted;
    }

    public function isEmpty(): bool
    {
        return $this->decorators === [];
    }
}
