<?php

declare(strict_types=1);

/**
 * VidyaLMS: one way sync from the core course record to its WordPress shell.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Content;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainException;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the vidyalms_course shell in step with the core: publish creates or
 * republishes it, create and update rewrite its title and slug, archive
 * trashes it.
 *
 * The direction never reverses. The core is the record; the post is a URL with
 * a title on it. Nothing here reads the post to decide anything about a course,
 * and nothing here writes a course value into postmeta.
 *
 * ## What tells it a course changed
 *
 * The core's own domain events, delivered through Port\WpEventBridge:
 * vidyalms/course_created, /course_published, /course_updated and
 * /course_archived, each carrying the JSON-safe payload the core emitted.
 *
 * It used to be the REST layer. This class listened on
 * rest_request_after_callbacks and recognised a successful write on a path
 * beginning "/vidyalms/v1/manage/courses", because the use cases dispatched no
 * event of their own and the React workbench was the only thing calling them.
 * That was adapter-side knowledge of the contract's URL shape, and it was
 * wrong in both directions: a course created by an importer, by WP-CLI, by a
 * Pro package or by any caller reaching the use case directly got no shell,
 * while a future contract path could pick one up by accident. The core emits
 * the events now, so this class knows nothing about routes and the shell
 * follows the record however the record was written.
 *
 * The three action names did not change, because they are a published
 * extension point: a plugin that fires vidyalms/course_published itself with a
 * Course, an array or a slug is still understood (see resolve()). What changed
 * is that the free build no longer needs anybody to fire them.
 */
final class CourseSync
{
    public const ACTION_CREATED = 'vidyalms/course_created';
    public const ACTION_PUBLISHED = 'vidyalms/course_published';
    public const ACTION_UPDATED = 'vidyalms/course_updated';
    public const ACTION_ARCHIVED = 'vidyalms/course_archived';

    /**
     * Set while a shell is being written, so a listener that maps posts back to
     * courses cannot start a loop through us.
     */
    private static bool $syncing = false;

    public static function register(): void
    {
        add_action(self::ACTION_CREATED, [self::class, 'onCreated'], 10, 1);
        add_action(self::ACTION_PUBLISHED, [self::class, 'onPublished'], 10, 1);
        add_action(self::ACTION_UPDATED, [self::class, 'onUpdated'], 10, 1);
        add_action(self::ACTION_ARCHIVED, [self::class, 'onArchived'], 10, 1);
    }

    public static function isSyncing(): bool
    {
        return self::$syncing;
    }

    /**
     * A course exists. CreateCourse makes drafts, so this normally does
     * nothing; an importer or a Pro package that creates a course already
     * published gets its shell here rather than having to publish it again.
     *
     * @param Course|array<string, mixed>|string $payload
     */
    public static function onCreated($payload): void
    {
        self::follow($payload);
    }

    /**
     * @param Course|array<string, mixed>|string $payload
     */
    public static function onPublished($payload): void
    {
        $course = self::resolve($payload);

        if ($course !== null) {
            self::upsert($course);
        }
    }

    /**
     * An update only follows an existing shell, or creates one for a course
     * that is already published. A draft course has no public page yet.
     *
     * @param Course|array<string, mixed>|string $payload
     */
    public static function onUpdated($payload): void
    {
        $course = self::resolve($payload);

        if ($course !== null && $course['state'] === 'trashed') {
            self::onArchived($payload);
            return;
        }

        self::follow($payload);
    }

    /**
     * @param Course|array<string, mixed>|string $payload
     */
    public static function onArchived($payload): void
    {
        $course = self::resolve($payload);

        if ($course === null) {
            return;
        }

        $post = self::findShell($course);

        if (!$post instanceof WP_Post || $post->post_status === 'trash') {
            return;
        }

        self::$syncing = true;
        wp_trash_post($post->ID);
        self::$syncing = false;

        // The map survives archiving: republishing the course must land on the
        // same post so the URL that was indexed comes back rather than a copy.
        CourseCpt::rememberShell($course['id'], $post->ID);
    }

    /**
     * The shell follows a published course, and follows an unpublished one
     * only as far as a shell it already has.
     *
     * @param Course|array<string, mixed>|string $payload
     */
    private static function follow($payload): void
    {
        $course = self::resolve($payload);

        if ($course === null) {
            return;
        }

        $shell = self::findShell($course);

        // A trashed shell is not a shell this follows. get_post() answers a
        // trashed post like any other, so an unpublished course "had" one and
        // the upsert below put it back online: a teacher fixing the title of
        // an archived course republished its public page.
        if ($shell instanceof WP_Post && $shell->post_status === 'trash') {
            $shell = null;
        }

        if ($course['state'] === 'published' || $shell instanceof WP_Post) {
            self::upsert($course);
        }
    }

    /**
     * @param array{id: int, title: string, slug: string, state: string} $course
     */
    private static function upsert(array $course): void
    {
        $post = self::findShell($course);

        self::$syncing = true;

        if ($post instanceof WP_Post) {
            $postId = self::update($post, $course);
        } else {
            $postId = self::create($course);
        }

        self::$syncing = false;

        if ($postId > 0) {
            CourseCpt::rememberShell($course['id'], $postId);
        }
    }

    /**
     * @param array{id: int, title: string, slug: string, state: string} $course
     */
    private static function create(array $course): int
    {
        $author = get_current_user_id();

        $result = wp_insert_post(
            [
                'post_type' => CourseCpt::POST_TYPE,
                'post_status' => 'publish',
                'post_title' => sanitize_text_field($course['title']),
                'post_name' => $course['slug'],
                // The body is the site owner's marketing copy, so it is created
                // empty and never touched again by sync.
                'post_content' => '',
                'post_author' => $author > 0 ? $author : 0,
                'comment_status' => 'closed',
                'ping_status' => 'closed',
            ],
            true,
        );

        return is_wp_error($result) ? 0 : (int) $result;
    }

    /**
     * @param array{id: int, title: string, slug: string, state: string} $course
     */
    private static function update(WP_Post $post, array $course): int
    {
        // The status follows the course, and a course that is not published
        // never brings its page back online. Writing 'publish' unconditionally
        // is how a title edit on a trashed shell resurrected it.
        $status = match ($course['state']) {
            'published' => 'publish',
            'draft' => 'draft',
            default => $post->post_status,
        };

        $unchanged = $post->post_title === sanitize_text_field($course['title'])
            && $post->post_name === $course['slug']
            && $post->post_status === $status;

        if ($unchanged) {
            return $post->ID;
        }

        $result = wp_update_post(
            [
                'ID' => $post->ID,
                'post_status' => $status,
                'post_title' => sanitize_text_field($course['title']),
                'post_name' => $course['slug'],
            ],
            true,
        );

        return is_wp_error($result) ? $post->ID : (int) $result;
    }

    /**
     * The mapped post first, the slug second. WordPress may have appended a
     * suffix to a slug that was already taken, so the map is the reliable link
     * and the slug lookup only adopts a shell created before the map existed.
     *
     * @param array{id: int, title: string, slug: string, state: string} $course
     */
    private static function findShell(array $course): ?WP_Post
    {
        $postId = CourseCpt::shellIdForCourse($course['id']);

        if ($postId > 0) {
            $post = get_post($postId);

            if ($post instanceof WP_Post && $post->post_type === CourseCpt::POST_TYPE) {
                return $post;
            }
        }

        if ($course['slug'] === '') {
            return null;
        }

        $found = get_page_by_path($course['slug'], \OBJECT, CourseCpt::POST_TYPE);

        if (!$found instanceof WP_Post) {
            return null;
        }

        // A shell already claimed by another course is not ours to rewrite.
        $owner = CourseCpt::courseIdForShell($found->ID);

        return $owner === 0 || $owner === $course['id'] ? $found : null;
    }

    /**
     * The anti-corruption boundary: whatever a caller hands over becomes the
     * four fields a shell needs, or null.
     *
     * Three shapes are understood, and the order matters because the first is
     * the free build's own:
     *
     *  - a domain event payload, which names the course "course_id" because
     *    that is the vocabulary every core event uses;
     *  - a Course entity, or an array using "id", which is what a plugin
     *    calling the use cases itself would have to hand over;
     *  - a slug, resolved through GetCourseBySlug.
     *
     * @param Course|array<string, mixed>|string $payload
     *
     * @return array{id: int, title: string, slug: string, state: string}|null
     */
    private static function resolve($payload): ?array
    {
        if ($payload instanceof Course) {
            return self::shape($payload->id, $payload->title, $payload->slug->value, $payload->state->value);
        }

        if (\is_array($payload)) {
            $id = $payload['course_id'] ?? $payload['id'] ?? 0;

            return self::shape(
                (int) (\is_scalar($id) ? $id : 0),
                (string) ($payload['title'] ?? ''),
                (string) ($payload['slug'] ?? ''),
                (string) ($payload['state'] ?? ''),
            );
        }

        if (\is_string($payload) && trim($payload) !== '') {
            return self::resolveBySlug(trim($payload));
        }

        return null;
    }

    /**
     * @return array{id: int, title: string, slug: string, state: string}|null
     */
    private static function resolveBySlug(string $slug): ?array
    {
        $kernel = self::kernel();

        if ($kernel === null) {
            return null;
        }

        try {
            $course = $kernel->getCourseBySlug->execute(self::principal(), $slug);
        } catch (DomainException) {
            return null;
        }

        return self::shape($course->id, $course->title, $course->slug->value, $course->state->value);
    }

    /**
     * @return array{id: int, title: string, slug: string, state: string}|null
     */
    private static function shape(int $id, string $title, string $slug, string $state): ?array
    {
        $title = trim(wp_strip_all_tags($title));
        $slug = sanitize_title($slug);

        if ($id <= 0 || $slug === '') {
            return null;
        }

        return [
            'id' => $id,
            'title' => $title !== '' ? $title : $slug,
            'slug' => $slug,
            'state' => $state,
        ];
    }

    private static function principal(): Principal
    {
        $userId = get_current_user_id();

        return $userId > 0 ? Principal::user($userId) : Principal::guest();
    }

    private static function kernel(): ?CoreKernel
    {
        if (!\function_exists('vidyalms_kernel')) {
            return null;
        }

        $kernel = vidyalms_kernel();

        return $kernel instanceof CoreKernel ? $kernel : null;
    }
}
