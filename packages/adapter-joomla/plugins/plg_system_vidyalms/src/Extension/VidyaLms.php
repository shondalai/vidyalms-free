<?php
/**
 * @package     VidyaLMS
 * @subpackage  plg_system_vidyalms
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Plugin\System\VidyaLms\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Application\AfterInitialiseEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\User\UserFactoryAwareTrait;
use Joomla\Event\Event;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Ports;

/**
 * The VidyaLMS request bootstrap and the published list of learning events.
 *
 * Three jobs, all small on purpose.
 *
 * 1. Bootstrap. Booting com_vidyalms registers the CoreKernel as a shared
 *    service on the global container. The site app, the API app and every
 *    other plugin then resolve the same kernel instance without each having to
 *    boot the component first.
 *
 * 2. The event surface. The adapter's EventBridge re-dispatches every core
 *    domain event onto the Joomla dispatcher. DOMAIN_EVENTS below is the
 *    complete, authoritative map of Joomla event name to core event name, so
 *    Rewardify, CjForum, EasyCommerce and third-party extensions have one place
 *    to read what they can listen to. Each event carries a "payload" argument:
 *    a JSON-safe associative array, plus "idempotencyKey", which identifies the
 *    occurrence so at-least-once redelivery never produces a second effect.
 *
 *      onVidyaLmsEnrolmentCreated      enrolment_id, user_id, course_id, entitlement_id
 *      onVidyaLmsEnrolmentSuspended    enrolment_id, user_id, course_id
 *      onVidyaLmsEnrolmentResumed      enrolment_id, user_id, course_id
 *      onVidyaLmsEntitlementGranted    entitlement_id, user_id, course_id, source
 *      onVidyaLmsEntitlementRevoked    entitlement_id, user_id, course_id, reason
 *      onVidyaLmsLessonCompleted       enrolment_id, lesson_id, user_id, course_id
 *      onVidyaLmsAttemptSubmitted      attempt_id, quiz_id, lesson_id, enrolment_id,
 *                                      user_id, course_id, percentage, passed,
 *                                      requires_manual_grading
 *      onVidyaLmsAttemptGraded         attempt_id, quiz_id, lesson_id, enrolment_id,
 *                                      user_id, course_id, percentage, passed
 *      onVidyaLmsSubmissionReceived    submission_id, assignment_id, lesson_id,
 *                                      enrolment_id, user_id, course_id
 *      onVidyaLmsSubmissionGraded      submission_id, assignment_id, lesson_id,
 *                                      enrolment_id, user_id, course_id,
 *                                      points_awarded, max_points
 *      onVidyaLmsCourseCompleted       enrolment_id, course_id, user_id
 *      onVidyaLmsCertificateIssued     certificate_id, code, enrolment_id, user_id,
 *                                      course_id
 *      onVidyaLmsCourseCreated         course_id, title, slug, state, actor_id
 *      onVidyaLmsCoursePublished       course_id, title, slug, state, actor_id
 *      onVidyaLmsCourseUpdated         course_id, title, slug, state, changed,
 *                                      actor_id
 *      onVidyaLmsCourseArchived        course_id, title, slug, state, actor_id
 *
 *    The four course events are authoring rather than learning: their actor_id
 *    is whoever wrote the course, and they fire however the course was written,
 *    by the workbench through the contract, by an importer, by a Pro package or
 *    by a CLI script. A listener that mirrors courses somewhere else, a search
 *    index, a sitemap, a cache, listens to these and needs no knowledge of any
 *    route. onVidyaLmsCourseUpdated carries "changed", the list of fields that
 *    actually moved, so a listener that only cares about the slug can ignore
 *    the rest without diffing against its own copy.
 *
 * 3. The host assertion. Joomla has just authenticated somebody, so this is
 *    where the core is told to read what the host asserts about them. See
 *    onUserAfterLogin below: it is one call into the core's own use case, and
 *    the reason it lives in a system plugin rather than in the component is
 *    that a login happens on requests that never boot com_vidyalms.
 *
 * This plugin subscribes to all of them and deliberately does nothing with
 * most: the set of behaviours is empty by default. The one exception is the
 * Joomla action log, which gets an entry for course completions and certificate
 * issuance when com_actionlogs is installed and the option is on. The four
 * course events are deliberately not logged there: a course saved eleven times
 * while it is being written is eleven rows on a timeline nobody learned
 * anything on. Every optional dependency (the kernel, the port bundle,
 * com_actionlogs) is guarded, and no failure here is ever allowed to break the
 * request.
 *
 * @since 1.0.0
 */
final class VidyaLms extends CMSPlugin implements SubscriberInterface
{
    use UserFactoryAwareTrait;

    protected $autoloadLanguage = true;

    /**
     * Joomla event name to core domain event name. The authoritative list.
     */
    private const DOMAIN_EVENTS = [
        'onVidyaLmsEnrolmentCreated'   => 'vidyalms.enrolment.created',
        'onVidyaLmsEnrolmentSuspended' => 'vidyalms.enrolment.suspended',
        'onVidyaLmsEnrolmentResumed'   => 'vidyalms.enrolment.resumed',
        'onVidyaLmsEntitlementGranted' => 'vidyalms.entitlement.granted',
        'onVidyaLmsEntitlementRevoked' => 'vidyalms.entitlement.revoked',
        'onVidyaLmsLessonCompleted'    => 'vidyalms.lesson.completed',
        'onVidyaLmsAttemptSubmitted'   => 'vidyalms.attempt.submitted',
        'onVidyaLmsAttemptGraded'      => 'vidyalms.attempt.graded',
        'onVidyaLmsSubmissionReceived' => 'vidyalms.submission.received',
        'onVidyaLmsSubmissionGraded'   => 'vidyalms.submission.graded',
        'onVidyaLmsCourseCompleted'    => 'vidyalms.course.completed',
        'onVidyaLmsCertificateIssued'  => 'vidyalms.certificate.issued',
        'onVidyaLmsCourseCreated'      => 'vidyalms.course.created',
        'onVidyaLmsCoursePublished'    => 'vidyalms.course.published',
        'onVidyaLmsCourseUpdated'      => 'vidyalms.course.updated',
        'onVidyaLmsCourseArchived'     => 'vidyalms.course.archived',
        // Archiving a lesson or a module can complete the course for a whole
        // cohort, which then fires CourseCompleted and CertificateIssued for
        // each of them minutes later. Without the cause, a search index, a
        // cache or a drip schedule sees that storm and nothing to attribute
        // it to.
        'onVidyaLmsLessonArchived'     => 'vidyalms.lesson.archived',
        'onVidyaLmsModuleArchived'     => 'vidyalms.module.archived',
    ];

    private const ACTIONLOG_CONTEXT_COURSE = 'plg_system_vidyalms.course';

    private const ACTIONLOG_CONTEXT_CERTIFICATE = 'plg_system_vidyalms.certificate';

    public static function getSubscribedEvents(): array
    {
        $events = [
            'onAfterInitialise' => 'onAfterInitialise',
            'onUserAfterLogin'  => 'onUserAfterLogin',
        ];

        foreach (array_keys(self::DOMAIN_EVENTS) as $joomlaEvent) {
            $events[$joomlaEvent] = 'onVidyaLmsDomainEvent';
        }

        return $events;
    }

    /**
     * The moment the free identity clause was missing.
     *
     * Both adapters shipped an AssertedIdentityPort, the core shipped the use
     * case that reads it, and nothing anywhere called that use case after
     * authenticating anybody. A free site therefore held every part of the
     * identity path and never once executed it. This is the call.
     *
     * Read Ports::$assertedIdentity and never a request attribute. The port is
     * asked here by way of the use case, which hands it a Principal built from
     * the account Joomla has just logged in; the event's own arguments carry an
     * authentication response that came off a login form, and reading a group,
     * an organisation or an issuer out of that would be trusting the request
     * for the thing the whole ceremony exists to establish.
     *
     * Never fatal, and never noisy. ApplyHostAssertion answers a
     * ProvisioningOutcome for every ordinary result including "this host has
     * nothing to assert", which is what a stock site with no verified
     * connection answers on every single login. A throw here would turn a
     * misconfigured connection into a white screen at the login form, so the
     * only thing that reaches the log is an unexpected exception.
     */
    public function onUserAfterLogin(EventInterface $event): void
    {
        $userId = $this->loggedInUserId($event);

        if ($userId < 1) {
            return;
        }

        try {
            $kernel = Factory::getContainer()->get(CoreKernel::class);

            if (!$kernel instanceof CoreKernel) {
                return;
            }

            $kernel->recordLogin->execute(Principal::user($userId));
            $kernel->applyHostAssertion->execute(Principal::user($userId));
        } catch (\Throwable) {
            // A directory that cannot be read is not a failed login.
        }
    }

    /**
     * The account Joomla has just authenticated.
     *
     * Joomla puts the User object on the event's options under "user" before it
     * dispatches, which is the authenticated identity rather than anything the
     * browser sent. The session identity is the fallback for a host that
     * dispatched the event without it, and a guest identity answers zero, which
     * the caller reads as nothing to do.
     */
    private function loggedInUserId(EventInterface $event): int
    {
        $options = $event->getArgument('options');

        if (\is_array($options) && isset($options['user']) && \is_object($options['user'])) {
            $id = $options['user']->id ?? 0;

            if (\is_int($id) || \is_string($id)) {
                return (int) $id;
            }
        }

        try {
            return (int) ($this->getApplication()->getIdentity()->id ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Register the learning engine on the global container before anything asks
     * for it. The bootstrap must never break the request, so every failure is
     * swallowed: consumers already guard on the kernel being resolvable.
     */
    public function onAfterInitialise(AfterInitialiseEvent $event): void
    {
        try {
            $this->getApplication()->bootComponent('com_vidyalms');
        } catch (\Throwable) {
            // Deliberately inert.
        }
    }

    /**
     * The single entry point for every re-dispatched core domain event. Adding
     * a behaviour means adding a case here, never re-implementing a rule: the
     * core has already decided and persisted everything by the time the event
     * arrives.
     */
    public function onVidyaLmsDomainEvent(EventInterface $event): void
    {
        $coreName = $this->coreEventName((string) $event->getName());

        if ($coreName === null) {
            return;
        }

        try {
            $payload = $this->payloadOf($event);

            match ($coreName) {
                'vidyalms.course.completed'   => $this->logCourseCompleted($payload),
                'vidyalms.certificate.issued' => $this->logCertificateIssued($payload),
                default                       => null,
            };
        } catch (\Throwable) {
            // A reporting side effect must never fail the action that caused it.
        }
    }

    // Action log

    /**
     * @param array<string, mixed> $payload
     */
    private function logCourseCompleted(array $payload): void
    {
        if (!$this->params->get('actionlog', 1)) {
            return;
        }

        $userId   = (int) ($payload['user_id'] ?? 0);
        $courseId = (int) ($payload['course_id'] ?? 0);

        if ($userId <= 0 || $courseId <= 0) {
            return;
        }

        $this->addActionLog(
            [
                'action'   => 'complete',
                'id'       => $courseId,
                'title'    => $this->courseTitle($courseId),
                'userid'   => $userId,
                'username' => $this->userName($userId),
            ],
            'PLG_SYSTEM_VIDYALMS_ACTIONLOG_COURSE_COMPLETED',
            self::ACTIONLOG_CONTEXT_COURSE,
            $userId
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function logCertificateIssued(array $payload): void
    {
        if (!$this->params->get('actionlog', 1)) {
            return;
        }

        $userId   = (int) ($payload['user_id'] ?? 0);
        $courseId = (int) ($payload['course_id'] ?? 0);

        if ($userId <= 0) {
            return;
        }

        $this->addActionLog(
            [
                'action'   => 'issue',
                'id'       => (int) ($payload['certificate_id'] ?? 0),
                'code'     => (string) ($payload['code'] ?? ''),
                'title'    => $this->courseTitle($courseId),
                'userid'   => $userId,
                'username' => $this->userName($userId),
            ],
            'PLG_SYSTEM_VIDYALMS_ACTIONLOG_CERTIFICATE_ISSUED',
            self::ACTIONLOG_CONTEXT_CERTIFICATE,
            $userId
        );
    }

    /**
     * @param array<string, mixed> $message
     */
    private function addActionLog(array $message, string $languageKey, string $context, int $userId): void
    {
        if (!ComponentHelper::isEnabled('com_actionlogs')) {
            return;
        }

        $component = $this->getApplication()->bootComponent('com_actionlogs');

        if (!method_exists($component, 'getMVCFactory')) {
            return;
        }

        $model = $component->getMVCFactory()->createModel('Actionlog', 'Administrator');

        if ($model === null || !method_exists($model, 'addLog')) {
            return;
        }

        $model->addLog([$message], $languageKey, $context, $userId);
    }

    // Lookups

    /**
     * Course titles are read, never written, and only through the core's
     * database port: the adapter is not a writer of any learning table.
     */
    private function courseTitle(int $courseId): string
    {
        if ($courseId <= 0 || !$this->params->get('actionlog_titles', 1)) {
            return '';
        }

        $ports = $this->ports();

        if ($ports === null) {
            return '';
        }

        try {
            $row = $ports->database->selectOne(
                Query::from('courses')->select(['title'])->where('id', '=', $courseId)
            );
        } catch (\Throwable) {
            return '';
        }

        return \is_array($row) && isset($row['title']) ? (string) $row['title'] : '';
    }

    private function userName(int $userId): string
    {
        try {
            return (string) $this->getUserFactory()->loadUserById($userId)->name;
        } catch (\Throwable) {
            return '';
        }
    }

    private function ports(): ?Ports
    {
        try {
            $ports = Factory::getContainer()->get(Ports::class);
        } catch (\Throwable) {
            return null;
        }

        return $ports instanceof Ports ? $ports : null;
    }

    // Event plumbing

    private function coreEventName(string $joomlaEvent): ?string
    {
        foreach (self::DOMAIN_EVENTS as $name => $coreName) {
            if (strcasecmp($name, $joomlaEvent) === 0) {
                return $coreName;
            }
        }

        return null;
    }

    /**
     * The bridge carries the JSON-safe payload as an argument. A bridge that
     * hands over the domain event object itself is accepted too, so listeners
     * keep working whichever shape the adapter dispatches.
     *
     * @return array<string, mixed>
     */
    private function payloadOf(EventInterface $event): array
    {
        $payload = $event->getArgument('payload');

        if (\is_array($payload)) {
            return $payload;
        }

        foreach (['event', 'subject'] as $argument) {
            $candidate = $event->getArgument($argument);

            if (\is_object($candidate) && method_exists($candidate, 'payload')) {
                $shape = $candidate->payload();

                if (\is_array($shape)) {
                    return $shape;
                }
            }
        }

        return [];
    }
}
