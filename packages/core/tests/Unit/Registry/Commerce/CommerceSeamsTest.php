<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Registry\Commerce;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Commerce\CommercePrices;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Commerce\Money;
use Shondalai\VidyaLMS\Core\Domain\Commerce\TaxTreatment;
use Shondalai\VidyaLMS\Core\Port\Commerce\AnswersPriceViews;
use Shondalai\VidyaLMS\Core\Port\Commerce\CommercePort;
use Shondalai\VidyaLMS\Core\Port\Commerce\ContextAwareCommercePort;
use Shondalai\VidyaLMS\Core\Port\Commerce\CoursePrice;
use Shondalai\VidyaLMS\Core\Port\Commerce\PriceOption;
use Shondalai\VidyaLMS\Core\Port\Commerce\PriceView;
use Shondalai\VidyaLMS\Core\Registry\Commerce\BatchPricesCourses;
use Shondalai\VidyaLMS\Core\Registry\Commerce\CommerceSourceInterface;
use Shondalai\VidyaLMS\Core\Registry\Commerce\CommerceSourceRegistry;
use Shondalai\VidyaLMS\Core\Registry\Commerce\ContextAwareCommerceSource;
use Shondalai\VidyaLMS\Core\Registry\Context\CommerceContext;

/**
 * The catalogue seam a second commerce source has to be reachable through.
 *
 * The two capability interfaces are additive over the untouched
 * CommerceSourceInterface, and the port's two new methods are
 * default-implemented, so an adapter built before either existed keeps
 * answering prices unchanged. Both halves are asserted here, because a
 * capability whose default is wrong is worse than no capability.
 */
final class CommerceSeamsTest extends TestCase
{
    public function testTheShippedSourceInterfaceIsUntouched(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(CommerceSourceInterface::class))->getMethods(),
        );
        sort($methods);

        // key() comes from ProviderInterface. Anything else here would be a
        // method added to a shipped interface, which breaks every third-party
        // source and is the failure the two capabilities exist to avoid.
        self::assertSame(['key', 'label', 'priceFor', 'purchaseUrlFor'], $methods);
    }

    public function testABridgeThatPredatesTheWideningStillAnswersAPriceView(): void
    {
        $port = new class implements CommercePort {
            public function priceForCourse(int $courseId): ?CoursePrice
            {
                return $courseId === 7 ? new CoursePrice(4900, 'EUR', 'EUR 49.00') : null;
            }

            public function purchaseUrlForCourse(int $courseId): ?string
            {
                return $courseId === 7 ? 'https://example.test/buy/7' : null;
            }
        };

        $context = CommerceContext::create(Principal::guest());
        $view = (new CommercePrices())->forCourseThroughPort($port, 7, $context);

        self::assertInstanceOf(PriceView::class, $view);
        self::assertSame(4900, $view->headline->amount->minorUnits);
        self::assertSame('EUR', $view->headline->amount->currency);
        self::assertSame('https://example.test/buy/7', $view->headline->purchaseUrl);

        // The default delegation cannot know a jurisdiction, so what it
        // answers is provisional by construction rather than by convention.
        self::assertTrue($view->isProvisional());
        self::assertNull((new CommercePrices())->forCourseThroughPort($port, 8, $context));
    }

    public function testABridgeThatDeclaresTheCapabilityIsAskedWithTheContext(): void
    {
        $port = new class implements CommercePort, ContextAwareCommercePort {
            use AnswersPriceViews;

            public ?CommerceContext $seen = null;

            public function priceForCourse(int $courseId): ?CoursePrice
            {
                return $courseId > 0 ? new CoursePrice(4900, 'EUR') : null;
            }

            public function purchaseUrlForCourse(int $courseId): ?string
            {
                return $courseId > 0 ? 'https://example.test/buy' : null;
            }

            public function priceViewForCourse(int $courseId, CommerceContext $context): ?PriceView
            {
                $this->seen = $context;

                if ($courseId < 1) {
                    return null;
                }

                return new PriceView(
                    new PriceOption('headline', new Money(5880, 'EUR'), TaxTreatment::Gross),
                    resolvedCountry: $context->country,
                );
            }
        };

        $context = CommerceContext::create(Principal::guest(), country: 'DE');
        $view = (new CommercePrices())->forCourseThroughPort($port, 7, $context);

        self::assertNotNull($view);
        self::assertSame($context, $port->seen);
        self::assertSame(5880, $view->headline->amount->minorUnits);
        self::assertSame('DE', $view->resolvedCountry);
        self::assertFalse($view->isProvisional());
    }

    public function testTheBatchDefaultLoopsRatherThanRefusing(): void
    {
        $port = new class implements CommercePort {
            /** @var list<int> */
            public array $asked = [];

            public function priceForCourse(int $courseId): ?CoursePrice
            {
                $this->asked[] = $courseId;

                return $courseId % 2 === 1 ? new CoursePrice(100 * $courseId, 'EUR') : null;
            }

            public function purchaseUrlForCourse(int $courseId): ?string
            {
                return null;
            }
        };

        $views = (new CommercePrices())->forCoursesThroughPort($port, [1, 2, 3], CommerceContext::create(Principal::guest()));

        self::assertSame([1, 2, 3], $port->asked);
        self::assertSame([1, 3], array_keys($views));
        self::assertSame(300, $views[3]->headline->amount->minorUnits);
    }

    public function testTheTraitIsTheShippedDefaultForABridgeWithNothingBetterYet(): void
    {
        $port = new class implements CommercePort, ContextAwareCommercePort {
            use AnswersPriceViews;

            public function priceForCourse(int $courseId): ?CoursePrice
            {
                return $courseId === 4 ? new CoursePrice(2500, 'EUR') : null;
            }

            public function purchaseUrlForCourse(int $courseId): ?string
            {
                return $courseId === 4 ? 'https://example.test/buy/4' : null;
            }
        };

        $context = CommerceContext::create(Principal::guest(), country: 'DE');
        $view = $port->priceViewForCourse(4, $context);

        self::assertNotNull($view);
        self::assertTrue($view->isProvisional(), 'a delegated answer knows no jurisdiction and says so');
        self::assertSame([4], array_keys($port->priceViewsForCourses([3, 4], $context)));
    }

    public function testASourceDeclaringNeitherCapabilityIsStillAValidSource(): void
    {
        $source = new class implements CommerceSourceInterface {
            public function key(): string
            {
                return 'legacy-shop';
            }

            public function label(): string
            {
                return 'Legacy shop';
            }

            public function priceFor(array $course): ?CoursePrice
            {
                return isset($course['id']) ? new CoursePrice(1000, 'EUR') : null;
            }

            public function purchaseUrlFor(array $course): ?string
            {
                return null;
            }
        };

        // A source declaring neither capability is still a valid source, which
        // is the whole promise: the two capabilities are additive over an
        // untouched interface, so nothing already written breaks.
        $capabilities = class_implements($source);
        self::assertNotContains(ContextAwareCommerceSource::class, $capabilities);
        self::assertNotContains(BatchPricesCourses::class, $capabilities);
        self::assertSame(1000, $source->priceFor(['id' => 1])?->amountMinor);

        // And the fallback path answers it a provisional price rather than
        // refusing, which is the documented default when the capability is
        // absent.
        $view = (new CommercePrices())->forCourse($source, ['id' => 1], CommerceContext::create(Principal::guest()));
        self::assertNotNull($view);
        self::assertTrue($view->isProvisional());
        self::assertSame('legacy-shop', $view->sourceKey);
    }

    public function testTheEventArgumentShapeIsPublishedByTheContextItself(): void
    {
        $context = CommerceContext::create(
            Principal::user(5),
            cmsUserId: 5,
            locale: 'de-DE',
            requestedCurrency: 'EUR',
            country: 'DE',
            subdivision: 'BY',
            buyerKind: CommerceContext::BUYER_BUSINESS,
        );

        // Both adapters carry exactly this map: the Joomla event dispatches an
        // argument array and the WordPress filter takes positional arguments,
        // and a shop plugin has to read the same keys on both hosts.
        self::assertSame([
            'locale' => 'de-DE',
            'currency' => 'EUR',
            'country' => 'DE',
            'subdivision' => 'BY',
            'buyer_kind' => 'business',
            'cms_user_id' => 5,
            'background' => false,
            'country_guessed' => false,
            'correlation_id' => null,
        ], $context->toEventArguments());

        self::assertTrue($context->jurisdictionIsSettled());
        self::assertFalse(CommerceContext::create(Principal::guest(), country: 'DE', countryGuessed: true)
            ->jurisdictionIsSettled());
    }

    public function testAPriceOptionDerivesTheNarrowShapeNothingBreaksOn(): void
    {
        $option = new PriceOption('headline', new Money(4900, 'EUR'));
        $price = $option->toCoursePrice('EUR 49.00');

        self::assertSame(4900, $price->amountMinor);
        self::assertSame('EUR', $price->currency);
        self::assertSame('EUR 49.00', $price->formatted);
    }

    public function testTheObviousImplementationOfBothInterfacesCompiles(): void
    {
        // The seam is compiled by a test rather than only described. Before
        // the capability dropped its own purchaseUrlFor(), declaring this
        // class was a fatal error: "Declaration of ::purchaseUrlFor(array
        // $course): ?string must be compatible with
        // ContextAwareCommerceSource::purchaseUrlFor(array $course,
        // CommerceContext $context)".
        $source = new class implements CommerceSourceInterface, ContextAwareCommerceSource {
            public function key(): string
            {
                return 'acme-shop';
            }

            public function label(): string
            {
                return 'Acme';
            }

            public function priceFor(array $course): ?CoursePrice
            {
                return isset($course['id']) ? new CoursePrice(1999, 'EUR') : null;
            }

            public function purchaseUrlFor(array $course): ?string
            {
                return isset($course['id']) ? 'https://example.test/buy' : null;
            }

            public function priceViewFor(array $course, CommerceContext $context): ?PriceView
            {
                if (!isset($course['id'])) {
                    return null;
                }

                return new PriceView(new PriceOption(
                    'headline',
                    new Money(2379, 'EUR'),
                    TaxTreatment::Gross,
                    // A jurisdiction-aware checkout link travels here, which
                    // is where the capability's own docblock now sends it.
                    purchaseUrl: 'https://example.test/de/buy',
                ), resolvedCountry: $context->country, sourceKey: 'acme-shop');
            }
        };

        $view = (new CommercePrices())->forCourse(
            $source,
            ['id' => 1],
            CommerceContext::create(Principal::guest(), country: 'DE'),
        );

        self::assertNotNull($view);
        self::assertSame(2379, $view->headline->amount->minorUnits);
        self::assertSame('https://example.test/de/buy', $view->headline->purchaseUrl);
        self::assertFalse($view->isProvisional());

        $registry = new CommerceSourceRegistry();
        $registry->register($source);
        self::assertTrue($registry->has('acme-shop'));
    }

    public function testASourceKeyedInTheReservedOrganisationNamespaceIsRefusedAtRegistration(): void
    {
        $source = new class implements CommerceSourceInterface {
            public function key(): string
            {
                return 'org7';
            }

            public function label(): string
            {
                return 'Impostor';
            }

            public function priceFor(array $course): ?CoursePrice
            {
                return null;
            }

            public function purchaseUrlFor(array $course): ?string
            {
                return null;
            }
        };

        // The prefix is enforced rather than described. Before the guard this
        // seated cleanly and the source could mint into the organisation
        // family's namespace.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/reserved for organisation references/');
        (new CommerceSourceRegistry())->register($source);
    }
}
