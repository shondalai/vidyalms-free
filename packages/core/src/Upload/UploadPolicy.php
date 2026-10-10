<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Upload;

/**
 * Which uploads a site accepts, as a registry of purposes rather than a switch
 * inside an endpoint.
 *
 * The free build registers two, and they are the two the workbench's file
 * picker asks for:
 *
 *   resource   documents and archives, stored privately, reachable only
 *              through a signed expiring URL, which is what a resource lesson
 *              records in its content
 *   image      raster images only, stored publicly, which is what a course
 *              record's image column holds
 *
 * SVG is deliberately absent from the image purpose and cannot be added to it
 * by an extension that keeps the raster flag: an SVG is a document that
 * executes script in the origin serving it, and the image purpose stores in
 * public. A site that genuinely wants vector artwork registers a purpose of
 * its own with its own storage and its own answer.
 *
 * ## One copy, not one per host
 *
 * This registry, UploadPurpose, UploadIntake and StoredUpload used to exist
 * twice, once inside each adapter, as identical CMS-free PHP. The duplicated
 * part was the security boundary itself: the extension allowlist, the
 * libmagic media-type table and the raster gate. Two copies of an allowlist
 * diverge, and a divergence there means one host accepts bytes the other
 * refuses. Each adapter now keeps only what genuinely differs: reading the
 * site's configured ceiling, firing its own extension hook over this object,
 * and the transport.
 *
 * ## Extending it
 *
 * A host builds the free policy, hands it to its own hook, and uses whatever
 * comes back. register() throws when the key is taken, so two extensions that
 * both believe they own "image" fail loudly instead of one silently winning;
 * replace() requires the key to exist and hands back what it displaced, so an
 * override can narrow the free build's purpose rather than reinventing it.
 * Neither is a way around the endpoint: every purpose, whoever registered it,
 * is validated by the same intake.
 */
final class UploadPolicy
{
    public const PURPOSE_RESOURCE = 'resource';

    public const PURPOSE_IMAGE = 'image';
    public const PURPOSE_ASSIGNMENT = 'assignment';
    public const PURPOSE_ORG_IMPORT = 'org_import';

    /**
     * What a learner hands in: an essay attachment, an uploaded file
     * response, a rendered drawing, a recorded answer.
     *
     * Private, because it is a learner own work and reaches a grader only
     * through a signed expiring URL, and under the path prefix the design
     * own response examples use, so a sweep, a quota and a retention rule
     * can address attempt work without touching course material.
     *
     * Neither existing purpose could stand in. The resource purpose accepts
     * no image, no audio and no video, so a recording could not be stored
     * even by a teacher and an upload item whose accepted types name an
     * image was refused by the intake. The image purpose is public, so
     * routing learner work there would publish every submission.
     */
    public const PURPOSE_ATTEMPT_ARTIFACT = 'attempt_artifact';

    /**
     * The first path segment every attempt artifact is stored under.
     *
     * Published, because a validator on the answer side has to be able to
     * tell a ref this site stored for an attempt from one the client made
     * up: nothing checked the shape at all, so a ref pointing at a lesson
     * file or at "../../../configuration.php" was accepted as an answer.
     */
    public const PURPOSE_ATTEMPT_ARTIFACT_PREFIX = 'attempts';

    /**
     * The ceilings the free build sets, in megabytes, when the host's options
     * do not. The host's own upload ceiling still applies on top and is
     * usually the number that actually binds.
     */
    public const DEFAULT_RESOURCE_MB = 64;

    public const DEFAULT_IMAGE_MB = 8;

    /** A recording of a few minutes is the largest thing a learner hands in. */
    public const DEFAULT_ATTEMPT_ARTIFACT_MB = 64;

    /** @var array<string, UploadPurpose> */
    private array $purposes = [];

    /** @param list<string> $extensions */
    public static function importSource(array $extensions, int $maximumBytes): UploadPurpose
    {
        $text = ['text/plain', 'text/csv', 'application/csv'];
        $gzip = ['application/gzip', 'application/x-gzip'];
        $types = [
            'json' => ['application/json', 'text/plain'],
            'xml' => ['application/xml', 'text/xml', 'text/plain'],
            'zip' => ['application/zip', 'application/x-zip', 'application/x-zip-compressed'],
            'mbz' => $gzip,
            'gz' => $gzip, 'tgz' => $gzip,
            'tar' => ['application/x-tar'],
            'bz2' => ['application/x-bzip2'], 'xz' => ['application/x-xz'],
            'csv' => $text, 'tsv' => $text, 'txt' => $text, 'gift' => $text,
            'sql' => ['text/plain', 'application/sql', 'text/x-sql'],
        ];
        $types['mbz'][] = 'application/x-tar';
        return new UploadPurpose('import', array_intersect_key($types, array_flip($extensions)), $maximumBytes, pathPrefix: 'imports');
    }

    private function __construct()
    {
    }

    /**
     * The free build's two purposes, sized by the host. The host passes the
     * ceilings its own settings carry; everything else about a purpose is the
     * same on every platform, which is what the parity suite checks.
     */
    public static function free(
        ?int $resourceBytes = null,
        ?int $imageBytes = null,
        ?int $artifactBytes = null,
    ): self {
        $policy = new self();

        $policy->register(new UploadPurpose(
            self::PURPOSE_RESOURCE,
            self::resourceTypes(),
            $resourceBytes ?? self::DEFAULT_RESOURCE_MB * 1024 * 1024,
            UploadPurpose::VISIBILITY_PRIVATE,
            'lesson-files',
        ));

        $policy->register(new UploadPurpose(
            self::PURPOSE_IMAGE,
            self::imageTypes(),
            $imageBytes ?? self::DEFAULT_IMAGE_MB * 1024 * 1024,
            UploadPurpose::VISIBILITY_PUBLIC,
            'course-images',
            true,
        ));

        $policy->register(new UploadPurpose(
            self::PURPOSE_ATTEMPT_ARTIFACT,
            self::attemptArtifactTypes(),
            $artifactBytes ?? self::DEFAULT_ATTEMPT_ARTIFACT_MB * 1024 * 1024,
            UploadPurpose::VISIBILITY_PRIVATE,
            self::PURPOSE_ATTEMPT_ARTIFACT_PREFIX,
        ));

        $policy->register(new UploadPurpose(
            self::PURPOSE_ASSIGNMENT,
            self::resourceTypes() + self::imageTypes(),
            $artifactBytes ?? self::DEFAULT_ATTEMPT_ARTIFACT_MB * 1024 * 1024,
            UploadPurpose::VISIBILITY_PRIVATE,
            'submissions',
        ));

        $policy->register(new UploadPurpose(
            self::PURPOSE_ORG_IMPORT,
            [
                'csv' => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
                'zip' => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
            ],
            $resourceBytes ?? self::DEFAULT_RESOURCE_MB * 1024 * 1024,
            UploadPurpose::VISIBILITY_PRIVATE,
            'imports',
        ));

        return $policy;
    }

    /**
     * Adds a purpose. Throws when the key is taken, so a collision is loud.
     */
    public function register(UploadPurpose $purpose): void
    {
        if (isset($this->purposes[$purpose->key])) {
            throw new \InvalidArgumentException(sprintf(
                'The upload purpose "%s" is already registered.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $purpose->key,
            ));
        }

        $this->purposes[$purpose->key] = $purpose;
    }

    /**
     * Overrides a registered purpose and hands back the one it displaced, so
     * the replacement can narrow it rather than reimplement it.
     */
    public function replace(UploadPurpose $purpose): UploadPurpose
    {
        $displaced = $this->purposes[$purpose->key] ?? null;

        if ($displaced === null) {
            throw new \InvalidArgumentException(sprintf(
                'The upload purpose "%s" is not registered, so there is nothing to replace.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $purpose->key,
            ));
        }

        $this->purposes[$purpose->key] = $purpose;

        return $displaced;
    }

    /**
     * Lookups fold case, because a purpose key is lower case by construction
     * and a host's input filter is not something the client can see. Joomla's
     * "cmd" filter preserves case and WordPress's controller lowercased its
     * own, so "purpose=Image" was a 201 on one host and a 422 on the other
     * for the same request.
     */
    public function has(string $key): bool
    {
        return isset($this->purposes[strtolower(trim($key))]);
    }

    public function purpose(string $key): ?UploadPurpose
    {
        return $this->purposes[strtolower(trim($key))] ?? null;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->purposes);
    }

    /**
     * Documents and archives. Every entry states the media types its bytes may
     * sniff as under the libmagic versions in the field: an OOXML or
     * OpenDocument file is a zip to an older one, and the legacy Office
     * formats are OLE containers that report under several names.
     *
     * @return array<string, list<string>>
     */
    private static function resourceTypes(): array
    {
        $ole = [
            'application/msword',
            'application/vnd.ms-excel',
            'application/vnd.ms-powerpoint',
            'application/vnd.ms-office',
            'application/x-ole-storage',
            'application/cdfv2',
            'application/cdfv2-corrupt',
        ];

        return [
            'pdf' => ['application/pdf'],
            'doc' => $ole,
            'xls' => $ole,
            'ppt' => $ole,
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
            'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
            'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
            'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
            'odp' => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],
            'rtf' => ['application/rtf', 'text/rtf'],
            'epub' => ['application/epub+zip', 'application/zip'],
            'txt' => ['text/plain'],
            'md' => ['text/plain', 'text/markdown', 'text/x-markdown'],
            'csv' => ['text/csv', 'text/plain', 'application/csv'],
            'tsv' => ['text/tab-separated-values', 'text/plain'],
            'zip' => ['application/zip'],
            '7z' => ['application/x-7z-compressed'],
            'gz' => ['application/gzip', 'application/x-gzip'],
            'tar' => ['application/x-tar', 'application/tar'],
            'tgz' => ['application/gzip', 'application/x-gzip'],
        ];
    }

    /**
     * Raster images only. There is no SVG entry and no HTML entry, and the
     * purpose that carries this map also demands that the bytes decode as an
     * image, so a document that merely claims a raster media type is refused
     * twice over.
     *
     * @return array<string, list<string>>
     */
    private static function imageTypes(): array
    {
        return [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
        ];
    }

    /**
     * What a learner may hand in: the document set, raster images and the
     * recorder's own containers.
     *
     * It is the union of what the four produced-response types need rather
     * than a per-type list, because the item's own accepted_types narrows it
     * again at save time and two allowlists for one decision is how they
     * diverge. Still no SVG and no HTML: this purpose stores privately, but a
     * grader opens what a learner hands in and a document that executes
     * script is not a thing to open.
     *
     * @return array<string, list<string>>
     */
    private static function attemptArtifactTypes(): array
    {
        return self::resourceTypes() + self::imageTypes() + [
            'webm' => ['video/webm', 'audio/webm'],
            'mp4' => ['video/mp4', 'application/mp4'],
            'm4a' => ['audio/mp4', 'audio/x-m4a', 'video/mp4'],
            'mp3' => ['audio/mpeg', 'audio/mp3'],
            'ogg' => ['audio/ogg', 'video/ogg', 'application/ogg'],
            'oga' => ['audio/ogg', 'application/ogg'],
            'wav' => ['audio/wav', 'audio/x-wav', 'audio/wave'],
        ];
    }
}
