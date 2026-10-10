<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment\Response;

/**
 * One field of a record response shape: a name with its own cardinality and
 * base type.
 *
 * Without the record signature, "record" is a wildcard that cannot tell a
 * numeric answer's {stringValue, floatValue, unit} from an extended
 * response's {text, files}, so numeric_precision becomes offerable on an
 * essay. The signature is what the shape-keyed fallback renderer, the answer
 * validator and the exporter all key on.
 */
final class ResponseField
{
    public function __construct(
        public readonly string $name,
        public readonly Cardinality $cardinality,
        public readonly BaseType $baseType,
    ) {
    }

    public function signature(): string
    {
        return sprintf('%s:%s:%s', $this->name, $this->cardinality->value, $this->baseType->value);
    }
}
