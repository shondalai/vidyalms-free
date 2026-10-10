<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Upload;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Tests\Support\FakeRandom;
use Shondalai\VidyaLMS\Core\Tests\Support\FakeStorage;
use Shondalai\VidyaLMS\Core\Upload\UploadIntake;
use Shondalai\VidyaLMS\Core\Upload\UploadPolicy;
use Shondalai\VidyaLMS\Core\Upload\UploadPurpose;

/**
 * The upload security boundary, in the one place it now lives.
 *
 * This code was duplicated verbatim in both adapters, allowlist and media
 * type table included, and neither copy had a test of its own: the only proof
 * either worked was a live smoke run. It is host-neutral PHP over the storage
 * and random ports, so it is tested like anything else.
 */
final class UploadIntakeTest extends TestCase
{
    private FakeStorage $storage;

    private UploadIntake $intake;

    protected function setUp(): void
    {
        $this->storage = new FakeStorage();
        $this->intake = new UploadIntake(UploadPolicy::free(), $this->storage, new FakeRandom());
    }

    public function testAnUnknownPurposeNamesWhatTheSiteAccepts(): void
    {
        try {
            $this->intake->store('bytes', 'notes.pdf', 'firmware', 7);
            self::fail('an unregistered purpose is refused');
        } catch (ApiProblem $problem) {
            self::assertSame(422, $problem->status);
            self::assertStringContainsString('resource', self::fieldErrors($problem)['purpose'] ?? '');
        }
    }

    /**
     * Joomla's cmd input filter preserves case and the WordPress controller
     * lowercased its own, so a purpose of "Image" was a 201 on one host and a
     * 422 on the other for the same request.
     */
    public function testThePurposeKeyIsCaseInsensitive(): void
    {
        $policy = UploadPolicy::free();

        self::assertTrue($policy->has('image'));
        self::assertTrue($policy->has('Image'));
        self::assertTrue($policy->has('  IMAGE '));
        self::assertNotNull($policy->purpose('ReSoUrCe'));
        self::assertFalse($policy->has('firmware'));
    }

    public function testAnEmptyFileIsRefused(): void
    {
        $this->expectException(ApiProblem::class);

        $this->intake->store('', 'notes.pdf', 'resource', 7);
    }

    public function testAFileOverThePurposeCeilingIsRefusedWithBothNumbers(): void
    {
        $policy = UploadPolicy::free(64, null);
        $intake = new UploadIntake($policy, $this->storage, new FakeRandom());

        try {
            $intake->store(str_repeat('a', 128), 'notes.pdf', 'resource', 7);
            self::fail('over the ceiling is refused');
        } catch (ApiProblem $problem) {
            self::assertSame(413, $problem->status);
            self::assertStringContainsString('128 B', $problem->detail ?? '');
            self::assertStringContainsString('64 B', $problem->detail ?? '');
        }
    }

    /**
     * The host's own ceiling lowers the purpose's, never raises it: reporting
     * a configured 64 MB while the server truncates at 8 MB is how an operator
     * loses an afternoon.
     */
    public function testTheHostCeilingLowersThePurposeCeilingAndNeverRaisesIt(): void
    {
        try {
            $this->intake->store(str_repeat('a', 512), 'notes.pdf', 'resource', 7, 100);
            self::fail('the host ceiling binds');
        } catch (ApiProblem $problem) {
            self::assertSame(413, $problem->status);
            self::assertStringContainsString('100 B', $problem->detail ?? '');
        }
    }

    public function testAnExtensionThePurposeDoesNotAcceptIsRefusedAndSaysWhatItTakes(): void
    {
        try {
            $this->intake->store('bytes', 'script.php', 'resource', 7);
            self::fail('an unaccepted extension is refused');
        } catch (ApiProblem $problem) {
            self::assertSame(422, $problem->status);
            self::assertStringContainsString('.php', self::fieldErrors($problem)['file'] ?? '');
            self::assertStringContainsString('pdf', self::fieldErrors($problem)['file'] ?? '');
        }
    }

    public function testAFileNameWithNoExtensionIsRefused(): void
    {
        $this->expectException(ApiProblem::class);

        $this->intake->store('bytes', 'notes', 'resource', 7);
    }

    /**
     * The name and the bytes are two independent facts and both have to
     * agree, which is what stops a PNG being stored as a .pdf and a zip being
     * stored as one.
     */
    public function testTheBytesAndTheNameHaveToAgree(): void
    {
        try {
            $this->intake->store(self::png(), 'notes.pdf', 'resource', 7);
            self::fail('bytes that are not a pdf are refused under .pdf');
        } catch (ApiProblem $problem) {
            self::assertSame(422, $problem->status);
            self::assertStringContainsString('.pdf', self::fieldErrors($problem)['file'] ?? '');
            self::assertStringNotContainsString('application/pdf', self::fieldErrors($problem)['file'] ?? '');
        }
    }

    public function testAnImagePurposeRefusesAVectorDocumentNamedAsARaster(): void
    {
        // The sniff already refuses this, and bytes that got past the sniff
        // would still fail the decode the image purpose demands.
        $this->expectException(ApiProblem::class);

        $this->intake->store('<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'art.png', 'image', 7);
    }

    public function testSvgIsNotAnAcceptedImageExtensionAtAll(): void
    {
        $policy = UploadPolicy::free();
        $image = $policy->purpose('image');

        self::assertNotNull($image);
        self::assertFalse($image->accepts('svg'));
        self::assertSame(['jpg', 'jpeg', 'png', 'gif', 'webp'], $image->extensions());
    }

    public function testAResourceIsStoredPrivatelyUnderAGeneratedPath(): void
    {
        $stored = $this->intake->store(self::pdf(), 'course.php.pdf', 'resource', 42);

        self::assertSame('application/pdf', $stored->mime);
        self::assertNull($stored->url, 'a private file is handed no address here');
        self::assertStringStartsWith('lesson-files/42/', $stored->path);
        self::assertStringEndsWith('.pdf', $stored->path);
        self::assertStringNotContainsString('.php', $stored->path, 'the client name never brings a second extension');
        self::assertArrayHasKey($stored->path, $this->storage->privateFiles);
        self::assertSame([], $this->storage->publicFiles);
    }

    public function testAssignmentPhotosArePrivateAndUseTheLearnersSubmissionNamespace(): void
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=', true);
        self::assertNotFalse($bytes);
        $stored = $this->intake->store($bytes, 'My cake.png', 'assignment', 42);
        self::assertSame('image/png', $stored->mime);
        self::assertStringStartsWith('submissions/42/', $stored->path);
        self::assertNull($stored->url);
        self::assertSame($bytes, $this->storage->privateFiles[$stored->path]);
        self::assertSame([], $this->storage->publicFiles);
    }

    public function testTwoAuthorsUploadingTheSameNameCannotOverwriteEachOther(): void
    {
        $first = $this->intake->store(self::pdf(), 'notes.pdf', 'resource', 1);
        $second = $this->intake->store(self::pdf(), 'notes.pdf', 'resource', 2);

        self::assertNotSame($first->path, $second->path);
    }

    public function testTheDisplayNameKeepsTheAuthorsWordsAndTheValidatedExtension(): void
    {
        $stored = $this->intake->store(self::pdf(), 'C:\\Users\\ada\\Tea notes.PDF', 'resource', 7);

        self::assertSame('Tea notes.pdf', $stored->name);
    }

    public function testAPurposeMayNotBeRegisteredTwiceAndAnOverrideHandsBackWhatItDisplaced(): void
    {
        $policy = UploadPolicy::free();

        try {
            $policy->register(new UploadPurpose('image', ['png' => ['image/png']], 1024));
            self::fail('a taken key is refused');
        } catch (\InvalidArgumentException $refusal) {
            self::assertStringContainsString('already registered', $refusal->getMessage());
        }

        $displaced = $policy->replace(new UploadPurpose('image', ['png' => ['image/png']], 1024));

        self::assertSame('image', $displaced->key);
        self::assertSame(['png'], $policy->purpose('image')?->extensions());
    }

    private static function png(): string
    {
        return "\x89PNG\r\n\x1a\n" . str_repeat("\0", 64);
    }

    private static function pdf(): string
    {
        return "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<<>>\nendobj\n";
    }

    /**
     * @return array<string, string>
     */
    private static function fieldErrors(ApiProblem $problem): array
    {
        $errors = $problem->extensions['errors'] ?? [];

        return \is_array($errors) ? array_map(strval(...), $errors) : [];
    }
    /**
     * A learner hands in bytes, and until this purpose existed there was
     * nowhere for them to land.
     *
     * The four produced-response types are answered by uploading. The only
     * endpoint required teach or manage unconditionally, so a learner got
     * NotAuthorized; and neither existing purpose could have held the bytes
     * anyway, because `resource` accepts no image, no audio and no video and
     * `image` is public, so routing learner work there would publish every
     * submission. The types were authorable and not answerable.
     */
    public function testALearnerArtifactIsStoredPrivatelyUnderItsOwnPrefix(): void
    {
        $purpose = UploadPolicy::free()->purpose(UploadPolicy::PURPOSE_ATTEMPT_ARTIFACT);
        self::assertNotNull($purpose);

        self::assertSame(UploadPurpose::VISIBILITY_PRIVATE, $purpose->visibility);
        self::assertSame(UploadPolicy::PURPOSE_ATTEMPT_ARTIFACT_PREFIX, $purpose->pathPrefix);
        self::assertSame('attempts', $purpose->pathPrefix, 'the prefix the design own response examples use');
        self::assertFalse($purpose->raster, 'a drawing and a recording are not raster images');

        // The union of what the four produced-response types need: the
        // document set, raster images and the recorder's own containers. The
        // item's own accepted_types narrows it again at save time.
        foreach (['pdf', 'docx', 'png', 'jpg', 'webm', 'mp3', 'wav'] as $extension) {
            self::assertArrayHasKey($extension, $purpose->types, $extension);
        }

        // Still no SVG and no HTML. This purpose stores privately, but a
        // grader opens what a learner hands in, and a document that executes
        // script in the origin serving it is not a thing to open.
        self::assertArrayNotHasKey('svg', $purpose->types);
        self::assertArrayNotHasKey('html', $purpose->types);
    }

    public function testAnAuthoringPurposeStillTakesNoLearnerBytes(): void
    {
        $resource = UploadPolicy::free()->purpose(UploadPolicy::PURPOSE_RESOURCE);
        self::assertNotNull($resource);

        self::assertArrayNotHasKey('png', $resource->types);
        self::assertArrayNotHasKey('webm', $resource->types);
    }
}
