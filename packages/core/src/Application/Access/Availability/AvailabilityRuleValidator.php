<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access\Availability;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityTree;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\SqlAvailabilityRuleRepository;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Registry\Availability\AvailabilityConditionRegistry;
use Shondalai\VidyaLMS\Core\Registry\Availability\DeclaresReferences;
use Shondalai\VidyaLMS\Core\Registry\Availability\RequiresFacts;
use Shondalai\VidyaLMS\Core\Registry\Availability\SupportsPayloadVersions;
use Shondalai\VidyaLMS\Core\Registry\Availability\ValidatesPayload;
use Shondalai\VidyaLMS\Core\Registry\Context\PayloadValidationContext;

/**
 * The one save-time pass, run from every write path that stores a tree.
 *
 * A pass wired only to the availability rule save leaves two thirds of the
 * stored trees unremapped on a course copy and invisible to a batched payload
 * upgrade, which is precisely the failure the reference table exists to
 * prevent, so it is stamped with the owner type and runs from the
 * notification rule save and the badge criteria save too.
 *
 * It refuses a leaf whose type is not registered, a leaf carrying a payload
 * version its provider cannot read, a leaf used outside its declared
 * applicability, a leaf authored on a cart-dependent surface that does not
 * declare {@see EvaluatesRedemption}, a declared fact key no loader answers,
 * whatever the provider's own payload check refuses, and a cycle anywhere
 * across the course with both ends named. Circularity is a validation here rather than a
 * support ticket: the documented case, where a learner must view a topic
 * before posting to a discussion and must post to the discussion before
 * accessing the topic, is otherwise the author's problem forever.
 *
 * The structural refusals the grammar owns, a length mismatch between the
 * children and their visibility flags among them, happen when the document is
 * decoded and not here.
 */
final class AvailabilityRuleValidator
{
    public function __construct(
        private readonly AvailabilityConditionRegistry $conditions,
        private readonly AvailabilityFactLoaders $factLoaders,
        private readonly SqlAvailabilityRuleRepository $rules,
        private readonly DatabasePort $database,
    ) {
    }

    /**
     * @throws ValidationException when the tree may not be stored
     */
    public function validate(
        AvailabilityTree $tree,
        AvailabilitySurface $surface,
        ?GateSubject $subject,
        ?int $courseId,
    ): AvailabilityValidationReport {
        $errors = [];
        $warnings = [];
        $references = [];

        foreach ($tree->leaves() as $path => $leaf) {
            if (!$this->conditions->has($leaf->type)) {
                $errors[$path . '.type'] = sprintf('No condition provider is registered for "%s".', $leaf->type);
                continue;
            }

            $condition = $this->conditions->get($leaf->type);

            $supported = $condition instanceof SupportsPayloadVersions
                ? $condition->supportedPayloadVersions()
                : [1];

            if (!\in_array($leaf->payloadVersion, $supported, true)) {
                $errors[$path . '.payload'] = sprintf(
                    'The "%s" condition cannot read a payload written at version %d.',
                    $leaf->type,
                    $leaf->payloadVersion,
                );
                continue;
            }

            if (!\in_array($surface, $condition->applicability(), true)) {
                $errors[$path . '.type'] = sprintf(
                    'The "%s" condition may not be used on the %s surface.',
                    $leaf->type,
                    $surface->value,
                );
                continue;
            }

            if ($condition instanceof RequiresFacts) {
                foreach ($condition->requiredFacts() as $factKey) {
                    if (!$this->factLoaders->has($factKey)) {
                        $errors[$path . '.type'] = sprintf(
                            'The "%s" condition needs the fact set "%s", which nothing on this site loads.',
                            $leaf->type,
                            $factKey,
                        );
                    }
                }
            }

            if ($condition instanceof ValidatesPayload) {
                $result = $condition->validatePayload(
                    $leaf->payload,
                    PayloadValidationContext::create($this->database, $surface, $subject, $path, $courseId),
                );

                foreach ($result->errors as $key => $parameters) {
                    $errors[$path . '.payload'] = $this->message($key, $parameters);
                }

                foreach ($result->warnings as $key => $parameters) {
                    $warnings[] = ['code' => $key, 'parameters' => $parameters, 'path' => $path];
                }
            }

            $dependencies = $condition instanceof DeclaresReferences ? $condition->dependsOn($leaf->payload) : [];

            if ($dependencies === []) {
                $references[] = [
                    'leaf_path' => $path,
                    'type' => $leaf->type,
                    'payload_version' => $leaf->payloadVersion,
                    'ref_type' => null,
                    'ref_id' => null,
                ];
                continue;
            }

            foreach ($dependencies as $dependency) {
                $references[] = [
                    'leaf_path' => $path,
                    'type' => $leaf->type,
                    'payload_version' => $leaf->payloadVersion,
                    'ref_type' => $dependency->type,
                    'ref_id' => $dependency->id,
                ];
            }
        }

        if ($errors === [] && $subject !== null) {
            $cycle = $this->findCycle($subject, $references, $surface);

            if ($cycle !== null) {
                $errors['tree'] = sprintf(
                    'This restriction is circular: %s already depends on %s.',
                    $cycle[0],
                    $cycle[1],
                );
            }
        }

        if ($errors !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new ValidationException($errors);
        }

        return new AvailabilityValidationReport($warnings, $references);
    }

    /**
     * The cycle check, across the whole course rather than the one rule being
     * saved: a two-step loop through a third item is exactly the case the
     * check exists for.
     *
     * @param list<array{leaf_path: string, type: string, payload_version: int, ref_type: string|null, ref_id: int|null}> $references
     *
     * @return array{0: string, 1: string}|null the two ends, named
     */
    private function findCycle(GateSubject $subject, array $references, AvailabilitySurface $surface): ?array
    {
        $edges = $this->rules->dependencyEdges($surface);
        $edges[$subject->key()] = [];

        foreach ($references as $reference) {
            if ($reference['ref_type'] === null || $reference['ref_id'] === null) {
                continue;
            }

            $edges[$subject->key()][] = (new GateSubject($reference['ref_type'], $reference['ref_id']))->key();
        }

        $seen = [];
        $stack = [$subject->key()];

        while ($stack !== []) {
            $current = array_pop($stack);

            foreach ($edges[$current] ?? [] as $next) {
                if ($next === $subject->key()) {
                    return [$subject->key(), $current];
                }

                if (isset($seen[$next])) {
                    continue;
                }

                $seen[$next] = true;
                $stack[] = $next;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function message(string $key, array $parameters): string
    {
        if ($parameters === []) {
            return $key;
        }

        $parts = [];

        foreach ($parameters as $name => $value) {
            $parts[] = $name . '=' . (\is_scalar($value) ? (string) $value : json_encode($value));
        }

        return $key . ' (' . implode(', ', $parts) . ')';
    }
}
