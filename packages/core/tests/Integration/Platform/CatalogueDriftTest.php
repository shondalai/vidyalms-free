<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\AssessmentCatalogue;
use Shondalai\VidyaLMS\Core\Boot\AssessmentProviders;
use Shondalai\VidyaLMS\Core\Registry\Context\PresetContext;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionContractInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ScoringPolicyAwareInterface;
use Shondalai\VidyaLMS\Core\Registry\Registries;
use Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator;

/**
 * The catalogue is an artefact, and this is what keeps it one.
 *
 * A checked-in file that drifts from the registries fails the build rather
 * than becoming an argument, and the direction of ownership is asserted too:
 * the providers are right, the file is a copy.
 */
final class CatalogueDriftTest extends TestCase
{
    private const CATALOGUE = __DIR__ . '/../../../../../docs/technical/assessment/catalogue.json';

    private function registries(): Registries
    {
        $registries = new Registries();
        AssessmentProviders::registerInto($registries, new StubTranslator());

        return $registries;
    }

    public function testTheCheckedInCatalogueMatchesTheRegistries(): void
    {
        // The catalogue is checked in under the development monorepo's docs,
        // which the public free source does not carry. Keyed on the folder,
        // so a catalogue missing inside the monorepo still fails.
        if (!is_dir(\dirname(self::CATALOGUE, 2))) {
            self::markTestSkipped('docs/technical is not part of this checkout.');
        }

        $path = realpath(self::CATALOGUE);
        self::assertIsString($path, 'the catalogue has never been generated');

        $checkedIn = file_get_contents($path);
        self::assertIsString($checkedIn);

        $generated = (new AssessmentCatalogue($this->registries()))->toJson();

        self::assertSame(
            $generated,
            $checkedIn,
            'The catalogue has drifted. Run: php tooling/assessment/catalogue.php',
        );
    }

    /**
     * The catalogue is never read at runtime. If it ever were, a type's
     * schema and its secret-key list would live in one shipped file across
     * both editions, and a third-party type would get no coverage from the
     * answer-hiding test.
     */
    public function testNothingUnderSrcReadsTheCatalogue(): void
    {
        $offenders = [];
        $source = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(__DIR__ . '/../../../src', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($source as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (\is_string($contents) && str_contains($contents, 'catalogue.json')) {
                $offenders[] = $file->getFilename();
            }
        }

        self::assertSame([], $offenders);
    }

    /**
     * A preset is checked against the type it compiles to, by a self-test
     * rather than by review: its skeleton must validate against that type's
     * schema, and its default policy must appear in that type's supported
     * list.
     *
     * The likert preset is why this exists. It set a questions column inside
     * the definition, named a policy its base type did not support, and its
     * base type required a correct answer a scale cannot supply. Three
     * contradictions in one four-field row, none of which a reviewer caught.
     */
    public function testEveryPresetCompilesToAValidQuestion(): void
    {
        $registries = $this->registries();

        $context = PresetContext::neutral();

        foreach ($registries->questionPresets->all() as $preset) {
            self::assertTrue(
                $registries->questionTypes->has($preset->baseTypeKey($context)),
                sprintf('preset %s compiles to an unregistered type', $preset->key()),
            );

            $type = $registries->questionTypes->get($preset->baseTypeKey($context));
            self::assertInstanceOf(ScoringPolicyAwareInterface::class, $type);
            self::assertInstanceOf(DefinitionContractInterface::class, $type);

            self::assertContains(
                $preset->defaultPolicy($context),
                $type->supportedPolicies(),
                sprintf('preset %s names a policy %s does not support', $preset->key(), $type->key()),
            );

            $properties = $type->definitionSchema()['properties'] ?? [];
            self::assertIsArray($properties);

            foreach (array_keys($preset->definitionSkeleton($context)) as $field) {
                self::assertArrayHasKey(
                    $field,
                    $properties,
                    sprintf(
                        'preset %s sets "%s", which %s has no field for',
                        $preset->key(),
                        (string) $field,
                        $type->key(),
                    ),
                );
            }
        }
    }
}
