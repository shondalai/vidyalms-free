<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Settings;

/**
 * A provider that has configuration, and says what it is.
 *
 * A capability interface rather than a method on nine shipped provider
 * interfaces, which is the rule this design states once and applies
 * everywhere: adding a method to a shipped interface breaks every external
 * implementation, and 02-product-editions.md invites third parties to write
 * these.
 *
 * **Default when absent: the provider reports only the fields a site has
 * already stored, typed by their stored values.** That was the whole
 * behaviour before this interface existed, and it is why the email channel's
 * feedback secret could be written by PUT and had nowhere to be entered: the
 * settings screen renders the field list the GET returns, the GET derived it
 * from the stored row, and the stored row is empty until somebody writes the
 * field that is not there. A seam whose only entry point is the API is a
 * seam the site owner cannot reach.
 *
 * The fields are the contract's SettingsField shape, which is the same shape
 * the quiz settings form already renders, so the screen needs no second
 * renderer. The provider owns its labels and translates them itself, for the
 * reason QuizSettingGroupInterface gives: a provider's copy belongs to the
 * package that ships it and not to the core's catalogue.
 *
 * **Declaring is not writing.** Nothing here accepts a value. The route
 * merges the stored row onto the declaration, hides every secret, and stores
 * what the PUT sent; a provider that declares a field it never reads gets a
 * field on a screen and nothing else.
 */
interface DeclaresSettings
{
    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     description?: string|null,
     *     fields: list<array<string, mixed>>
     * }
     */
    public function settingsGroup(SettingsDeclarationContext $context): array;
}
