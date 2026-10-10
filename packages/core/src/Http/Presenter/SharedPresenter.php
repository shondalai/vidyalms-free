<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Presenter;

use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use Shondalai\VidyaLMS\Core\Port\Users\UserAccount;

/**
 * The three references every other presenter embeds: a course, a learner and
 * an account.
 */
final class SharedPresenter
{
    public const SHAPE_COURSE_REF = 'course_ref';
    public const SHAPE_LEARNER_REF = 'learner_ref';
    public const SHAPE_USER_ACCOUNT = 'user_account';

    public function __construct(private readonly Decorated $decorated)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function courseRef(Course $course): array
    {
        return $this->decorated->apply(self::SHAPE_COURSE_REF, [
            'id' => $course->id,
            'title' => $course->title,
            'slug' => $course->slug->value,
        ], $course);
    }

    /**
     * A learner reference carries no email: nothing on the management surface
     * emits one, and a roster of a hundred names is not a directory of a
     * hundred addresses.
     *
     * @return array<string, mixed>
     */
    public function learnerRef(int $userId, ?string $name): array
    {
        return ['id' => $userId, 'name' => $name];
    }

    /**
     * @return array<string, mixed>
     */
    public function userAccount(UserAccount $account): array
    {
        return $this->decorated->apply(self::SHAPE_USER_ACCOUNT, [
            'id' => $account->id,
            'name' => $account->name,
            'email' => $account->email,
        ], $account);
    }
}
