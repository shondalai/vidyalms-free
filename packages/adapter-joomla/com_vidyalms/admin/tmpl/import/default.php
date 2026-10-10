<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use VidyaLMS\Component\VidyaLMS\Administrator\Helper\VidyaLmsHelper;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\ImportSources;
use VidyaLMS\Component\VidyaLMS\Administrator\View\Import\HtmlView;

/** @var \VidyaLMS\Component\VidyaLMS\Administrator\View\Import\HtmlView $this */

$hasImporters = $this->importers !== [];
$pageUrl      = 'index.php?option=com_vidyalms&view=import';
?>
<div class="row">
    <div class="col-md-12">
        <div id="j-main-container" class="j-main-container">

            <?php if (!$this->authorised) : ?>
                <div class="alert alert-warning">
                    <span class="icon-exclamation-triangle" aria-hidden="true"></span>
                    <span class="visually-hidden"><?php echo Text::_('WARNING'); ?></span>
                    <?php echo Text::_('COM_VIDYALMS_IMPORT_ERROR_NO_MANAGE'); ?>
                </div>
            <?php else : ?>

                <?php foreach ($this->queueWarnings as $warning) : ?>
                    <div class="alert alert-warning">
                        <span class="icon-exclamation-triangle" aria-hidden="true"></span>
                        <span class="visually-hidden"><?php echo Text::_('WARNING'); ?></span>
                        <?php echo Text::_($warning); ?>
                    </div>
                <?php endforeach; ?>

                <?php if (!$hasImporters) : ?>
                    <div class="alert alert-info">
                        <span class="icon-info-circle" aria-hidden="true"></span>
                        <span class="visually-hidden"><?php echo Text::_('INFO'); ?></span>
                        <?php echo Text::_('COM_VIDYALMS_IMPORT_NO_IMPORTERS'); ?>
                    </div>
                <?php else : ?>
                    <form action="<?php echo Route::_('index.php?option=com_vidyalms'); ?>"
                          method="post" name="adminForm" id="vidyalms-import-form"
                          enctype="multipart/form-data" class="mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h2 class="card-title h4 mb-0"><?php echo Text::_('COM_VIDYALMS_IMPORT_START_HEADING'); ?></h2>
                            </div>
                            <div class="card-body">
                                <p class="text-muted">
                                    <?php echo Text::_('COM_VIDYALMS_IMPORT_START_INTRO'); ?>
                                </p>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label" for="vidyalms-importer">
                                            <?php echo Text::_('COM_VIDYALMS_IMPORT_FIELD_IMPORTER'); ?>
                                        </label>
                                        <select class="form-select" name="importer" id="vidyalms-importer" required>
                                            <?php foreach ($this->importers as $importer) : ?>
                                                <option value="<?php echo $this->escape($importer['key']); ?>"
                                                        data-extensions="<?php echo $this->escape(implode(',', $importer['extensions'])); ?>"
                                                        data-file="<?php echo $importer['accepts_file'] ? '1' : '0'; ?>"
                                                        data-database="<?php echo $importer['accepts_database'] ? '1' : '0'; ?>">
                                                    <?php echo $this->escape($importer['label']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text" id="vidyalms-importer-hint"></div>
                                    </div>

                                    <div class="col-md-6 mb-3">
                                        <span class="form-label d-block"><?php echo Text::_('COM_VIDYALMS_IMPORT_FIELD_SOURCE'); ?></span>
                                        <div class="form-check" id="vidyalms-source-file-choice">
                                            <input class="form-check-input" type="radio" name="source_mode"
                                                   id="vidyalms-source-file" value="file" checked>
                                            <label class="form-check-label" for="vidyalms-source-file">
                                                <?php echo Text::_('COM_VIDYALMS_IMPORT_SOURCE_FILE'); ?>
                                            </label>
                                        </div>
                                        <div class="form-check" id="vidyalms-source-database-choice">
                                            <input class="form-check-input" type="radio" name="source_mode"
                                                   id="vidyalms-source-database" value="database">
                                            <label class="form-check-label" for="vidyalms-source-database">
                                                <?php echo Text::_('COM_VIDYALMS_IMPORT_SOURCE_DATABASE'); ?>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <div class="row" id="vidyalms-file-row">
                                    <div class="col-md-8 mb-3">
                                        <label class="form-label" for="vidyalms-import-file">
                                            <?php echo Text::_('COM_VIDYALMS_IMPORT_FIELD_FILE'); ?>
                                        </label>
                                        <input class="form-control" type="file" name="import_file" id="vidyalms-import-file">
                                        <div class="form-text">
                                            <?php echo Text::sprintf('COM_VIDYALMS_IMPORT_FIELD_FILE_DESC', $this->escape($this->maxUploadLabel)); ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="row d-none" id="vidyalms-selector-row">
                                    <div class="col-md-8 mb-3">
                                        <label class="form-label" for="vidyalms-selector">
                                            <?php echo Text::_('COM_VIDYALMS_IMPORT_FIELD_SELECTOR'); ?>
                                        </label>
                                        <input class="form-control" type="text" name="selector" id="vidyalms-selector"
                                               value="" placeholder="<?php echo $this->escape('categories=3,7'); ?>">
                                        <div class="form-text">
                                            <?php echo Text::sprintf('COM_VIDYALMS_IMPORT_FIELD_SELECTOR_DESC', ImportSources::DATABASE_SOURCE); ?>
                                        </div>
                                    </div>
                                </div>

                                <?php // One fieldset per importer that declares options, and every ?>
                                <?php // control in a hidden one is disabled so a browser cannot post ?>
                                <?php // one importer's options with another importer's run. With ?>
                                <?php // scripting off the first importer is the selected one, and its ?>
                                <?php // fieldset is the enabled one, so the screen still works. ?>
                                <?php foreach ($this->importers as $index => $importer) : ?>
                                    <?php if ($importer['options'] !== []) : ?>
                                        <?php $active = $index === 0; ?>
                                        <fieldset class="row<?php echo $active ? '' : ' d-none'; ?>"
                                                  data-vidyalms-options="<?php echo $this->escape($importer['key']); ?>"
                                                  <?php echo $active ? '' : 'disabled'; ?>>
                                            <legend class="col-12 h6"><?php echo Text::_('COM_VIDYALMS_IMPORT_OPTIONS_HEADING'); ?></legend>
                                            <?php foreach ($importer['options'] as $option) : ?>
                                                <?php $id = 'vidyalms-option-' . $this->escape($importer['key'] . '-' . $option['key']); ?>
                                                <div class="col-md-8 mb-3">
                                                    <?php if ($option['kind'] === 'boolean') : ?>
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" value="1"
                                                                   id="<?php echo $id; ?>"
                                                                   name="options[<?php echo $this->escape($option['key']); ?>]"
                                                                   <?php echo $option['default'] === '' ? '' : 'checked'; ?>>
                                                            <label class="form-check-label" for="<?php echo $id; ?>">
                                                                <?php echo $this->escape($option['label']); ?>
                                                            </label>
                                                        </div>
                                                    <?php else : ?>
                                                        <label class="form-label" for="<?php echo $id; ?>">
                                                            <?php echo $this->escape($option['label']); ?>
                                                        </label>
                                                        <?php if ($option['kind'] === 'choice') : ?>
                                                            <select class="form-select" id="<?php echo $id; ?>"
                                                                    name="options[<?php echo $this->escape($option['key']); ?>]">
                                                                <?php foreach ($option['choices'] as $choice) : ?>
                                                                    <option value="<?php echo $this->escape($choice['value']); ?>"
                                                                            <?php echo $choice['value'] === $option['default'] ? 'selected' : ''; ?>>
                                                                        <?php echo $this->escape($choice['label']); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        <?php else : ?>
                                                            <input class="form-control"
                                                                   type="<?php echo $option['kind'] === 'number' ? 'number' : 'text'; ?>"
                                                                   id="<?php echo $id; ?>"
                                                                   name="options[<?php echo $this->escape($option['key']); ?>]"
                                                                   value="" placeholder="<?php echo $this->escape($option['default']); ?>">
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    <?php if ($option['help'] !== '') : ?>
                                                        <div class="form-text"><?php echo $this->escape($option['help']); ?></div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </fieldset>
                                    <?php endif; ?>
                                <?php endforeach; ?>

                                <div class="alert alert-info mb-0">
                                    <span class="icon-info-circle" aria-hidden="true"></span>
                                    <span class="visually-hidden"><?php echo Text::_('INFO'); ?></span>
                                    <?php echo Text::_('COM_VIDYALMS_IMPORT_NO_ROLLBACK'); ?>
                                    <span class="d-block mt-2"><?php echo Text::_('COM_VIDYALMS_IMPORT_KEY_ADVICE'); ?></span>
                                </div>
                            </div>
                            <div class="card-footer">
                                <button type="submit" class="btn btn-secondary" name="task" value="import.preview">
                                    <span class="icon-search" aria-hidden="true"></span>
                                    <?php echo Text::_('COM_VIDYALMS_IMPORT_BUTTON_PREVIEW'); ?>
                                </button>
                                <button type="submit" class="btn btn-primary" name="task" value="import.run">
                                    <span class="icon-upload" aria-hidden="true"></span>
                                    <?php echo Text::_('COM_VIDYALMS_IMPORT_BUTTON_RUN'); ?>
                                </button>
                                <span class="ms-2 text-muted small"><?php echo Text::_('COM_VIDYALMS_IMPORT_BUTTON_HINT'); ?></span>
                            </div>
                        </div>

                        <input type="hidden" name="option" value="com_vidyalms">
                        <?php echo HTMLHelper::_('form.token'); ?>
                    </form>
                <?php endif; ?>

                <h2 class="h4"><?php echo Text::_('COM_VIDYALMS_IMPORT_HISTORY_HEADING'); ?></h2>

                <?php if ($this->jobs === []) : ?>
                    <div class="alert alert-info">
                        <span class="icon-info-circle" aria-hidden="true"></span>
                        <span class="visually-hidden"><?php echo Text::_('INFO'); ?></span>
                        <?php echo Text::_('COM_VIDYALMS_IMPORT_NO_JOBS'); ?>
                    </div>
                <?php else : ?>
                    <table class="table" id="vidyalms-import-jobs">
                        <caption class="visually-hidden">
                            <?php echo Text::_('COM_VIDYALMS_IMPORT_TABLE_CAPTION'); ?>
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col" class="w-10"><?php echo Text::_('JSTATUS'); ?></th>
                                <th scope="col"><?php echo Text::_('COM_VIDYALMS_IMPORT_HEADING_JOB'); ?></th>
                                <th scope="col" class="w-25 d-none d-md-table-cell"><?php echo Text::_('COM_VIDYALMS_IMPORT_HEADING_TOTALS'); ?></th>
                                <th scope="col" class="w-15 d-none d-lg-table-cell"><?php echo Text::_('COM_VIDYALMS_IMPORT_HEADING_FINISHED'); ?></th>
                                <th scope="col" class="w-5 d-none d-lg-table-cell"><?php echo Text::_('JGRID_HEADING_ID'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($this->jobs as $i => $job) : ?>
                                <?php
                                $trouble   = HtmlView::troubleLines($job->log);
                                $totals    = HtmlView::orderedTotals($job->totals);
                                $open      = $this->highlight === $job->id || !$job->isFinished();
                                $stalled   = $job->hasStalled($this->now);
                                $heartbeat = $job->heartbeatAt();
                                ?>
                                <tr class="row<?php echo $i % 2; ?>" data-vidyalms-job="<?php echo (int) $job->id; ?>">
                                    <td>
                                        <span class="badge <?php echo VidyaLmsHelper::importStatusBadge($job->status, $stalled); ?>"
                                              data-vidyalms-status>
                                            <?php echo Text::_(VidyaLmsHelper::importStatusLabel($job->status, $stalled)); ?>
                                        </span>
                                        <?php if ($job->isDryRun()) : ?>
                                            <div class="small text-muted"><?php echo Text::_('COM_VIDYALMS_IMPORT_DRY_RUN'); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo $this->escape($job->importer); ?></strong>
                                        <div class="small text-muted text-break">
                                            <?php echo $this->escape($job->sourcePath ?? Text::_('COM_VIDYALMS_IMPORT_NO_SOURCE')); ?>
                                        </div>

                                        <div data-vidyalms-trouble>
                                            <?php if ($trouble !== []) : ?>
                                                <div class="alert alert-warning mt-2 mb-2 py-2">
                                                    <span class="icon-exclamation-triangle" aria-hidden="true"></span>
                                                    <span class="visually-hidden"><?php echo Text::_('WARNING'); ?></span>
                                                    <strong><?php echo Text::sprintf('COM_VIDYALMS_IMPORT_TROUBLE_COUNT', \count($trouble)); ?></strong>
                                                    <ul class="mb-0 mt-1 small">
                                                        <?php foreach (\array_slice($trouble, 0, 10) as $note) : ?>
                                                            <li>
                                                                <span class="<?php echo HtmlView::noteClass($note->level); ?>">
                                                                    <?php echo Text::_(VidyaLmsHelper::importNoteLabel($note->level)); ?>
                                                                </span>
                                                                <?php echo $this->escape($note->message); ?>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (!$job->isFinished()) : ?>
                                            <div class="alert alert-warning mt-2 mb-2 py-2<?php echo $stalled ? '' : ' d-none'; ?>"
                                                 data-vidyalms-stalled>
                                                <span class="icon-exclamation-triangle" aria-hidden="true"></span>
                                                <span class="visually-hidden"><?php echo Text::_('WARNING'); ?></span>
                                                <strong><?php echo Text::_('COM_VIDYALMS_IMPORT_STALLED_HEADING'); ?></strong>
                                                <div class="small mt-1">
                                                    <?php echo Text::_('COM_VIDYALMS_IMPORT_STALLED_INTRO'); ?>
                                                </div>
                                                <div class="small mt-1">
                                                    <?php echo Text::_('COM_VIDYALMS_IMPORT_STALLED_HEARTBEAT'); ?>
                                                    <span data-vidyalms-heartbeat><?php echo $heartbeat !== null
                                                        ? HTMLHelper::_('date', $heartbeat->format('Y-m-d H:i:s'), Text::_('DATE_FORMAT_LC5'))
                                                        : '-'; ?></span>
                                                </div>
                                                <form action="<?php echo Route::_('index.php?option=com_vidyalms'); ?>"
                                                      method="post" class="mt-2 d-inline-block">
                                                    <input type="hidden" name="option" value="com_vidyalms">
                                                    <input type="hidden" name="job_id" value="<?php echo (int) $job->id; ?>">
                                                    <?php echo HTMLHelper::_('form.token'); ?>
                                                    <button type="submit" class="btn btn-sm btn-outline-danger"
                                                            name="task" value="import.abandon">
                                                        <?php echo Text::_('COM_VIDYALMS_IMPORT_BUTTON_ABANDON'); ?>
                                                    </button>
                                                    <button type="submit" class="btn btn-sm btn-outline-primary"
                                                            name="task" value="import.retry">
                                                        <?php echo Text::_('COM_VIDYALMS_IMPORT_BUTTON_RETRY'); ?>
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endif; ?>

                                        <details class="mt-1"<?php echo $open ? ' open' : ''; ?>>
                                            <summary class="small"><?php echo Text::_('COM_VIDYALMS_IMPORT_SHOW_LOG'); ?></summary>
                                            <pre class="vidyalms-import-log small mt-2 mb-0" data-vidyalms-log><?php
                                                echo $this->escape((string) ($job->log ?? ''));
                                            ?></pre>
                                        </details>
                                    </td>
                                    <td class="d-none d-md-table-cell" data-vidyalms-totals>
                                        <?php if ($totals === []) : ?>
                                            <span class="text-muted">-</span>
                                        <?php else : ?>
                                            <?php foreach ($totals as $key => $count) : ?>
                                                <?php $label = VidyaLmsHelper::importTotalLabel($key); ?>
                                                <span class="badge bg-light text-dark me-1">
                                                    <?php echo $this->escape($label !== '' ? Text::_($label) : $key); ?>:
                                                    <?php echo (int) $count; ?>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="d-none d-lg-table-cell" data-vidyalms-finished>
                                        <?php echo $job->finishedAt !== null
                                            ? HTMLHelper::_('date', $job->finishedAt->format('Y-m-d H:i:s'), Text::_('DATE_FORMAT_LC5'))
                                            : '<span class="text-muted">-</span>'; ?>
                                    </td>
                                    <td class="d-none d-lg-table-cell"><?php echo (int) $job->id; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ($this->pages > 1) : ?>
                        <nav aria-label="<?php echo $this->escape(Text::_('COM_VIDYALMS_IMPORT_HISTORY_HEADING')); ?>">
                            <ul class="pagination">
                                <li class="page-item<?php echo $this->page <= 1 ? ' disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo Route::_($pageUrl . '&page=' . max(1, $this->page - 1)); ?>">
                                        <?php echo Text::_('JPREV'); ?>
                                    </a>
                                </li>
                                <li class="page-item disabled">
                                    <span class="page-link">
                                        <?php echo Text::sprintf('COM_VIDYALMS_IMPORT_PAGE_OF', $this->page, $this->pages); ?>
                                    </span>
                                </li>
                                <li class="page-item<?php echo $this->page >= $this->pages ? ' disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo Route::_($pageUrl . '&page=' . min($this->pages, $this->page + 1)); ?>">
                                        <?php echo Text::_('JNEXT'); ?>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($this->pollIds !== []) : ?>
                    <p class="text-muted small" id="vidyalms-import-polling">
                        <span class="icon-loop" aria-hidden="true"></span>
                        <?php echo Text::_('COM_VIDYALMS_IMPORT_POLLING'); ?>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
