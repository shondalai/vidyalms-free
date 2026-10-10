/**
 * VidyaLMS import screen.
 *
 * Two jobs, both small, and neither is load bearing: the form's submit buttons
 * carry their own action, so the screen works with scripting off.
 *
 * First, the source controls follow the chosen importer, because only some
 * importers read the host database and each accepts its own file types.
 * Second, a queued import happens in the background, so the job rows poll until
 * they stop moving: the log grows and the totals count what actually exists so
 * far. Which rows to watch is read from the table, because the script is
 * enqueued before the table has queried anything.
 *
 * Polling stops as soon as every watched job is finished, or has stopped
 * responding: a run whose process died leaves a row that says "running" and
 * never changes again, and a screen that kept asking about it would ask for
 * ever. That row is shown as stopped responding, with the time it last wrote
 * and the operator's two ways out. Nothing here decides anything about the
 * import; it renders what the poll endpoint reports.
 *
 * @package VidyaLMS
 */
(function () {
    'use strict';

    var MIN_INTERVAL = 2000;
    var MAX_FAILURES = 5;

    function config() {
        return window.vidyalmsImport || null;
    }

    /**
     * The upload control and the selector belong to different source modes, and
     * only some importers read the host database at all. The choice is hidden
     * rather than disabled where an importer does not offer it, so nobody
     * chooses a source their importer cannot take.
     */
    function bindSourceModes() {
        var importer = document.getElementById('vidyalms-importer');
        var fileRow = document.getElementById('vidyalms-file-row');
        var selectorRow = document.getElementById('vidyalms-selector-row');
        var databaseChoice = document.getElementById('vidyalms-source-database-choice');
        var fileChoice = document.getElementById('vidyalms-source-file-choice');
        var databaseRadio = document.getElementById('vidyalms-source-database');
        var fileRadio = document.getElementById('vidyalms-source-file');
        var fileInput = document.getElementById('vidyalms-import-file');
        var hint = document.getElementById('vidyalms-importer-hint');
        var selectorInput = document.getElementById('vidyalms-selector');
        var selectorHint = document.getElementById('vidyalms-selector-hint');

        if (!importer || !fileRow || !selectorRow || !databaseChoice || !fileChoice || !databaseRadio || !fileRadio) {
            return;
        }

        function sync() {
            var option = importer.options[importer.selectedIndex] || null;
            var extensions = option ? (option.getAttribute('data-extensions') || '') : '';
            var acceptsDatabase = option ? option.getAttribute('data-database') === '1' : false;
            var acceptsFile = option ? option.getAttribute('data-file') !== '0' : true;
            if (selectorInput) {
                selectorInput.placeholder = option ? (option.getAttribute('data-selector-example') || '') : '';
            }
            if (selectorHint) {
                selectorHint.textContent = option ? (option.getAttribute('data-source-help') || '') : '';
            }

            databaseChoice.style.display = acceptsDatabase ? '' : 'none';
            fileChoice.style.display = acceptsFile ? '' : 'none';

            if (!acceptsDatabase && databaseRadio.checked) {
                fileRadio.checked = true;
            }

            if (!acceptsFile && acceptsDatabase) {
                databaseRadio.checked = true;
            }

            var database = databaseRadio.checked && acceptsDatabase;

            fileRow.style.display = database ? 'none' : '';
            selectorRow.style.display = database ? '' : 'none';

            if (fileInput) {
                fileInput.accept = extensions
                    ? extensions.split(',').map(function (extension) {
                        return '.' + extension.trim();
                    }).join(',')
                    : '';
            }

            if (hint) {
                hint.textContent = extensions ? extensions.split(',').join(', ') : '';
            }

            syncOptions(option ? option.value : '');
        }

        /**
         * One options fieldset per importer that declares any. The hidden ones
         * are disabled as well as hidden, so a browser never posts one
         * importer's options with another importer's run: hiding alone would
         * still submit every control on the form.
         */
        function syncOptions(key) {
            var fieldsets = document.querySelectorAll('[data-vidyalms-options]');

            Array.prototype.forEach.call(fieldsets, function (fieldset) {
                var mine = fieldset.getAttribute('data-vidyalms-options') === key;

                fieldset.style.display = mine ? '' : 'none';
                fieldset.disabled = !mine;
            });
        }

        importer.addEventListener('change', sync);
        fileRadio.addEventListener('change', sync);
        databaseRadio.addEventListener('change', sync);
        sync();
    }

    function pendingIds() {
        var rows = document.querySelectorAll('[data-vidyalms-job][data-vidyalms-pending="1"]');

        return Array.prototype.map.call(rows, function (row) {
            return row.getAttribute('data-vidyalms-job');
        }).filter(Boolean);
    }

    function rowFor(id) {
        return document.querySelector('[data-vidyalms-job="' + id + '"]');
    }

    // The notes arrive with their level beside them, so nothing here reads a
    // level out of a message: an imported course title cannot make itself
    // look like an error by starting with one.
    function troubleNotes(job) {
        return Array.isArray(job.notes) ? job.notes : [];
    }

    function renderTrouble(cell, notes, heading) {
        cell.textContent = '';

        if (!notes.length) {
            return;
        }

        var notice = document.createElement('div');
        notice.className = 'notice notice-warning inline vidyalms-import-trouble';

        var paragraph = document.createElement('p');
        var title = document.createElement('strong');
        title.textContent = heading.replace('%d', String(notes.length));
        paragraph.appendChild(title);
        notice.appendChild(paragraph);

        var list = document.createElement('ul');

        notes.slice(0, 10).forEach(function (note) {
            var item = document.createElement('li');
            var level = document.createElement('strong');

            level.textContent = note.label || note.level || '';
            item.appendChild(level);
            item.appendChild(document.createTextNode(' ' + (note.message || '')));
            list.appendChild(item);
        });

        notice.appendChild(list);
        cell.appendChild(notice);
    }

    function renderTotals(cell, totals) {
        cell.textContent = '';

        var keys = Object.keys(totals || {}).filter(function (key) {
            return Number(totals[key]) !== 0;
        });

        if (!keys.length) {
            cell.textContent = '-';

            return;
        }

        keys.forEach(function (key) {
            var pill = document.createElement('span');
            pill.className = 'vidyalms-pill vidyalms-pill-draft';
            pill.textContent = key + ': ' + Number(totals[key]);
            cell.appendChild(pill);
            cell.appendChild(document.createTextNode(' '));
        });
    }

    function applyJob(job, heading) {
        var row = rowFor(job.id);

        if (!row) {
            return;
        }

        // A stalled job is not moving and will not start again on its own, so
        // it stops being watched: polling a dead run for ever is what this
        // stops.
        row.setAttribute('data-vidyalms-pending', job.finished || job.stalled ? '0' : '1');

        var status = row.querySelector('[data-vidyalms-status]');

        if (status) {
            status.className = 'vidyalms-pill ' + (job.status_class || 'vidyalms-pill-suspended');
            status.textContent = job.status_text || job.status;
        }

        var log = row.querySelector('[data-vidyalms-log]');

        if (log) {
            log.textContent = job.log || '';
        }

        var totals = row.querySelector('[data-vidyalms-totals]');

        if (totals) {
            renderTotals(totals, job.totals);
        }

        var trouble = row.querySelector('[data-vidyalms-trouble]');

        if (trouble) {
            renderTrouble(trouble, troubleNotes(job), heading);
        }

        var finished = row.querySelector('[data-vidyalms-finished]');

        if (finished && job.finished_at) {
            finished.textContent = job.finished_at;
        }

        applyStalled(row, job);
    }

    /**
     * A run whose heartbeat stopped is shown as stopped responding, with the
     * time it last wrote and the two ways out. The block is already in the
     * row, nonce and all, because only the operator may end a job and the
     * script is not allowed to be the one that decides anything.
     */
    function applyStalled(row, job) {
        var stalled = row.querySelector('[data-vidyalms-stalled]');

        if (!stalled) {
            return;
        }

        stalled.style.display = job.stalled ? '' : 'none';

        var heartbeat = stalled.querySelector('[data-vidyalms-heartbeat]');

        if (heartbeat && job.heartbeat) {
            heartbeat.textContent = job.heartbeat;
        }
    }

    function startPolling(settings) {
        var watching = pendingIds();

        if (!watching.length) {
            return;
        }

        var interval = Math.max(MIN_INTERVAL, Number(settings.interval) || MIN_INTERVAL);
        var heading = settings.troubleHeading || '%d items need your attention';
        var failures = 0;
        var timer = null;

        function stop() {
            if (timer) {
                window.clearTimeout(timer);
                timer = null;
            }

            var banner = document.getElementById('vidyalms-import-polling');

            if (banner) {
                banner.style.display = 'none';
            }
        }

        function tick() {
            var body = new window.FormData();
            body.append('action', settings.action);
            body.append('nonce', settings.nonce);
            body.append('ids', watching.join(','));

            window.fetch(settings.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                body: body
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('poll failed');
                }

                return response.json();
            }).then(function (payload) {
                failures = 0;

                if (!payload || !payload.success) {
                    stop();

                    return;
                }

                var jobs = (payload.data && payload.data.jobs) || [];

                jobs.forEach(function (job) {
                    applyJob(job, heading);
                });

                watching = pendingIds();

                if (!watching.length) {
                    stop();

                    return;
                }

                timer = window.setTimeout(tick, interval);
            }).catch(function () {
                failures += 1;

                if (failures >= MAX_FAILURES) {
                    stop();

                    return;
                }

                timer = window.setTimeout(tick, interval * 2);
            });
        }

        timer = window.setTimeout(tick, interval);
    }

    function boot() {
        bindSourceModes();

        var settings = config();

        if (settings && settings.endpoint && settings.action && settings.nonce) {
            startPolling(settings);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
