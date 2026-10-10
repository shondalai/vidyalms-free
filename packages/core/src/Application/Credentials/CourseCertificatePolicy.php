<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Expiry\DeclaresPayloadShape;
use Shondalai\VidyaLMS\Core\Registry\Expiry\ExpiryPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/** One executable course policy, shared by both hosts and every issue path. */
final class CourseCertificatePolicy
{
    private const TABLE = 'course_certificate_policies';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly CourseAccess $access,
        private readonly CertificateTemplateRepository $templates,
        private readonly CertificateStudio $studio,
        private readonly EnsureDefaultTemplate $default,
        private readonly CertificateExpiryRule $expiryRule,
        private readonly ExpiryPolicyRegistry $expiryPolicies,
        private readonly ClockPort $clock,
    ) {
    }

    public function authorize(Principal $principal, int $courseId): void
    {
        $this->access->require($principal, $courseId, Capability::TEACH);
    }

    /** Fresh post-rollback check for the unique absent-row creation race. */
    public function hasChanged(int $courseId, int $expectedRevision): bool
    {
        return $this->read($courseId)['revision'] !== $expectedRevision;
    }

    /** @return array<string, mixed> */
    public function get(Principal $principal, int $courseId): array
    {
        $this->authorize($principal, $courseId);
        $policy = $this->read($courseId);
        $template = $policy['template_id'] === null
            ? $this->templates->findPublishedDefault()
            : $this->templates->findById((int) $policy['template_id']);
        $summary = $this->studio->publishedSummary($template);
        $policy['resolved_template'] = [
            'id' => $summary['id'],
            'name' => $summary['name'],
            'published_revision' => $summary['published_revision'],
            'source' => $policy['template_id'] === null ? 'site_default' : 'course',
            'available' => $summary['available'],
            'diagnostic' => $summary['diagnostic'],
        ];
        $policy['capabilities'] = [
            'issue_rules' => ['course_completed'],
            'co_branding_modes' => ['site'],
            'expiry_modes' => array_merge(['inherit_template'], $this->expiryPolicies->keys()),
        ];

        return $policy;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function put(Principal $principal, int $courseId, array $body): array
    {
        $this->authorize($principal, $courseId);
        $fields = new CertificateTemplateFields($body);
        $expected = $fields->optionalInt('expected_revision', 0);
        $enabled = $fields->optionalBool('enabled');
        $templateId = $fields->nullableInt('template_id', 1);
        if ($expected === null || $expected > CertificateTemplateFields::MAX_EXPECTED_REVISION) {
            $fields->reject('expected_revision', 'A policy revision from 0 to 2147483646 is required.');
        }
        if ($enabled === null || !$fields->has('template_id')) {
            $fields->reject('enabled', 'Provide the complete policy, including enabled and template_id.');
        }
        if (($body['issue_rule'] ?? null) !== 'course_completed') {
            $fields->reject('issue_rule', 'Only course_completed is executable.');
        }
        if (($body['co_branding'] ?? null) !== 'site') {
            $fields->reject('co_branding', 'Only site branding is available.');
        }
        $expiry = $body['expiry'] ?? null;
        if (!is_array($expiry) || !is_string($expiry['mode'] ?? null)) {
            $fields->reject('expiry', 'Provide an expiry object with a mode.');
            $expiry = ['mode' => 'inherit_template'];
        }
        $fields->check();
        $expiry = $this->validateExpiry($expiry);
        $this->database->transactional(function () use ($principal, $courseId, $expected, $enabled, $templateId, $expiry): void {
            $current = $this->read($courseId);
            if ($current['revision'] !== $expected) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ConflictException::with('This course certificate policy has changed.', ['expected_revision' => $expected]);
            }
            if ($templateId !== null) {
                $this->studio->publishedForIssuance($templateId);
            }
            $now = $this->clock->now()->format('Y-m-d H:i:s');
            $data = [
                'revision' => $expected + 1,
                'enabled' => $enabled ? 1 : 0,
                'template_id' => $templateId,
                'issue_rule' => 'course_completed',
                // Stamped with the policy's own shape where it declares one,
                // so a provider that moves its settings can tell its old rows
                // apart. The version travels inside the stored JSON rather
                // than in a new column, on the same terms the compliance
                // seam's rule state does, and the read strips it again.
                'expiry' => json_encode($this->stamped($expiry), JSON_THROW_ON_ERROR),
                'co_branding' => 'site',
                'updated_by' => $principal->userId,
                'updated_at' => $now,
            ];
            if ($expected === 0) {
                try {
                    $this->database->insert(self::TABLE, $data + ['course_id' => $courseId, 'created_at' => $now]);
                } catch (\Throwable $failure) {
                    // A unique course key is the absent-row CAS. Only an
                    // observed competing policy is translated to a conflict.
                    if ($this->database->selectOne(Query::from(self::TABLE)->where('course_id', '=', $courseId)) !== null) {
                        throw new ConflictException('A course certificate policy was created concurrently.');
                    }
                    throw $failure;
                }
            } elseif ($this->database->update(self::TABLE, $data, ['course_id' => $courseId, 'revision' => $expected]) !== 1) {
                throw new ConflictException('This course certificate policy changed during the save.');
            }
        });

        return $this->get($principal, $courseId);
    }

    /** @return array<string, mixed> */
    public function picker(Principal $principal, int $courseId, string $search, int $page, int $perPage): array
    {
        $this->authorize($principal, $courseId);
        if ($page < 1 || $perPage < 1 || $perPage > 50 || mb_strlen($search) > 100) {
            throw ValidationException::forField('page', 'Use a positive page, 1 to 50 rows, and at most 100 search characters.');
        }
        $query = Query::from('certificate_templates')->where('state', '=', 'published');
        if ($search !== '') {
            $query->whereAnyOf(
                Predicate::contains('published_name', $search),
                Predicate::allOf(Predicate::isNull('published_name'), Predicate::contains('name', $search)),
            );
        }
        $total = $this->database->count(clone $query);
        $rows = $this->database->selectAll($query->select([
            'id', 'name', 'published_summary', 'published_revision', 'revision', 'renderer', 'format', 'is_default', 'state',
        ])
            ->orderBy('published_name')->orderBy('id')->limit($perPage)->offset(($page - 1) * $perPage));
        $items = array_map($this->studio->publishedSummary(...), $rows);

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * Null is an explicit disabled outcome. A missing assigned design throws;
     * it never silently substitutes the site default.
     * @return array<string, mixed>|null
     */
    public function resolve(int $courseId): ?array
    {
        $policy = $this->read($courseId);
        if (!$policy['enabled']) {
            return null;
        }
        $id = $policy['template_id'];
        if ($id === null) {
            $row = $this->templates->findPublishedDefault();
            $id = $row === null ? $this->default->execute() : (int) $row['id'];
        }
        $template = $this->studio->publishedForIssuance((int) $id);
        $expiry = $policy['expiry'];
        if (is_array($expiry) && ($expiry['mode'] ?? 'inherit_template') !== 'inherit_template') {
            $expiry = $this->validateExpiry($expiry);
            $template['expiry_mode'] = $expiry['mode'];
            $template['expiry_days'] = $expiry['days'];
            $template['expiry_at'] = $expiry['at'];
        }
        $template['course_policy'] = $policy;

        return $template;
    }

    /** @return array<string, mixed> */
    private function read(int $courseId): array
    {
        $row = $this->database->selectOne(Query::from(self::TABLE)->where('course_id', '=', $courseId));
        $expiry = $row === null ? ['mode' => 'inherit_template', 'days' => null, 'at' => null]
            : StoredShape::bodyOf((array) json_decode((string) $row['expiry'], true, 512, JSON_THROW_ON_ERROR));

        return [
            'course_id' => $courseId, 'revision' => $row === null ? 0 : (int) $row['revision'],
            'enabled' => $row === null || (int) $row['enabled'] === 1,
            'template_id' => isset($row['template_id']) ? (int) $row['template_id'] : null,
            'issue_rule' => $row['issue_rule'] ?? 'course_completed',
            'expiry' => $expiry, 'co_branding' => $row['co_branding'] ?? 'site',
        ];
    }

    /**
     * @param array<string, mixed> $expiry
     * @return array{mode: string, days: ?int, at: ?string}
     */
    /**
     * The stored bag with the policy's own shape number in it, where the
     * policy declares one.
     *
     * A provider that does not declare {@see DeclaresPayloadShape} writes no
     * version key at all, which reads back as unversioned. That is the
     * truthful answer about a bag whose author never said what shape it is,
     * and it is better than a `1` this code would be inventing.
     *
     * @param array<string, mixed> $expiry
     *
     * @return array<string, mixed>
     */
    private function stamped(array $expiry): array
    {
        $mode = \is_string($expiry['mode'] ?? null) ? $expiry['mode'] : '';
        $provider = $this->expiryPolicies->has($mode) ? $this->expiryPolicies->get($mode) : null;

        return $provider instanceof DeclaresPayloadShape
            ? StoredShape::wrap($expiry, $provider->currentShape())
            : $expiry;
    }

    /**
     * @param array<string, mixed> $expiry
     *
     * @return array{mode: string, days: ?int, at: ?string}
     */
    private function validateExpiry(array $expiry): array
    {
        $mode = $expiry['mode'] ?? null;
        if ($mode === 'inherit_template') {
            return ['mode' => 'inherit_template', 'days' => null, 'at' => null];
        }
        $fields = new CertificateTemplateFields([
            'expiry_mode' => $mode, 'expiry_days' => $expiry['days'] ?? null, 'expiry_at' => $expiry['at'] ?? null,
        ]);
        $changes = $this->expiryRule->changesFor($fields);
        $fields->check();

        return ['mode' => (string) $changes['expiry_mode'],
            'days' => isset($changes['expiry_days']) ? (int) $changes['expiry_days'] : null,
            'at' => isset($changes['expiry_at']) ? (string) $changes['expiry_at'] : null];
    }
}
