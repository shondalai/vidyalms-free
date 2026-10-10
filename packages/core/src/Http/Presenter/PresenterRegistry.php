<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Presenter;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;

/**
 * The management surface's wire shapes, one presenter per bounded context,
 * with the decorator chain every package extends them through.
 *
 * This replaced a single final class with a private constructor, twenty-eight
 * public static methods and thirty imports spanning seven contexts, called by
 * name from forty-two handlers. It was static, so it could not be decorated;
 * final, so it could not be subclassed; and named at the call site, so it
 * could not be injected. The one route seam is additive only, so replacing
 * the route was no way round it either: the first field Pro needs on a course
 * row meant editing a free build file.
 *
 * Handlers take this object by constructor injection exactly as they already
 * take use cases. A package adds a field by registering a ShapeDecorator from
 * its kernel-ready listener, and the free build's bytes are unchanged.
 */
final class PresenterRegistry
{
    public readonly SharedPresenter $shared;
    public readonly AssessmentPresenter $assessment;
    public readonly CurriculumPresenter $curriculum;
    public readonly AccessPresenter $access;
    public readonly CredentialsPresenter $credentials;
    public readonly CertificateTemplatePresenter $certificateTemplates;
    public readonly PlatformPresenter $platform;

    private readonly Decorated $decorated;

    /**
     * @param string $locale the request locale, for the shapes that resolve
     *                       copy at read time rather than at boot
     */
    public function __construct(
        string $locale = 'en-GB',
        /**
         * For the shapes that carry a translated message beside the key they
         * are translated from. Absent, those shapes carry the key twice,
         * which is what a host with no catalogue would have shown anyway.
         */
        ?TranslatorPort $translator = null,
    ) {
        $this->decorated = new Decorated();

        $this->shared = new SharedPresenter($this->decorated);
        $this->assessment = new AssessmentPresenter($this->decorated, $locale);
        $this->curriculum = new CurriculumPresenter($this->decorated, $this->shared, $this->assessment);
        $this->access = new AccessPresenter($this->decorated, $this->shared);
        $this->credentials = new CredentialsPresenter($this->decorated, $this->shared);
        $this->certificateTemplates = new CertificateTemplatePresenter($this->decorated, $translator);
        $this->platform = new PlatformPresenter(
            $this->decorated,
            $this->shared,
            $this->access,
            $this->credentials,
            $this->assessment,
        );
    }

    /**
     * Adds one package's contribution to one or more shapes. Order is
     * registration order, and a decorator sees what the ones before it
     * produced.
     *
     * Resolved when a response is built rather than at construction, so a
     * package that installs after the kernel exists still lands, which is the
     * same promise the provider registries make.
     */
    public function decorate(ShapeDecorator $decorator): void
    {
        $this->decorated->add($decorator);
    }

    /** Whether anything has extended the free build's shapes. */
    public function isExtended(): bool
    {
        return !$this->decorated->isEmpty();
    }
}
