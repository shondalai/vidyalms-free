/**
 * VidyaLMS import screen.
 *
 * Two jobs, both small, and neither is load bearing: the form's submit buttons
 * carry their own task, so the screen works with scripting off.
 *
 * First, the source controls follow the chosen importer, because only some
 * importers read the host database and each accepts its own file types.
 * Second, a queued import happens in the background, so the job rows poll
 * until they stop moving: the log grows and the totals count what actually
 * exists so far.
 *
 * Polling stops as soon as every watched job is finished, or has stopped
 * responding: a run whose process died leaves a row that says "running" and
 * never changes again, and a screen that kept asking about it would ask for
 * ever. That row is shown as stopped responding, with the time it last wrote
 * and the operator's two ways out. Nothing here decides anything about the
 * import; it renders what the poll task reports.
 *
 * @copyright  Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license    GNU General Public License version 2 or later
 */
(function () {
    'use strict';

    var MIN_INTERVAL = 2000;
    var MAX_FAILURES = 5;

    function options() {
        if (!window.Joomla || typeof window.Joomla.getOptions !== 'function') {
            return null;
        }

        return window.Joomla.getOptions('com_vidyalms.import', null);
    }

    /**
     * The upload control and the selector belong to different source modes, and
     * only some importers read the host database at all. The choice is hidden
     * rather than disabled where an importer does not offer it, so nobody
     * chooses a source their importer cannot take.
     */
    function bindSourceModes() {
        var form = document.getElementById('vidyalms-import-form');

        if (!form) {
            return;
        }

        var importer = document.getElementById('vidyalms-importer');
        var fileRow = document.getElementById('vidyalms-file-row');
        var selectorRow = document.getElementById('vidyalms-selector-row');
        var databaseChoice = document.getElementById('vidyalms-source-database-choice');
        var fileChoice = document.getElementById('vidyalms-source-file-choice');
        var databaseRadio = document.getElementById('vidyalms-source-database');
        var fileRadio = document.getElementById('vidyalms-source-file');
        var fileInput = document.getElementById('vidyalms-import-file');
        var hint = document.getElementById('vidyalms-importer-hint');

        if (!importer || !fileRow || !selectorRow || !databaseChoice || !fileChoice || !databaseRadio || !fileRadio) {
            return;
        }

        function selected() {
            return importer.options[importer.selectedIndex] || null;
        }

        function sync() {
            var option = selected();
            var extensions = option ? (option.getAttribute('data-extensions') || '') : '';
            var acceptsDatabase = option ? option.getAttribute('data-database') === '1' : false;
            var acceptsFile = option ? option.getAttribute('data-file') !== '0' : true;

            databaseChoice.classList.toggle('d-none', !acceptsDatabase);
            fileChoice.classList.toggle('d-none', !acceptsFile);

            if (!acceptsDatabase && databaseRadio.checked) {
                fileRadio.checked = true;
            }

            if (!acceptsFile && acceptsDatabase) {
                databaseRadio.checked = true;
            }

            var database = databaseRadio.checked && acceptsDatabase;

            fileRow.classList.toggle('d-none', database);
            selectorRow.classList.toggle('d-none', !database);

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
            var fieldsets = form.querySelectorAll('[data-vidyalms-options]');

            Array.prototype.forEach.call(fieldsets, function (fieldset) {
                var mine = fieldset.getAttribute('data-vidyalms-options') === key;

                fieldset.classList.toggle('d-none', !mine);
                fieldset.disabled = !mine;
            });
        }

        importer.addEventListener('change', sync);
        fileRadio.addEventListener('change', sync);
        databaseRadio.addEventListener('change', sync);
        sync();
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

        var alert = document.createElement('div');
        alert.className = 'alert alert-warning mt-2 mb-2 py-2';

        var title = document.createElement('strong');
        title.textContent = heading.replace('%d', String(notes.length));
        alert.appendChild(title);

        var list = document.createElement('ul');
        list.className = 'mb-0 mt-1 small';

        notes.slice(0, 10).forEach(function (note) {
            var item = document.createElement('li');
            var level = document.createElement('span');

            level.className = note.class || 'text-warning-emphasis';
            level.textContent = note.label || note.level || '';
            item.appendChild(level);
            item.appendChild(document.createTextNode(' ' + (note.message || '')));
            list.appendChild(item);
        });

        alert.appendChild(list);
        cell.appendChild(alert);
    }

    function renderTotals(cell, totals) {
        cell.textContent = '';

        var keys = Object.keys(totals || {}).filter(function (key) {
            return Number(totals[key]) !== 0;
        });

        if (!keys.length) {
            var empty = document.createElement('span');
            empty.className = 'text-muted';
            empty.textContent = '-';
            cell.appendChild(empty);

            return;
        }

        keys.forEach(function (key) {
            var badge = document.createElement('span');
            badge.className = 'badge bg-light text-dark me-1';
            badge.textContent = key + ': ' + Number(totals[key]);
            cell.appendChild(badge);
        });
    }

    function applyJob(job, heading) {
        var row = rowFor(job.id);

        if (!row) {
            return;
        }

        var badge = row.querySelector('[data-vidyalms-status]');

        if (badge) {
            badge.className = 'badge ' + (job.badge || 'bg-secondary');
            badge.textContent = job.status_text || job.status;
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
     * row, token and all, because only the operator may end a job and the
     * script is not allowed to be the one that decides anything.
     */
    function applyStalled(row, job) {
        var stalled = row.querySelector('[data-vidyalms-stalled]');

        if (!stalled) {
            return;
        }

        stalled.classList.toggle('d-none', !job.stalled);

        var heartbeat = stalled.querySelector('[data-vidyalms-heartbeat]');

        if (heartbeat && job.heartbeat) {
            heartbeat.textContent = job.heartbeat;
        }
    }

    function startPolling(config) {
        var watching = (config.ids || []).slice();

        if (!watching.length) {
            return;
        }

        var interval = Math.max(MIN_INTERVAL, Number(config.interval) || MIN_INTERVAL);
        var heading = config.troubleHeading || '%d notes need attention';
        var failures = 0;
        var timer = null;

        function stop() {
            if (timer) {
                window.clearTimeout(timer);
                timer = null;
            }

            var banner = document.getElementById('vidyalms-import-polling');

            if (banner) {
                banner.classList.add('d-none');
            }
        }

        function tick() {
            var url = config.endpoint
                + '&ids=' + encodeURIComponent(watching.join(','))
                + '&' + encodeURIComponent(config.token) + '=1';

            window.fetch(url, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('poll failed');
                }

                return response.json();
            }).then(function (payload) {
                failures = 0;

                if (payload.error) {
                    stop();

                    return;
                }

                var jobs = payload.jobs || [];
                var stillMoving = [];

                jobs.forEach(function (job) {
                    applyJob(job, heading);

                    // A stalled job is not moving and will not start again on
                    // its own, so it is dropped from the watch list: polling a
                    // dead run for ever is what this stops.
                    if (!job.finished && !job.stalled) {
                        stillMoving.push(job.id);
                    }
                });

                watching = stillMoving;

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

        var config = options();

        if (config && config.endpoint && config.token) {
            startPolling(config);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
