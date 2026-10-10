<?php

declare(strict_types=1);

/** Check the source input helpers with WordPress's real unslashing and text sanitization. */
use Shondalai\VidyaLMS\WordPress\Content\NotificationPages;
use Shondalai\VidyaLMS\WordPress\Content\Templates;

$wordpress = $argv[1] ?? '';
if (!is_file($wordpress . '/wp-load.php')) {
    throw new RuntimeException('Usage: php request-input-test.php <disposable-wordpress-root>');
}
// Load the edited source helpers before the installed plugin's autoloader can provide older copies.
define('ABSPATH', rtrim($wordpress, '/\\') . '/');
require dirname(__DIR__) . '/vidyalms/src/Content/NotificationPages.php';
require dirname(__DIR__) . '/vidyalms/src/Content/Templates.php';
require $wordpress . '/wp-load.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string) $error . "\n");
    exit(1);
});
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$checks = 0;
function checkInput(mixed $expected, mixed $actual, string $label): void
{
    global $checks;
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
    ++$checks;
}

function inputHelper(string $class, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod($class, $method))->invoke(null, ...$arguments);
}

$originalPost = $_POST;
$originalGet = $_GET;
try {
    $_POST = [];
    checkInput(false, inputHelper(NotificationPages::class, 'isOneClick'), 'missing one-click');
    checkInput([], inputHelper(NotificationPages::class, 'postedChannels'), 'missing channels');
    checkInput([], inputHelper(NotificationPages::class, 'postedRows'), 'missing preferences');

    foreach (['One-Click', ' <b>One-Click</b> ', 'One\\-Click'] as $value) {
        $_POST = [NotificationPages::ONE_CLICK_FIELD => $value];
        checkInput(true, inputHelper(NotificationPages::class, 'isOneClick'), 'sanitized one-click');
    }
    foreach (['one-click', '', 1, true, null, ['One-Click'], new stdClass()] as $value) {
        $_POST = [NotificationPages::ONE_CLICK_FIELD => $value];
        checkInput(false, inputHelper(NotificationPages::class, 'isOneClick'), 'invalid one-click');
    }

    foreach (['email', null, 7, new stdClass()] as $value) {
        $_POST = ['channels' => $value, 'preference' => $value];
        checkInput([], inputHelper(NotificationPages::class, 'postedChannels'), 'non-array channels');
        checkInput([], inputHelper(NotificationPages::class, 'postedRows'), 'non-array preferences');
    }
    $_POST = ['channels' => [
        'named' => ' <b>email</b> ', 'in\\_app', 'sms', 'custom.channel', 'repeat', 'repeat',
        str_repeat('a', 64), '9', 9, true, null, ['email'], new stdClass(), 'Email',
        'bad channel', '', str_repeat('b', 65),
    ]];
    $posted = $_POST;
    checkInput(['email', 'in_app', 'sms', 'custom.channel', 'repeat', 'repeat', str_repeat('a', 64), '9'],
        inputHelper(NotificationPages::class, 'postedChannels'), 'channel shapes, order, duplicates and limits');
    checkInput($posted, $_POST, 'channel request unchanged');

    $_POST = ['preference' => [
        'course\\_progress|em\\ail' => ' <b>daily</b> ',
        '<b>course_progress</b>|email' => 'weekly',
        'assessment|in_app' => 'immediate',
        'account|push' => 'never',
        'marketing|email' => 'dai\\ly',
        'account|sms' => ['daily'],
        'account|webhook' => true,
        7 => 'daily',
        'missing-pair' => 'daily',
        'too|many|parts' => 'daily',
        'Bad|email' => 'daily',
        'assessment|push' => 'monthly',
        str_repeat('a', 65) . '|email' => 'daily',
        'assessment|' . str_repeat('b', 65) => 'daily',
    ]];
    $posted = $_POST;
    checkInput([
        ['category_key' => 'course_progress', 'channel_key' => 'email', 'frequency' => 'daily'],
        ['category_key' => 'course_progress', 'channel_key' => 'email', 'frequency' => 'weekly'],
        ['category_key' => 'assessment', 'channel_key' => 'in_app', 'frequency' => 'immediate'],
        ['category_key' => 'account', 'channel_key' => 'push', 'frequency' => 'never'],
        ['category_key' => 'marketing', 'channel_key' => 'email', 'frequency' => 'daily'],
    ], inputHelper(NotificationPages::class, 'postedRows'), 'preference keys, frequencies, order and shapes');
    checkInput($posted, $_POST, 'preference request unchanged');

    foreach (['postValue', 'getValue'] as $method) {
        $_POST = $_GET = [];
        checkInput('', inputHelper(Templates::class, $method, 'value'), $method . ': missing');
        foreach ([
            [" <b>O\\'Reilly</b>\n Academy%20 ", "O'Reilly Academy"],
            ["one\\\\two", "one\\two"], // Exactly one unslash; do not destroy the remaining backslash.
            [42, '42'], [0, '0'], [3.5, '3.5'], [true, '1'], [false, ''], [null, ''],
            [['nested'], ''], [new stdClass(), ''],
        ] as [$value, $expected]) {
            $_POST = $_GET = ['value' => $value];
            checkInput($expected, inputHelper(Templates::class, $method, 'value'), $method . ': normalized scalar');
        }
    }
} finally {
    $_POST = $originalPost;
    $_GET = $originalGet;
}
echo 'PASS: ', $checks, " request-input checks using real WordPress functions.\n";
