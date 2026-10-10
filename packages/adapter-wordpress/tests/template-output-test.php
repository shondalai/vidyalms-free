<?php

declare(strict_types=1);

/** Render source templates using real escaping functions from a disposable WordPress installation. */
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\WordPress\Content\CoreRouteResult;

$wordpress = $argv[1] ?? '';
if (!is_file($wordpress . '/wp-load.php')) {
    throw new RuntimeException('Usage: php template-output-test.php <disposable-wordpress-root>');
}
require $wordpress . '/wp-load.php';
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, (string) $error . "\n");
    exit(1);
});
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function renderPart(string $part, array $context): DOMXPath
{
    // These commonly named variables belong to the including scope, not the template.
    $course = $title = $row = $copy = $heading = 'host-sentinel';
    $original = $context;
    ob_start();
    try {
        include dirname(__DIR__) . '/vidyalms/templates/' . $part . '.php';
        $html = (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
    check($context === $original, $part . ': modified the incoming context');
    foreach ([$course, $title, $row, $copy, $heading] as $value) {
        check($value === 'host-sentinel', $part . ': overwrote a host variable');
    }
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($document);
    check($xpath->query('//script')->length === 0, $part . ': emitted a script');
    foreach ($xpath->query('//@*') as $attribute) {
        check(!str_starts_with(strtolower($attribute->nodeName), 'on'), $part . ': emitted an event handler');
        if (in_array($attribute->nodeName, ['href', 'src', 'action'], true)) {
            check(!preg_match('/^\s*javascript:/i', $attribute->nodeValue), $part . ': emitted an unsafe URL');
        }
    }
    echo 'PASS ', $part, "\n";
    return $xpath;
}

$payload = '<script>alert("template")</script><img src=x onerror="alert(1)"> & "quotes"';
$config = wp_json_encode(['title' => $payload, 'url' => 'https://example.test/?a=1&b=2']);
foreach ([$config, esc_attr($config)] as $attribute) {
    $xpath = renderPart('part-instructor', [
        'signed_in' => true, 'mount_id' => 'vidyalms-instructor', 'config_attribute' => $attribute,
        'my_learning_url' => 'https://example.test/learning',
    ]);
    check($xpath->evaluate('string(//*[@id="vidyalms-instructor"]/@data-vy-config)') === $config,
        'Config must survive exactly one browser attribute decode, including pre-escaped host data');
}

$xpath = renderPart('part-catalogue', [
    'available' => true, 'total' => 1, 'search' => $payload, 'heading' => $payload,
    'form_action' => 'https://example.test/courses',
    'courses' => [['title' => $payload, 'summary' => $payload, 'url' => 'javascript:alert(1)',
        'image' => 'https://example.test/image.jpg', 'is_free' => true]],
    'pagination' => '<a class="page-numbers" href="https://example.test/page/2" onclick="alert(1)">2</a>' . $payload,
]);
check($xpath->query('//img[@loading="lazy"]')->length === 1, 'Catalogue artwork lost its loading attribute');
check($xpath->query('//a[@class="page-numbers"]')->length === 1, 'Pagination link was stripped');
check(trim($xpath->evaluate('string(//h3/a)')) === $payload, 'Catalogue title was not escaped as text');

$xpath = renderPart('part-course', [
    'available' => true, 'found' => true, 'is_guest' => false, 'can_enrol' => true,
    'course_url' => 'https://example.test/course',
    'course' => (object) ['id' => 123, 'slug' => (object) ['value' => 'sample'], 'priceMode' => 'free',
        'summary' => $payload, 'description' => '<p><strong>Safe formatting</strong></p>' . $payload,
        'image' => 'https://example.test/image.jpg'],
]);
check($xpath->query('//input[@name="vidyalms_nonce"]')->length === 1, 'Enrolment nonce was stripped');
check($xpath->query('//input[@name="_wp_http_referer"]')->length === 1, 'Enrolment referer was stripped');
check($xpath->query('//strong[text()="Safe formatting"]')->length === 1, 'Safe course formatting was stripped');

$nonce = wp_nonce_field('vidyalms-template-output-test', '_wpnonce', true, false);
$centre = CoreRouteResult::fromResponse(new ApiResponse(200, [
    'channels' => [['key' => 'email', 'label' => 'Email']],
    'categories' => [['key' => 'course_progress', 'label' => $payload]],
    'rows' => [['category_key' => 'course_progress', 'channel_key' => 'email',
        'frequency' => 'daily', 'available_frequencies' => ['immediate', 'daily', 'never']]],
]));
$xpath = renderPart('part-notification-preferences', [
    'centre' => $centre, 'form_action' => 'https://example.test/preferences',
    'nonce_field' => str_replace('type="hidden"', 'type="hidden" onclick="alert(1)"', $nonce) . $payload,
]);
check($xpath->evaluate('string(//input[@name="_wpnonce"]/@value)') === wp_create_nonce('vidyalms-template-output-test'),
    'Preferences nonce value changed');
check($xpath->query('//input[@name="_wp_http_referer"]')->length === 1, 'Preferences referer was stripped');
check($xpath->evaluate('string(//select/option[@selected]/@value)') === 'daily', 'Selected frequency changed');

renderPart('part-signin', ['heading' => $payload, 'title' => $payload, 'login_url' => 'javascript:alert(1)',
    'steps' => [['name' => $payload, 'detail' => $payload]]]);
renderPart('part-certificate', ['available' => true, 'answered' => true, 'valid' => true,
    'learner_name' => $payload, 'course_title' => $payload, 'issuer_name' => $payload,
    'issuer_url' => 'javascript:alert(1)', 'code' => $payload]);
renderPart('part-my-learning', ['available' => true, 'is_guest' => false,
    'learning_url' => 'https://example.test/learning', 'organisation_filter' => null, 'organisation_options' => [],
    'enrolments' => [['title' => $payload, 'url' => 'javascript:alert(1)', 'status_label' => $payload]],
]);
renderPart('part-unsubscribe', ['token' => 'signed-token', 'result' => $centre, 'form_action' => 'javascript:alert(1)']);
echo "All source template output checks passed.\n";
