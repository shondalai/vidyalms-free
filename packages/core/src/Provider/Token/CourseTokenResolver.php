<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Token;

use Shondalai\VidyaLMS\Core\Domain\Credentials\IssuanceCourseFacts;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Links\CourseLinkPort;
use Shondalai\VidyaLMS\Core\Registry\Context\IssuanceParameterContext;
use Shondalai\VidyaLMS\Core\Registry\Context\TokenContext;
use Shondalai\VidyaLMS\Core\Registry\Token\ContributesIssuanceParameters;
use Shondalai\VidyaLMS\Core\Registry\Token\DescribesNotificationTokens;
use Shondalai\VidyaLMS\Core\Registry\Token\NotificationTokenType;
use Shondalai\VidyaLMS\Core\Registry\Token\TokenResolverInterface;

/**
 * The course the message is about.
 *
 * It prefers what the trigger already put in the parameter bag and reads the
 * course row only when it has to, because a render happens once per message
 * and a query per token would be one per placeholder.
 */
final class CourseTokenResolver implements TokenResolverInterface, ContributesIssuanceParameters, DescribesNotificationTokens
{
    public const PREFIX = 'course';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly string $siteUrl = '',
        /**
         * The three facts this family answers that the issuance itself does
         * not carry. Absent, they resolve to nothing and their placeholders
         * are left intact, which is the published miss direction.
         */
        private readonly ?IssuanceCourseFacts $facts = null,
        private readonly ?CourseLinkPort $links = null,
    ) {
    }

    /**
     * The family supplies its own inputs at issue and reads them back in
     * resolve(), so category, grade and instructors are frozen with everything
     * else rather than resolved live on a later render.
     *
     * @return array<string, mixed>
     */
    public function parametersFor(IssuanceParameterContext $context): array
    {
        if ($this->facts === null || $context->courseId === null) {
            return [];
        }

        return array_filter([
            'course_category' => $this->facts->categoryOf($context->courseId),
            'course_grade' => $this->facts->gradeFor($context->enrolmentId),
            'course_instructors' => $this->facts->instructorsOf($context->courseId),
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    public function key(): string
    {
        return self::PREFIX;
    }

    public function prefix(): string
    {
        return self::PREFIX;
    }

    public function label(): string
    {
        return 'vidyalms.token.course';
    }

    public function tokens(): array
    {
        return [
            'course.title',
            'course.id',
            'course.url',
            'course.category',
            'course.level',
            // Two the certificate elements need and mail does not. They are
            // answered from what the surface froze rather than from a live
            // read: a grade is the progress context's to compute, and an
            // instructor list is the curriculum context's, and a token
            // resolver that queried either would be a second answer to a
            // question another context already owns.
            'course.grade',
            'course.instructors',
        ];
    }

    public function notificationTokenTypes(): array
    {
        $types = array_fill_keys($this->tokens(), NotificationTokenType::Text);
        $types['course.url'] = NotificationTokenType::Url;

        return $types;
    }

    public function resolve(string $token, TokenContext $context): ?string
    {
        $courseId = $context->courseId;

        if ($courseId === null) {
            $raw = $context->string('course_id');
            $courseId = $raw === null ? null : (int) $raw;
        }

        if ($token === 'course.id') {
            return $courseId === null ? null : (string) $courseId;
        }

        $frozen = match ($token) {
            'course.category' => $context->string('course_category'),
            'course.level' => $context->string('course_level'),
            'course.grade' => $context->string('course_grade'),
            'course.instructors' => $context->string('course_instructors'),
            default => null,
        };

        if ($frozen !== null) {
            return $frozen;
        }

        if ($token === 'course.grade' || $token === 'course.instructors') {
            // Nothing froze them, so nothing answers them. Leaving the
            // placeholder intact is the published miss direction and is
            // better than a live query that would disagree with the frozen
            // snapshot on the next render.
            return null;
        }

        $title = $context->string('course_title');
        $slug = null;
        $level = null;

        $needsRow = $title === null
            || $token === 'course.url'
            || $token === 'course.level'
            || $token === 'course.category';

        if ($needsRow && $courseId !== null && $courseId > 0) {
            $row = $this->database->selectOne(
                Query::from('courses')->select(['title', 'slug', 'level'])->where('id', '=', $courseId),
            );

            if ($row !== null) {
                $title ??= \is_string($row['title'] ?? null) ? (string) $row['title'] : null;
                $slug = \is_string($row['slug'] ?? null) ? (string) $row['slug'] : null;
                $level = \is_string($row['level'] ?? null) && $row['level'] !== '' ? (string) $row['level'] : null;
            }
        }

        return match ($token) {
            'course.title' => $title,
            'course.level' => $level,
            // The catalogue's category is a host taxonomy on both platforms,
            // so it is answered from what the surface supplied and never
            // guessed at here.
            'course.category' => null,
            'course.url' => $slug === null ? null : $this->courseUrl($courseId, $slug),
            default => null,
        };
    }

    private function courseUrl(?int $courseId, string $slug): ?string
    {
        if ($this->links !== null && $courseId !== null) {
            return $this->links->publicUrl($courseId, $slug);
        }
        return $this->siteUrl === '' ? null : rtrim($this->siteUrl, '/') . '/courses/' . rawurlencode($slug);
    }
}
