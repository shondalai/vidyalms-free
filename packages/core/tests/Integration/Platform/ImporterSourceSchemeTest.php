<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Registry\Importer\DeclaresSourceSchemes;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImporterInterface;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;
use Shondalai\VidyaLMS\Core\Tests\Support\WordPressLmsFixture;

/**
 * The half of the "both hosts probe the literal cms-db:" defect that the core
 * can prove on its own.
 *
 * Both Import screens decided whether to offer a database control by probing
 * one literal, and three of the six importers R4 shipped answer false to it on
 * purpose: a LearnDash importer must not claim the Community Quiz importer's
 * source string. The screens now ask the importer which schemes it answers to.
 * What is provable here is the importer's side of that contract: every scheme
 * an importer declares is a scheme its own supports() accepts, so a screen that
 * probes the declaration gets the same answer the run will.
 *
 * The screens' own half is asserted in both hosts' smoke harnesses, where a
 * real registry and a real screen are both present.
 */
final class ImporterSourceSchemeTest extends TestCase
{
    public function testEveryDeclaredSchemeIsOneAHostCanComposeASelectorOnto(): void
    {
        $declared = [];

        foreach ($this->importers() as $importer) {
            if (!$importer instanceof DeclaresSourceSchemes) {
                continue;
            }

            self::assertNotSame(
                [],
                $importer->sourceSchemes(),
                $importer->key() . ' declares the interface and answers with nothing.',
            );

            foreach ($importer->sourceSchemes() as $scheme) {
                self::assertMatchesRegularExpression(
                    '/^[a-z][a-z0-9+.-]*:$/',
                    $scheme,
                    $importer->key() . ' declares a scheme a host cannot compose a selector onto.',
                );

                $declared[$scheme][] = $importer->key();
            }
        }

        // "cms-db:" is deliberately shared: it means "the tables of this
        // site's own database" and four importers read them, disambiguating on
        // isInstalled() rather than on the scheme. A product-specific scheme is
        // not shared, and that is the half that matters, because a screen
        // probing "learndash-db:" is asking about LearnDash.
        foreach ($declared as $scheme => $keys) {
            if ($scheme === 'cms-db:') {
                continue;
            }

            self::assertCount(
                1,
                $keys,
                sprintf('%s claim "%s", so a screen probing it would offer the wrong one.', implode(' and ', $keys), $scheme),
            );
        }

        self::assertGreaterThanOrEqual(
            4,
            \count($declared),
            'cms-db: plus the three the literal probe could not see.',
        );
    }

    /**
     * The three schemes the shipped screens could not reach at all, named so
     * the regression is a failure rather than an absence.
     */
    public function testTheThreeWordPressFormatImportersDeclareTheirOwnSchemes(): void
    {
        $schemes = [];

        foreach ($this->importers() as $importer) {
            if ($importer instanceof DeclaresSourceSchemes) {
                $schemes[$importer->key()] = $importer->sourceSchemes();
            }
        }

        self::assertSame(['learndash-db:'], $schemes['learndash'] ?? []);
        self::assertSame(['tutor-db:'], $schemes['tutor-lms'] ?? []);
        self::assertSame(['lifterlms-db:'], $schemes['lifterlms'] ?? []);
    }

    /**
     * With the product actually present, the declared scheme is the string the
     * importer answers yes to and the string the screen composes onto.
     *
     * This is what the shipped screens got wrong: they probed "cms-db:", which
     * every one of these three refuses even on a site where the product is
     * installed, so the database control was never offered and the only string
     * the screen could build was one the importer rejects.
     */
    public function testWithTheProductInstalledTheDeclaredSchemeIsTheOneTheImporterAcceptsAndTheScreenComposes(): void
    {
        $products = [
            'learndash' => [WordPressLmsFixture::LEARNDASH, 'sfwd-courses'],
            'tutor-lms' => [WordPressLmsFixture::TUTOR, 'courses'],
            'lifterlms' => [WordPressLmsFixture::LIFTERLMS, 'course'],
        ];

        foreach ($products as $key => [$tables, $courseType]) {
            $env = TestPorts::create();
            $source = WordPressLmsFixture::install($env->database, $tables);
            $source->post(10, $courseType, 'A course this product owns');
            $importer = (new CoreKernel($env->ports))->registries->importers->get($key);

            self::assertInstanceOf(DeclaresSourceSchemes::class, $importer);

            $scheme = $importer->sourceSchemes()[0] ?? '';

            self::assertTrue($importer->supports($scheme), $key . ' refuses the scheme it declares.');
            self::assertTrue(
                $importer->supports($scheme . 'courses=7'),
                $key . ' refuses its own scheme with the selector a screen appends.',
            );
            self::assertFalse(
                $importer->supports('cms-db:'),
                $key . ' must not claim the Community Quiz importer\'s source string.',
            );
        }
    }

    /**
     * @return list<ImporterInterface>
     */
    private function importers(): array
    {
        $env = TestPorts::create();

        return (new CoreKernel($env->ports))->registries->importers->all();
    }
}
