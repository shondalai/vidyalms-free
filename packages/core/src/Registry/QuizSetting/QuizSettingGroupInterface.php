<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuizSetting;

use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * One group of quiz-level settings a package contributes to the authoring
 * form.
 *
 * The settings route was registry driven for the options inside a field and
 * hardcoded for the fields themselves: its five groups and their field lists
 * were a fixed method, so a package could contribute an option to one of the
 * four provider-keyed fields the core declares and could not contribute a
 * setting of its own. A seam that only lets a stranger fill in the blanks the
 * core happened to leave is the fork ADR 0011 exists to forbid.
 *
 * A group describes itself in the same shape the core's own groups use, which
 * is the contract's SettingsGroup: the form renders it and nothing in the
 * core interprets it. The provider owns its own labels, so it translates
 * through the translator it was constructed with rather than borrowing the
 * core's catalogue.
 *
 * **Describing is not writing.** Nothing here accepts a value: a contributed
 * field is stored by whatever owns it, so a package that adds a setting also
 * owns the route that saves it. The core will not write a member of
 * QuizPolicy it does not know about.
 */
interface QuizSettingGroupInterface extends ProviderInterface
{
    /**
     * The group as the form should render it, or null when this package has
     * nothing to say about this quiz.
     *
     * Null rather than an empty field list, because a group with no fields
     * renders as an empty tab and an author reads that as a broken screen.
     *
     * @return array{
     *     key: string,
     *     label: string,
     *     description?: string|null,
     *     fields: list<array<string, mixed>>
     * }|null
     */
    public function describe(QuizSettingContext $context): ?array;
}
