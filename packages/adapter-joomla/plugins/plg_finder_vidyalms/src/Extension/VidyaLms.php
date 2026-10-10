<?php
/**
 * @package     VidyaLMS
 * @subpackage  plg_finder_vidyalms
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Plugin\Finder\VidyaLms\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Finder as FinderEvent;
use Joomla\Component\Finder\Administrator\Indexer\Adapter;
use Joomla\Component\Finder\Administrator\Indexer\Helper;
use Joomla\Component\Finder\Administrator\Indexer\Indexer;
use Joomla\Component\Finder\Administrator\Indexer\Result;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\QueryInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Event\EventInterface;
use Joomla\Registry\Registry;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory;

/**
 * Smart Search adapter for VidyaLMS courses.
 *
 * Only courses a visitor could actually reach are searchable. Two gates decide
 * that, and they behave differently on purpose:
 *
 *   visibility  a course that is not "public" is removed from the index
 *               outright. Its summary and description are the very text the
 *               index stores, so leaving a private course at a hidden state
 *               would keep that text on disk.
 *   state       "published" is indexed live and "archived" follows the
 *               Search archived option, matching how Joomla treats archived
 *               content everywhere else. A draft is removed from the index.
 *
 * Courses live in the core's own tables and carry string states, so the base
 * adapter's integer state juggling does not apply: a state change reindexes the
 * row, which re-reads the truth rather than guessing at it.
 *
 * Course categories are a plain integer on the course record, not necessarily a
 * node in the Joomla category tree, so no category taxonomy or category state
 * inheritance is claimed here.
 *
 * @since 1.0.0
 */
final class VidyaLms extends Adapter implements SubscriberInterface
{
    use DatabaseAwareTrait;

    /**
     * The plugin identifier.
     *
     * @var string
     */
    protected $context = 'Vidyalms';

    /**
     * The extension name.
     *
     * @var string
     */
    protected $extension = 'com_vidyalms';

    /**
     * The sublayout to use when rendering the results.
     *
     * @var string
     */
    protected $layout = 'course';

    /**
     * The type of content that the adapter indexes.
     *
     * @var string
     */
    protected $type_title = 'VidyaLMS Course';

    /**
     * The table name.
     *
     * @var string
     */
    protected $table = '#__vidyalms_courses';

    protected $autoloadLanguage = true;

    private const CONTEXT_COURSE = 'com_vidyalms.course';

    private const CONTEXT_FORM = 'com_vidyalms.form';

    private const STATE_PUBLISHED = 'published';

    private const STATE_ARCHIVED = 'archived';

    private const VISIBILITY_PUBLIC = 'public';

    public static function getSubscribedEvents(): array
    {
        return array_merge(parent::getSubscribedEvents(), [
            'onFinderChangeState' => 'onFinderChangeState',
            'onFinderAfterDelete' => 'onFinderAfterDelete',
            'onFinderAfterSave'   => 'onFinderAfterSave',
            'onVidyaLmsCourseCreated' => 'onCourseChanged',
            'onVidyaLmsCoursePublished' => 'onCourseChanged',
            'onVidyaLmsCourseUpdated' => 'onCourseChanged',
            'onVidyaLmsCourseArchived' => 'onCourseChanged',
            'onVidyaLmsCourseIndexChanged' => 'onCourseChanged',
        ]);
    }

    protected function setup()
    {
        return true;
    }

    // Events

    /** Core events are emitted after persistence; never trust their title or visibility. */
    public function onCourseChanged(EventInterface $event): void
    {
        $payload = $event->getArgument('payload');
        $id = is_array($payload) ? (int) ($payload['course_id'] ?? 0) : 0;
        if ($id > 0) {
            $this->refreshCourse($id);
        }
    }

    private function refreshCourse(int $id): void
    {
        // A failed read/reindex must not leave previously public text searchable.
        $this->remove((string) $id);
        $this->reindex($id);
    }

    /**
     * Reindex a saved course. The row is re-read, so a save that changed state
     * or visibility lands correctly without this method knowing what changed.
     */
    public function onFinderAfterSave(FinderEvent\AfterSaveEvent $event): void
    {
        $context = $event->getContext();

        if ($context !== self::CONTEXT_COURSE && $context !== self::CONTEXT_FORM) {
            return;
        }

        $row = $event->getItem();

        if (empty($row->id)) {
            return;
        }

        $this->refreshCourse((int) $row->id);
    }

    /**
     * Drop the link when a course, or the finder entry itself, is deleted.
     */
    public function onFinderAfterDelete(FinderEvent\AfterDeleteEvent $event): void
    {
        $context = $event->getContext();
        $table   = $event->getItem();

        if ($context === self::CONTEXT_COURSE) {
            $id = (int) $table->id;
        } elseif ($context === 'com_finder.index') {
            $id = (int) $table->link_id;
        } else {
            return;
        }

        $this->remove((string) $id);
    }

    /**
     * A state change is answered by a reindex rather than by patching the
     * stored state: course states are strings ("draft", "published",
     * "archived") and the incoming value cannot be translated without reading
     * the row anyway. index() then removes drafts and non-public courses.
     */
    public function onFinderChangeState(FinderEvent\AfterChangeStateEvent $event): void
    {
        $context = $event->getContext();
        $pks     = $event->getPks();
        $value   = $event->getValue();

        if ($context === self::CONTEXT_COURSE || $context === self::CONTEXT_FORM) {
            foreach ((array) $pks as $pk) {
                $this->refreshCourse((int) $pk);
            }
        }

        if ($context === 'com_plugins.plugin' && $value === 0) {
            $this->pluginDisable($pks);
        }
    }

    // Indexing

    /**
     * @param Result $item The item to index as a Result object.
     *
     * @return bool
     */
    protected function index(Result $item)
    {
        if (empty($item->id)) {
            return false;
        }

        if (ComponentHelper::isEnabled($this->extension) === false) {
            return false;
        }

        $id          = (int) $item->id;
        $visibility  = strtolower(trim((string) ($item->visibility ?? '')));
        $courseState = strtolower(trim((string) ($item->course_state ?? '')));

        if ($visibility !== self::VISIBILITY_PUBLIC) {
            $this->remove((string) $id);

            return false;
        }

        if ($courseState !== self::STATE_PUBLISHED && $courseState !== self::STATE_ARCHIVED) {
            $this->remove((string) $id);

            return false;
        }

        // The third gate, and it is the core's answer rather than this
        // plugin's reading of it. A course whose own restriction is authored
        // hidden is absent from the index rather than present and unreachable:
        // the summary and the description are the very text the index stores,
        // and the title itself is the leak. Enforcement written only in the
        // player is not enforcement, which is why the design names the search
        // indexer alongside the use cases.
        if (!$this->titleIsPublishable($id)) {
            $this->remove((string) $id);

            return false;
        }

        $item->language = trim((string) ($item->language ?? '')) !== '' ? (string) $item->language : '*';
        $item->setLanguage();

        $item->context  = self::CONTEXT_COURSE;
        $item->params   = clone ComponentHelper::getParams($this->extension, true);
        $item->metadata = new Registry();

        $item->summary = Helper::prepareContent((string) ($item->summary ?? ''), $item->params, $item);
        $item->body    = Helper::prepareContent((string) ($item->body ?? ''), $item->params, $item);

        $item->url   = $this->getUrl($id, $this->extension, $this->layout);
        $item->route = $this->courseRoute($id, (string) ($item->slug ?? ''));

        $image = (string) ($item->image ?? '');

        if ($image !== '') {
            $item->imageUrl = $image;
            $item->imageAlt = (string) ($item->title ?? '');
        }

        $item->state  = $this->translateState($courseState === self::STATE_PUBLISHED ? 1 : 2);
        $item->access = $this->defaultAccess();

        $item->addInstruction(Indexer::META_CONTEXT, 'level');

        $taxonomies = (array) $this->params->get('taxonomies', ['type', 'level', 'language']);

        if (\in_array('type', $taxonomies, true)) {
            $item->addTaxonomy('Type', 'VidyaLMS Course');
        }

        $level = (string) ($item->level ?? '');

        if ($level !== '' && \in_array('level', $taxonomies, true)) {
            $item->addTaxonomy('Level', $level, $item->state, $item->access, $item->language);
        }

        if (\in_array('language', $taxonomies, true)) {
            $item->addTaxonomy('Language', $item->language);
        }

        Helper::getContentExtras($item);

        $this->indexer->index($item);

        return true;
    }

    // Queries

    /**
     * @param mixed $query A QueryInterface object or null.
     *
     * @return QueryInterface
     */
    protected function getListQuery($query = null)
    {
        $db = $this->getDatabase();

        // com_finder hands in a query carrying only a WHERE clause when it
        // rebuilds a subset, so the columns and the FROM are always appended
        // rather than returned from a fresh query.
        $query = $query instanceof QueryInterface ? $query : $db->createQuery();

        return $query
            ->select(
                $db->quoteName(
                    [
                        'a.id',
                        'a.title',
                        'a.slug',
                        'a.summary',
                        'a.description',
                        'a.image',
                        'a.level',
                        'a.language',
                        'a.state',
                        'a.visibility',
                        'a.featured',
                        'a.category_id',
                        'a.created_by',
                        'a.published_at',
                        'a.created_at',
                        'a.updated_at',
                    ],
                    [
                        'id',
                        'title',
                        'slug',
                        'summary',
                        'body',
                        'image',
                        'level',
                        'language',
                        'course_state',
                        'visibility',
                        'featured',
                        'category_id',
                        'created_by',
                        'start_date',
                        'created_at',
                        'modified',
                    ]
                )
            )
            ->from($db->quoteName($this->table, 'a'));
    }

    /**
     * The base implementation joins #__categories on a "catid" column that
     * VidyaLMS courses do not have, so state lookups read the course row alone.
     *
     * @return QueryInterface
     */
    protected function getStateQuery()
    {
        $db = $this->getDatabase();

        return $db->createQuery()
            ->select($db->quoteName('a.id'))
            ->select($db->quoteName('a.state', 'course_state'))
            ->select($db->quoteName('a.visibility'))
            ->from($db->quoteName($this->table, 'a'));
    }

    /**
     * @param string $time The modified timestamp.
     *
     * @return QueryInterface
     */
    protected function getUpdateQueryByTime($time)
    {
        $db = $this->getDatabase();

        return $db->createQuery()
            ->where($db->quoteName('a.updated_at') . ' >= ' . $db->quote($time));
    }

    // Helpers

    /**
     * Whether the core says this course's title may be published at all.
     *
     * The question is asked of the engine rather than answered here, so this
     * plugin, the WordPress outline and a future third adapter cannot
     * disagree about what a hidden restriction means. A site whose kernel
     * cannot be built must not publish text whose visibility it cannot check.
     *
     * @since 1.0.0
     */
    private function titleIsPublishable(int $courseId): bool
    {
        try {
            return KernelFactory::kernel()->availability->titleIsPublishable(
                new GateSubject(GateSubject::TYPE_COURSE, $courseId),
            );
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The non-SEF course link. com_vidyalms's router turns it into the site's
     * SEF path; CourseModel resolves the slug query parameter.
     */
    private function courseRoute(int $id, string $slug): string
    {
        return 'index.php?option=' . $this->extension . '&view=' . $this->layout . '&slug=' . rawurlencode($slug);
    }

    /**
     * Courses carry no access column, so a public course takes the site's
     * default view level.
     */
    private function defaultAccess(): int
    {
        $app = $this->getApplication();

        return max(1, (int) $app->get('access', 1));
    }
}
