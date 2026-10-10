<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\I18n;

/** Only translations travel in the mount JSON; English is already in the bundle. */
final class UiMessages
{
    /**
     * @param callable(string, string):string $translate
     * @return array<string, string>
     */
    public static function translated(callable $translate): array
    {
        $messages = [];
        foreach (require __DIR__ . '/ui.php' as $key => $english) {
            $value = $translate($key, $english);
            if ($value !== $english && $value !== $key) {
                $messages[$key] = $value;
            }
        }
        return $messages;
    }
}
