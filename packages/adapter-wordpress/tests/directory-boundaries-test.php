<?php

declare(strict_types=1);

/** Verify exception data is preserved as JSON and escaped where it becomes HTML. */
if (PHP_SAPI !== 'cli' || !is_file(($argv[1] ?? '') . '/wp-load.php')) {
    exit("Usage: php directory-boundaries-test.php <local-wordpress-root>\n");
}
require $argv[1] . '/wp-load.php';

use Shondalai\VidyaLMS\WordPressVendor\Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Route;
use Shondalai\VidyaLMS\WordPress\Rest\CoreBodyEmitter;

$payload = '</script><img src=x onerror="alert(1)"> & <script>alert(2)</script>';
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $checks++;
};
$failures = [
    new ValidationException(['title' => $payload]),
    new ConflictException($payload, ['remaining' => 3]),
    EntityNotFoundException::for('Lesson', $payload),
    new NotAuthorizedException($payload),
    new class ($payload) extends DomainException {},
    ApiProblem::badRequest($payload),
    new RuntimeException('PRIVATE_INTERNAL_' . $payload),
];
foreach ($failures as $failure) {
    $kernel = new ApiKernel(new NullLogger());
    $kernel->register(new Route('POST', '/review', new class ($failure) implements HandlerInterface {
        public function __construct(private readonly Throwable $failure) {}
        public function handle(ApiRequest $request): ApiResponse { throw $this->failure; }
    }));
    ob_start();
    $response = $kernel->handle(new ApiRequest('POST', '/review', Principal::user(1)));
    $check(ob_get_clean() === '', 'The exception boundary wrote raw output.');
    $emitter = new CoreBodyEmitter();
    $request = new WP_REST_Request('POST', '/vidyalms/v1/review');
    $rest = $emitter->respond($request, $response);
    $check($rest->get_headers()['Content-Type'] === 'application/problem+json', 'Problem lost its JSON media type.');
    $check($rest->get_headers()['X-Content-Type-Options'] === 'nosniff', 'MIME sniffing is not disabled.');
    ob_start();
    $check($emitter->serveCoreBody(false, $rest, $request) === true, 'JSON was not served.');
    $body = ob_get_clean();
    remove_filter('rest_pre_serve_request', [$emitter, 'serveCoreBody'], 10);
    $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    $check(!str_contains($body, 'PRIVATE_INTERNAL_'), 'Unexpected exception details leaked.');
    if ($failure instanceof ValidationException) {
        $check($decoded['errors']['title'] === $payload, 'Validation data was corrupted by early HTML escaping.');
    } elseif ($failure instanceof ConflictException) {
        $check($decoded['detail'] === $payload && $decoded['remaining'] === 3, 'Typed error data was changed.');
    } elseif ($failure instanceof DomainException || $failure instanceof ApiProblem) {
        $check(str_contains($decoded['detail'], $payload), 'Public error data was corrupted by early escaping.');
        $expectedStatus = $failure instanceof EntityNotFoundException ? 404
            : ($failure instanceof NotAuthorizedException ? 403 : 400);
        $check($rest->get_status() === $expectedStatus, 'Public error status changed.');
    } else {
        $check($rest->get_status() === 500, 'Unexpected failure was not mapped to a generic 500.');
    }
}

// The same data must be text when a server-rendered form displays it.
$context = ['save_error' => $payload, 'form_action' => 'https://example.invalid/preferences',
    'centre' => \Shondalai\VidyaLMS\WordPress\Content\CoreRouteResult::unavailable()];
ob_start();
require dirname(__DIR__) . '/vidyalms/templates/part-notification-preferences.php';
$html = (string) ob_get_clean();
$check(!str_contains($html, '<script>') && !str_contains($html, '<img src=x'), 'Error text became HTML.');
$check(str_contains($html, esc_html($payload)), 'Displayed error is missing or incorrectly escaped.');
echo "PASS: {$checks} exception/JSON/HTML boundary checks.\n";
