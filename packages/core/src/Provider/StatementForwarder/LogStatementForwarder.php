<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\StatementForwarder;

use Psr\Log\LoggerInterface;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Reporting\XapiStatement;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\StatementForwarder\StatementForwardContext;
use Shondalai\VidyaLMS\Core\Registry\StatementForwarder\StatementForwarderInterface;

/**
 * Writes the built statement to the logger and forwards it nowhere.
 *
 * It is the free provider that makes this seam reachable, and it earns its
 * place: a site owner deciding whether to buy an LRS forwarder can see exactly
 * what would be sent before spending anything, which is a question nothing else
 * in the product answers.
 *
 * It logs at debug, because a busy site emits one of these per graded answer
 * and a forwarder that filled the error log would be turned off by the first
 * administrator who noticed.
 */
final class LogStatementForwarder implements StatementForwarderInterface
{
    public const KEY = 'log';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly TranslatorPort $translator,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.statement_forwarder.log');
    }

    public function eventNames(): array
    {
        return [];
    }

    public function forward(XapiStatement $statement, StatementForwardContext $context): void
    {
        $this->logger->debug('vidyalms.xapi.forward', [
            'statement_id' => $statement->id,
            'verb' => $statement->verb,
            // The moment the fact happened, never the fan-out time. It is the
            // one field a redelivery must not move, and it is the field a
            // forwarder written in a hurry stamps with now().
            'timestamp' => $statement->occurredAt->format(DATE_ATOM),
            'attempt_id' => $context->attemptId,
            'course_id' => $context->courseId,
            'user_id' => $context->userId,
            'statement' => $statement->toArray(),
        ]);
    }
}
