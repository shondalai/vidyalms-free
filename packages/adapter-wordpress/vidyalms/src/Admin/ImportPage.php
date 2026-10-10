<?php

declare( strict_types=1 );

/**
 * The migration screen.
 *
 * @package VidyaLMS
 */

namespace Shondalai\VidyaLMS\WordPress\Admin;

use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImporterRegistry;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportOption;
use Shondalai\VidyaLMS\WordPress\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * What can be imported, the two ways to start one, and the history of every
 * run.
 *
 * Not one row is written here. Starting an import is QueueImportJob (a real
 * run, which the queue runner picks up) or RunImportJob (a dry run, which
 * answers the operator now); reading history is ListImportJobs and
 * GetImportJob. Every one of them requires MANAGE inside the use case, so the
 * capability check on this screen turns a refusal into a message rather than
 * being the gate.
 *
 * The upload is the only thing this adapter owns, because bytes never travel
 * through the REST layer: the file is validated, stored through StoragePort,
 * and its private path is what the job records as its source.
 *
 * Nothing is undone. A run that failed after eleven of twenty lessons has
 * created eleven lessons, and running the same source again is the recovery:
 * the import map turns the second run into an update. There is deliberately no
 * "clean up failed import" action.
 */
final class ImportPage {

	public const NONCE_ACTION = 'vidyalms_import_start';

	public const NONCE_FIELD = 'vidyalms_import_nonce';

	public const AJAX_ACTION = 'vidyalms_import_poll';

	/** Prepare a source with the same upload validation as the native form. */
	public function ajaxWorkspace(): void {
		check_ajax_referer( self::NONCE_ACTION, self::NONCE_FIELD );
		if ( ! Menu::can( Capability::MANAGE ) ) {
			wp_send_json( array( 'error' => __( 'You do not have permission to manage imports.', 'vidyalms' ) ), 403 );
		}
		$kernel = Menu::kernel();
		if ( $kernel === null ) {
			wp_send_json( array( 'error' => __( 'The VidyaLMS engine is not available.', 'vidyalms' ) ), 503 );
		}
		$operation = isset( $_POST['operation'] ) && is_scalar( $_POST['operation'] )
			? sanitize_key( wp_unslash( (string) $_POST['operation'] ) ) : '';
		try {
			if ( $operation === 'catalogue' ) {
				wp_send_json( array( 'importers' => $this->importers(), 'max_bytes' => self::maxUploadBytes() ) );
			}
			if ( $operation === 'abandon' ) {
				$id = isset( $_POST['job_id'] ) && is_scalar( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
				$job = $kernel->getImportJob->execute( Menu::principal(), $id );
				if ( ! $job->isFinished() ) {
					$job = $kernel->abandonImportJob->execute( Menu::principal(), $job->id );
				}
				wp_send_json( array( 'id' => $job->id ) );
			}
			if ( $operation !== 'preview' ) {
				wp_send_json( array( 'error' => __( 'This import operation is not available.', 'vidyalms' ) ), 400 );
			}
			$key = isset( $_POST['importer'] ) && is_scalar( $_POST['importer'] )
				? sanitize_text_field( wp_unslash( (string) $_POST['importer'] ) ) : '';
			if ( ! $kernel->registries->importers->has( $key ) ) {
				throw new \RuntimeException( __( 'That importer is not registered on this site.', 'vidyalms' ) );
			}
			$source = $this->resolveSource( $key );
			$options = $this->submittedOptions( $kernel->registries->importers, $key );
			$options['dry_run'] = true;
			$job = $kernel->runImportJob->execute( Menu::principal(), $key, $source, $options );
			wp_send_json( array( 'id' => $job->id ) );
		} catch ( DomainException | \RuntimeException $failure ) {
			wp_send_json( array( 'error' => $failure->getMessage() ), 422 );
		}
	}

	/**
	 * The row actions on a run that stopped responding. They are links rather
	 * than a form because the list table is already inside one, and a nonce is
	 * what makes a link safe to act on.
	 */
	public const NONCE_ROW_ACTION = 'vidyalms_import_row';

	public const ACTION_ABANDON = 'abandon';

	public const ACTION_RETRY = 'retry';

	/**
	 * The upload cap when the site has not set one, in megabytes. PHP's own
	 * upload_max_filesize and post_max_size still apply and are the lower bound
	 * the screen reports.
	 */
	public const DEFAULT_MAX_UPLOAD_MB = 64;

	/**
	 * Site owners raise or lower the cap through this filter rather than a
	 * setting, because the number that actually binds is usually PHP's.
	 */
	public const FILTER_MAX_UPLOAD_MB = 'vidyalms_import_max_upload_mb';

	private ?ImportJobsListTable $table = null;

	/**
	 * @var array<int, array{key: string, label: string, extensions: array<int, string>, accepts_file: bool, accepts_database: bool, database_scheme: string, source_installed: bool, options: array<int, array{key: string, label: string, kind: string, help: string, default: string, choices: array<int, array{value: string, label: string}>}>}>|null
	 */
	private ?array $importers = null;

	/**
	 * Runs before any output, so a start can redirect.
	 */
	public function load(): void {
		if ( ! Menu::can( Capability::MANAGE ) ) {
			return;
		}

		$this->handleStart();
		$this->handleRowAction();

		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Import jobs per page', 'vidyalms' ),
				'default' => 20,
				'option'  => ImportJobsListTable::PER_PAGE_OPTION,
			)
		);

		$this->table = new ImportJobsListTable();
	}

	public function render(): void {
		if ( ! Menu::can( Capability::MANAGE ) ) {
			wp_die(
				esc_html__( 'You do not have permission to import into VidyaLMS.', 'vidyalms' ),
				'',
				array( 'response' => 403 )
			);
		}

		echo '<div class="wrap vidyalms-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Import', 'vidyalms' ) . '</h1>';
		echo '<hr class="wp-header-end">';

		Menu::renderNotices();

		if ( Menu::kernel() === null ) {
			Menu::renderKernelNotice();
			echo '</div>';

			return;
		}

		$this->renderQueueWarnings();
		$this->renderForm();
		$this->renderHistory();

		echo '</div>';
	}

	/**
	 * The poll endpoint the job list uses while a queued import runs. Same
	 * capability and same nonce as starting one: an import log can name courses
	 * and people.
	 */
	public function ajaxPoll(): void {
		if ( ! Menu::can( Capability::MANAGE ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to read import jobs.', 'vidyalms' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$kernel = Menu::kernel();

		if ( $kernel === null ) {
			wp_send_json_error( array( 'message' => __( 'The VidyaLMS engine is not available.', 'vidyalms' ) ), 503 );
		}

		$raw   = isset( $_POST['ids'] ) && ! is_array( $_POST['ids'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['ids'] ) )
			: '';
		$ids   = array();
		$jobs  = array();
		$parts = $raw === '' ? array() : explode( ',', $raw );

		foreach ( $parts as $part ) {
			$id = absint( trim( $part ) );

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		foreach ( array_slice( array_values( $ids ), 0, 20 ) as $id ) {
			try {
				$jobs[] = self::jobPayload( $kernel->getImportJob->execute( Menu::principal(), $id ) );
			} catch ( EntityNotFoundException $missing ) {
				// A job pruned between two polls simply stops being reported.
				continue;
			} catch ( DomainException $refused ) {
				wp_send_json_error( array( 'message' => $refused->getMessage() ), 403 );
			}
		}

		wp_send_json_success( array( 'jobs' => $jobs ) );
	}

	/**
	 * The ids on the current page that are still moving, so the script knows
	 * whether it has anything to poll for.
	 *
	 * @return array<int, int>
	 */
	public function pollIds(): array {
		return $this->table !== null ? $this->table->unfinishedIds() : array();
	}

	/**
	 * The job as the script renders it. "source" rather than "source_path", to
	 * match the wire field the /v1/import routes use.
	 *
	 * @param ImportJob $job The job to describe.
	 *
	 * @return array<string, mixed>
	 */
	public static function jobPayload( ImportJob $job ): array {
		$stalled = self::hasStalled( $job );

		return array(
			'id'          => $job->id,
			'status'      => $job->status,
			'status_text' => self::statusLabel( $job->status, $stalled ),
			'status_class' => self::statusClass( $job->status, $stalled ),
			'source'      => $job->sourcePath,
			'dry_run'     => $job->isDryRun(),
			'finished'    => $job->isFinished(),
			// A presumption about a row that stopped moving, never the stored
			// status: the screen stops polling and offers the operator a way
			// out, and nothing rewrites the job behind their back.
			'stalled'     => $stalled,
			'heartbeat'   => self::formatMoment( $job->heartbeatAt() ),
			'totals'      => $job->totals ?? array(),
			'log'         => $job->log ?? '',
			// The notes carry their level as data, so the script shows a
			// warning because the core recorded one and not because an
			// imported title happens to start with "[warning]".
			'notes'       => array_map(
				static fn ( ImportNote $note ): array => array(
					'level'   => $note->level,
					'message' => $note->message,
					'label'   => self::noteLabel( $note->level ),
				),
				self::troubleLines( $job->log )
			),
			'finished_at' => Menu::formatDate( $job->finishedAt !== null ? $job->finishedAt->format( 'Y-m-d H:i:s' ) : '' ),
		);
	}

	/**
	 * A moment with the time on it. The date alone is what Menu::formatDate
	 * gives, and it does not tell an operator whether a run stopped a minute
	 * ago or overnight, which is the whole question a heartbeat answers.
	 *
	 * @param \DateTimeImmutable|null $moment The moment, in UTC.
	 */
	public static function formatMoment( ?\DateTimeImmutable $moment ): string {
		if ( $moment === null ) {
			return '';
		}

		$timestamp = strtotime( $moment->format( 'Y-m-d H:i:s' ) . ' UTC' );

		if ( $timestamp === false ) {
			return '';
		}

		$date = get_option( 'date_format' );
		$time = get_option( 'time_format' );

		return (string) wp_date(
			( is_string( $date ) && $date !== '' ? $date : 'Y-m-d' ) . ' ' . ( is_string( $time ) && $time !== '' ? $time : 'H:i' ),
			$timestamp
		);
	}

	/**
	 * Whether a run has stopped writing. The clock is the core's own, so the
	 * screen and the engine never disagree about what time it is.
	 *
	 * @param ImportJob $job The job to judge.
	 */
	public static function hasStalled( ImportJob $job ): bool {
		try {
			return $job->hasStalled( Plugin::ports()->clock->now() );
		} catch ( \Throwable $unavailable ) {
			return false;
		}
	}

	/**
	 * @param string $status  The stored status.
	 * @param bool   $stalled Whether the run has stopped writing, which is a
	 *                        display state and never the stored status.
	 */
	public static function statusLabel( string $status, bool $stalled = false ): string {
		if ( $stalled ) {
			return __( 'Stopped responding', 'vidyalms' );
		}

		return match ( $status ) {
			ImportJob::STATUS_RUNNING   => __( 'Running', 'vidyalms' ),
			ImportJob::STATUS_COMPLETED => __( 'Completed', 'vidyalms' ),
			ImportJob::STATUS_PREVIEWED => __( 'Previewed', 'vidyalms' ),
			ImportJob::STATUS_FAILED    => __( 'Failed', 'vidyalms' ),
			default                     => __( 'Queued', 'vidyalms' ),
		};
	}

	/**
	 * @param string $status  The stored status.
	 * @param bool   $stalled Whether the run has stopped writing.
	 */
	public static function statusClass( string $status, bool $stalled = false ): string {
		if ( $stalled ) {
			return 'vidyalms-pill-suspended';
		}

		return match ( $status ) {
			ImportJob::STATUS_RUNNING   => 'vidyalms-pill-active',
			ImportJob::STATUS_COMPLETED => 'vidyalms-pill-completed',
			ImportJob::STATUS_PREVIEWED => 'vidyalms-pill-draft',
			ImportJob::STATUS_FAILED    => 'vidyalms-pill-cancelled',
			default                     => 'vidyalms-pill-suspended',
		};
	}

	/**
	 * The nonce-protected link that ends a run that stopped responding, or
	 * queues its source again.
	 *
	 * @param string $action One of the ACTION_* constants.
	 * @param int    $jobId  The job to act on.
	 */
	public static function rowActionUrl( string $action, int $jobId ): string {
		return wp_nonce_url(
			Menu::url(
				Menu::SLUG_IMPORT,
				array(
					'vidyalms_row_action' => $action,
					'vidyalms_job'        => $jobId,
				)
			),
			self::NONCE_ROW_ACTION,
			'_vidyalms_nonce'
		);
	}

	/**
	 * The warning and error notes of one job.
	 *
	 * The core parses the log and the level comes back as data, rather than
	 * this screen matching a prefix against text. That is the whole point: a
	 * note quotes course and lesson titles the source wrote, and a title
	 * holding a line break and "[error]" would otherwise put a line into this
	 * list that VidyaLMS never emitted. ImportNote flattens every note to one
	 * line when it is written, and parseLog() is the reader that pairs with
	 * that writer.
	 *
	 * @param string|null $log The job log.
	 *
	 * @return array<int, ImportNote>
	 */
	public static function troubleLines( ?string $log ): array {
		return array_values(
			array_filter(
				ImportNote::parseLog( $log ),
				static fn ( ImportNote $note ): bool => $note->level !== ImportNote::INFO
			)
		);
	}

	/**
	 * What a note's level is called on screen, from the level the core
	 * recorded and never from the note's own text.
	 *
	 * @param string $level The note level.
	 */
	public static function noteLabel( string $level ): string {
		return match ( $level ) {
			ImportNote::ERROR   => __( 'Error', 'vidyalms' ),
			ImportNote::WARNING => __( 'Warning', 'vidyalms' ),
			default             => __( 'Note', 'vidyalms' ),
		};
	}

	/**
	 * The totals in the shared vocabulary's order first, then whatever else the
	 * importer counted. A zero is dropped: an admin reading a migration wants
	 * what came across, not a row of noughts.
	 *
	 * @param array<string, mixed>|null $totals Totals as stored on the job.
	 *
	 * @return array<string, int>
	 */
	public static function orderedTotals( ?array $totals ): array {
		if ( $totals === null || $totals === array() ) {
			return array();
		}

		$order   = array( 'courses', 'modules', 'lessons', 'quizzes', 'questions', 'enrolments', 'learners', 'skipped' );
		$ordered = array();

		foreach ( $order as $key ) {
			if ( isset( $totals[ $key ] ) && is_numeric( $totals[ $key ] ) && (int) $totals[ $key ] !== 0 ) {
				$ordered[ $key ] = (int) $totals[ $key ];
			}
		}

		foreach ( $totals as $key => $value ) {
			if ( ! is_string( $key ) || isset( $ordered[ $key ] ) || in_array( $key, $order, true ) ) {
				continue;
			}

			if ( is_numeric( $value ) && (int) $value !== 0 ) {
				$ordered[ $key ] = (int) $value;
			}
		}

		return $ordered;
	}

	public static function totalLabel( string $key ): string {
		return match ( $key ) {
			'courses'    => __( 'Courses', 'vidyalms' ),
			'modules'    => __( 'Modules', 'vidyalms' ),
			'lessons'    => __( 'Lessons', 'vidyalms' ),
			'quizzes'    => __( 'Quizzes', 'vidyalms' ),
			'questions'  => __( 'Questions', 'vidyalms' ),
			'enrolments' => __( 'Enrolments', 'vidyalms' ),
			'learners'   => __( 'Learners', 'vidyalms' ),
			'skipped'    => __( 'Skipped', 'vidyalms' ),
			default      => $key,
		};
	}

	/**
	 * The smaller of the filtered cap and what PHP will actually accept, in
	 * bytes. Reporting the configured number while PHP silently truncates at 8M
	 * is how an operator loses an afternoon.
	 */
	public static function maxUploadBytes(): int {
		$configured = (int) apply_filters( self::FILTER_MAX_UPLOAD_MB, \Shondalai\VidyaLMS\WordPress\Setup\Settings::get('import_max_upload_mb', self::DEFAULT_MAX_UPLOAD_MB) );
		$limits     = array( max( 1, $configured ) * MB_IN_BYTES );
		$php        = wp_max_upload_size();

		if ( $php > 0 ) {
			$limits[] = (int) $php;
		}

		return (int) min( $limits );
	}

	/**
	 * The registered importers, described for the screen.
	 *
	 * @return array<int, array{key: string, label: string, extensions: array<int, string>, accepts_file: bool, accepts_database: bool, database_scheme: string, source_installed: bool, options: array<int, array{key: string, label: string, kind: string, help: string, default: string, choices: array<int, array{value: string, label: string}>}>}>
	 */
	private function importers(): array {
		if ( $this->importers !== null ) {
			return $this->importers;
		}

		$kernel = Menu::kernel();

		if ( $kernel === null ) {
			return $this->importers = array();
		}

		return $this->importers = ImportSources::descriptors( $kernel->registries->importers );
	}

	/**
	 * A real import is queued, not run inline, so it needs WP-Cron alive or
	 * Action Scheduler installed. Without either, the job row is created, stays
	 * pending forever and nothing says why.
	 */
	private function renderQueueWarnings(): void {
		if ( defined( 'DISABLE_WP_CRON' ) && constant( 'DISABLE_WP_CRON' ) === true ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__(
					'WP-Cron is disabled on this site (DISABLE_WP_CRON), so a queued import runs only when your system cron calls wp-cron.php. Check that it does, or the import will sit waiting. A preview still works.',
					'vidyalms'
				)
			);

			return;
		}

		if ( wp_next_scheduled( Plugin::HOOK_RUN_JOBS ) === false ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__(
					'The VidyaLMS queue is not scheduled, so a queued import will sit waiting. Deactivate and reactivate the plugin to restore the schedule. A preview still works.',
					'vidyalms'
				)
			);
		}
	}

	private function renderForm(): void {
		$importers = $this->importers();

		if ( $importers === array() ) {
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html__(
					'No importer is registered on this site, so there is nothing to import from. Check that the extension providing your importer is active.',
					'vidyalms'
				)
			);

			return;
		}

		$maxUpload = size_format( self::maxUploadBytes() );

		echo '<div class="card vidyalms-import-panel">';
		echo '<h2>' . esc_html__( 'Start an import', 'vidyalms' ) . '</h2>';
		echo '<p>' . esc_html__(
			'Pick an importer, give it a source, and preview it before you run it. An importer writes through the same rules as the editing screens, so imported courses land as drafts for you to review.',
			'vidyalms'
		) . '</p>';

		printf(
			'<form method="post" enctype="multipart/form-data" action="%s" id="vidyalms-import-form">',
			esc_url( Menu::url( Menu::SLUG_IMPORT ) )
		);

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="vidyalms-importer">' . esc_html__( 'Importer', 'vidyalms' ) . '</label></th><td>';
		echo '<select name="importer" id="vidyalms-importer" required>';

		foreach ( $importers as $importer ) {
			printf(
				'<option value="%1$s" data-extensions="%2$s" data-file="%3$s" data-database="%4$s" data-source-help="%6$s" data-selector-example="%7$s">%5$s</option>',
				esc_attr( $importer['key'] ),
				esc_attr( implode( ',', $importer['extensions'] ) ),
				$importer['accepts_file'] ? '1' : '0',
				$importer['accepts_database'] ? '1' : '0',
				esc_html( $importer['label'] ),
				esc_attr( ImportSources::selectorHelp( $importer['database_scheme'] ) ),
				esc_attr( ImportSources::selectorExample( $importer['database_scheme'] ) )
			);
		}

		echo '</select>';
		echo '<p class="description" id="vidyalms-importer-hint"></p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Source', 'vidyalms' ) . '</th><td>';
		echo '<fieldset>';
		printf(
			'<p id="vidyalms-source-file-choice"><label><input type="radio" name="source_mode" id="vidyalms-source-file" value="file" checked> %s</label></p>',
			esc_html__( 'An uploaded file', 'vidyalms' )
		);
		printf(
			'<p id="vidyalms-source-database-choice"><label><input type="radio" name="source_mode" id="vidyalms-source-database" value="database"> %s</label></p>',
			esc_html__( "This site's own database", 'vidyalms' )
		);
		echo '</fieldset>';
		echo '</td></tr>';

		echo '<tr id="vidyalms-file-row"><th scope="row"><label for="vidyalms-import-file">' . esc_html__( 'File', 'vidyalms' ) . '</label></th><td>';
		echo '<input type="file" name="import_file" id="vidyalms-import-file">';
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: maximum upload size, already formatted. */
					__( 'Stored in the private file area and served only through signed links. Up to %s.', 'vidyalms' ),
					$maxUpload
				)
			)
		);
		echo '</td></tr>';

		echo '<tr id="vidyalms-selector-row" style="display:none"><th scope="row"><label for="vidyalms-selector">'
			. esc_html__( 'Selector (optional)', 'vidyalms' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" name="selector" id="vidyalms-selector" value="">';
		printf(
			'<p class="description" id="vidyalms-selector-hint">%s</p>',
			esc_html__( 'Use a selector supported by the chosen importer, or leave it empty to read all of its courses.', 'vidyalms' )
		);
		if ( is_multisite() ) {
			echo '<p class="description">' . esc_html__( 'Local LearnDash and Tutor LMS database imports are unavailable on multisite because their learner metadata cannot yet be safely scoped to one site.', 'vidyalms' ) . '</p>';
		}
		echo '</td></tr>';

		echo '</tbody></table>';

		$this->renderOptions( $importers );

		printf(
			'<div class="notice notice-info inline"><p>%1$s</p><p>%2$s</p></div>',
			esc_html__(
				'Nothing is rolled back. An import that fails halfway keeps what it already created, and running the same source again updates those rows instead of duplicating them.',
				'vidyalms'
			),
			esc_html__(
				'A re-import finds what it already created by the identity the source gives it. A JSON document should carry a "key" of its own before you correct it and upload it again: without one it is identified by its exact content, so a corrected copy would import as a second course. A keyless run writes the key to add into its log.',
				'vidyalms'
			)
		);

		echo '<p class="submit">';
		printf(
			'<button type="submit" class="button" name="vidyalms_import_action" value="preview">%s</button> ',
			esc_html__( 'Preview', 'vidyalms' )
		);
		printf(
			'<button type="submit" class="button button-primary" name="vidyalms_import_action" value="run">%s</button>',
			esc_html__( 'Run import', 'vidyalms' )
		);
		printf(
			' <span class="description">%s</span>',
			esc_html__( 'A preview writes nothing and answers here. A real import runs in the background.', 'vidyalms' )
		);
		echo '</p>';

		echo '</form>';
		echo '</div>';
	}

	/**
	 * One options fieldset per importer that declares any.
	 *
	 * Every control in a hidden fieldset is disabled as well as hidden, so a
	 * browser never posts one importer's options with another importer's run:
	 * hiding alone would still submit every control on the form. With scripting
	 * off the first importer is the selected one, and its fieldset is the
	 * enabled one, so the screen still works.
	 *
	 * Nothing here knows an option name. The importer declares what it accepts
	 * through ConfigurableImporterInterface and this draws a control per
	 * declaration, so a QTI profile or a Moodle XML dialect appears the day the
	 * package registering it says it exists, with no edit to this file.
	 *
	 * @param array<int, array{key: string, label: string, extensions: array<int, string>, accepts_file: bool, accepts_database: bool, database_scheme: string, source_installed: bool, options: array<int, array{key: string, label: string, kind: string, help: string, default: string, choices: array<int, array{value: string, label: string}>}>}> $importers Descriptors.
	 */
	private function renderOptions( array $importers ): void {
		$index = 0;

		foreach ( $importers as $importer ) {
			$active = 0 === $index;
			++$index;

			if ( array() === $importer['options'] ) {
				continue;
			}

			printf(
				'<fieldset class="vidyalms-import-options" data-vidyalms-options="%1$s"%2$s%3$s>',
				esc_attr( $importer['key'] ),
				$active ? '' : ' style="display:none"',
				$active ? '' : ' disabled'
			);
			printf( '<legend>%s</legend>', esc_html__( 'Options for this importer', 'vidyalms' ) );
			echo '<table class="form-table" role="presentation"><tbody>';

			foreach ( $importer['options'] as $option ) {
				$this->renderOption( $importer['key'], $option );
			}

			echo '</tbody></table>';
			echo '</fieldset>';
		}
	}

	/**
	 * One declared option, as the control its kind asks for.
	 *
	 * @param string                                                                                                              $importerKey The importer this belongs to.
	 * @param array{key: string, label: string, kind: string, help: string, default: string, choices: array<int, array{value: string, label: string}>} $option      The declaration.
	 */
	private function renderOption( string $importerKey, array $option ): void {
		$id   = 'vidyalms-option-' . sanitize_key( $importerKey . '-' . $option['key'] );
		$name = 'options[' . $option['key'] . ']';

		echo '<tr><th scope="row">';

		if ( 'boolean' === $option['kind'] ) {
			echo '<span>' . esc_html( $option['label'] ) . '</span>';
		} else {
			printf( '<label for="%1$s">%2$s</label>', esc_attr( $id ), esc_html( $option['label'] ) );
		}

		echo '</th><td>';

		switch ( $option['kind'] ) {
			case 'boolean':
				printf(
					'<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s> %4$s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					'' === $option['default'] ? '' : ' checked',
					esc_html( $option['label'] )
				);
				break;

			case 'choice':
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );

				foreach ( $option['choices'] as $choice ) {
					printf(
						'<option value="%1$s"%2$s>%3$s</option>',
						esc_attr( $choice['value'] ),
						$choice['value'] === $option['default'] ? ' selected' : '',
						esc_html( $choice['label'] )
					);
				}

				echo '</select>';
				break;

			default:
				printf(
					'<input type="%1$s" class="regular-text" id="%2$s" name="%3$s" value="" placeholder="%4$s">',
					'number' === $option['kind'] ? 'number' : 'text',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $option['default'] )
				);
		}

		if ( '' !== $option['help'] ) {
			printf( '<p class="description">%s</p>', esc_html( $option['help'] ) );
		}

		echo '</td></tr>';
	}

	private function renderHistory(): void {
		echo '<h2>' . esc_html__( 'Import history', 'vidyalms' ) . '</h2>';

		if ( $this->table === null ) {
			return;
		}

		$this->table->prepare_items();

		echo '<form method="get">';
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( Menu::SLUG_IMPORT ) );
		$this->table->display();
		echo '</form>';

		if ( $this->pollIds() !== array() ) {
			printf(
				'<p class="description" id="vidyalms-import-polling">%s</p>',
				esc_html__( 'An import is running in the background. This page refreshes itself while it does.', 'vidyalms' )
			);
		}
	}

	/**
	 * Validates the request, stores the upload and starts the job, then
	 * redirects so a refresh cannot replay it.
	 */
	/**
	 * Ends a run that stopped responding, or queues its source again, because
	 * the operator asked.
	 *
	 * The core refuses to end a run that is still writing, so a tab left open
	 * for an hour cannot close a job that recovered in the meantime. Nothing
	 * either action does removes what an import already created.
	 */
	private function handleRowAction(): void {
		$action = isset( $_GET['vidyalms_row_action'] ) && ! is_array( $_GET['vidyalms_row_action'] )
			? sanitize_key( wp_unslash( (string) $_GET['vidyalms_row_action'] ) )
			: '';

		if ( ! in_array( $action, array( self::ACTION_ABANDON, self::ACTION_RETRY ), true ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ROW_ACTION, '_vidyalms_nonce' );

		$kernel = Menu::kernel();
		$jobId  = isset( $_GET['vidyalms_job'] ) && ! is_array( $_GET['vidyalms_job'] )
			? absint( wp_unslash( (string) $_GET['vidyalms_job'] ) )
			: 0;

		if ( $kernel === null || $jobId <= 0 ) {
			Menu::queueNotice( 'error', __( 'The VidyaLMS engine is not available, so nothing was changed.', 'vidyalms' ) );
			$this->redirect();
		}

		try {
			$job = $kernel->getImportJob->execute( Menu::principal(), $jobId );

			// The queue runner's housekeeping deletes an uploaded source once
			// the retention window has passed and clears the path with it, so
			// a job old enough has nothing left to run. Saying so is the whole
			// point of clearing the path rather than leaving one that points
			// at bytes that are gone.
			if ( $action === self::ACTION_RETRY && ( $job->sourcePath === null || $job->sourcePath === '' ) ) {
				Menu::queueNotice(
					'error',
					__(
						'The uploaded file for this import has been removed by the retention housekeeping, so there is nothing to run again. Upload it again to start a new import.',
						'vidyalms'
					)
				);
				$this->redirect();
			}

			if ( ! $job->isFinished() ) {
				$job = $kernel->abandonImportJob->execute( Menu::principal(), $job->id );
			}

			if ( $action === self::ACTION_RETRY ) {
				$queued = $kernel->queueImportJob->execute(
					Menu::principal(),
					$job->importer,
					$job->sourcePath,
					$job->options
				);

				Menu::queueNotice(
					'success',
					sprintf(
						/* translators: %d: job id. */
						__( 'Import job %d is queued. It runs in the background; this screen follows it.', 'vidyalms' ),
						$queued->id
					)
				);
				$this->redirect();
			}

			Menu::queueNotice(
				'success',
				sprintf(
					/* translators: %d: job id. */
					__( 'Import job %d was marked failed. What it created before it stopped is still there.', 'vidyalms' ),
					$job->id
				)
			);
		} catch ( DomainException $refused ) {
			Menu::queueNotice( 'error', $refused->getMessage() );
		}

		$this->redirect();
	}

	private function handleStart(): void {
		$action = $this->requestedAction();

		if ( $action === '' ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$kernel = Menu::kernel();

		if ( $kernel === null ) {
			Menu::queueNotice( 'error', __( 'The VidyaLMS engine is not available, so nothing was started.', 'vidyalms' ) );
			$this->redirect();
		}

		$importerKey = isset( $_POST['importer'] ) && ! is_array( $_POST['importer'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['importer'] ) )
			: '';

		if ( ! $kernel->registries->importers->has( $importerKey ) ) {
			Menu::queueNotice( 'error', __( 'That importer is not registered on this site.', 'vidyalms' ) );
			$this->redirect();
		}

		try {
			$source = $this->resolveSource( $importerKey );
		} catch ( \RuntimeException $refused ) {
			Menu::queueNotice( 'error', $refused->getMessage() );
			$this->redirect();
		}

		$dryRun  = $action === 'preview';
		$options = $this->submittedOptions( $kernel->registries->importers, $importerKey );

		try {
			$job = $dryRun
				? $kernel->runImportJob->execute( Menu::principal(), $importerKey, $source, $options + array( 'dry_run' => true ) )
				: $kernel->queueImportJob->execute( Menu::principal(), $importerKey, $source, $options + array( 'dry_run' => false ) );
		} catch ( DomainException $refused ) {
			Menu::queueNotice( 'error', $refused->getMessage() );
			$this->redirect();
		}

		Menu::queueNotice(
			$job->status === ImportJob::STATUS_FAILED ? 'error' : 'success',
			$this->startedMessage( $job )
		);

		$this->redirect();
	}

	/**
	 * What the operator set on the options fieldset, read back through the
	 * importer's own declaration.
	 *
	 * The form posts strings, and an importer reading
	 * ( $options['enrolments'] ?? false ) === true would read the string "1" as
	 * false and quietly do the opposite of what was ticked. ImportOption::read()
	 * is in the core precisely so that this form and the Joomla one cannot mean
	 * two different things; "dry_run" is stripped there and decided here, by
	 * which button was pressed.
	 *
	 * @param ImporterRegistry $importers   The registry to resolve the declaration in.
	 * @param string           $importerKey The chosen importer.
	 *
	 * @return array<string, mixed>
	 */
	private function submittedOptions( ImporterRegistry $importers, string $importerKey ): array {
		// The nonce was checked by handleStart before anything read the post.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw = isset( $_POST['options'] ) && is_array( $_POST['options'] )
			// Import entry points verify the import nonce and manager capability before these helpers run.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			? map_deep( wp_unslash( $_POST['options'] ), 'sanitize_text_field' ) : array();

		$submitted = array();

		/** @var mixed $value */
		foreach ( $raw as $name => $value ) {
			$name = sanitize_key( (string) $name );

			if ( '' === $name || ! is_scalar( $value ) ) {
				continue;
			}

			$submitted[ $name ] = sanitize_text_field( (string) $value );
		}

		return ImportOption::read(
			array_values( ImportSources::declaredOptions( $importers, $importerKey ) ),
			$submitted
		);
	}

	/**
	 * A database importer is started with no file at all; every other source is
	 * an upload this adapter validates and stores before the job exists.
	 *
	 * @throws \RuntimeException When the source is not usable.
	 */
	private function resolveSource( string $importerKey ): string {
		$descriptor = null;

		foreach ( $this->importers() as $candidate ) {
			if ( $candidate['key'] === $importerKey ) {
				$descriptor = $candidate;

				break;
			}
		}

		if ( $descriptor === null ) {
			// Exception arguments are data; escape at the HTTP/template boundary.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( __( 'That importer is not registered on this site.', 'vidyalms' ) );
		}

		// Import entry points verify the import nonce and manager capability before these helpers run.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$mode = isset( $_POST['source_mode'] ) && ! is_array( $_POST['source_mode'] )
			// Import entry points verify the import nonce and manager capability before these helpers run.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			? sanitize_key( wp_unslash( (string) $_POST['source_mode'] ) )
			: 'file';

		// An importer with only one source shape has no choice to make, and a
		// browser with scripting off cannot hide the control that does not
		// apply. Taking the only shape it offers beats an error message that
		// asks for a file it would refuse.
		if ( ! $descriptor['accepts_file'] && $descriptor['accepts_database'] ) {
			$mode = 'database';
		}

		if ( $mode === 'database' ) {
			if ( ! $descriptor['accepts_database'] ) {
				// Exception arguments are data; escape at the HTTP/template boundary.
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new \RuntimeException( __( "That importer does not read this site's database, so it needs a file.", 'vidyalms' ) );
			}

			// Import entry points verify the import nonce and manager capability before these helpers run.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$selector = isset( $_POST['selector'] ) && ! is_array( $_POST['selector'] )
				// Import entry points verify the import nonce and manager capability before these helpers run.
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				? sanitize_text_field( wp_unslash( (string) $_POST['selector'] ) )
				: '';

			// The scheme the chosen importer answered to, not the literal
			// 'cms-db:': a LearnDash run composes 'learndash-db:'.
			return ImportSources::databaseSource( $selector, $descriptor['database_scheme'] );
		}

		return $this->storeUpload( $descriptor );
	}

	/**
	 * Everything is checked before a byte is written: the transport error, the
	 * size against the cap, and the extension against the allowlist the chosen
	 * importer narrowed. The stored name is rebuilt from the validated
	 * extension, so an uploaded "course.php.json" cannot become a PHP file.
	 *
	 * @param array{key: string, label: string, extensions: array<int, string>, accepts_file: bool, accepts_database: bool, database_scheme: string, source_installed: bool, options: array<int, array{key: string, label: string, kind: string, help: string, default: string, choices: array<int, array{value: string, label: string}>}>} $descriptor Chosen importer.
	 *
	 * @throws \RuntimeException When the upload is not usable.
	 */
	private function storeUpload( array $descriptor ): string {
		// Both entry points verify the import nonce and manager capability before calling this method.
		// This is file metadata, not text: validate its shape here, then prove is_uploaded_file,
		// bound actual bytes, inspect content, and use wp_handle_upload in private staging.
		// Import entry points verify the import nonce and manager capability before these helpers run.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$file = isset( $_FILES['import_file'] ) && is_array( $_FILES['import_file'] ) ? $_FILES['import_file'] : null;

		if ( $file === null || ! is_string( $file['name'] ?? null ) || ! is_string( $file['tmp_name'] ?? null )
			|| ! is_int( $file['error'] ?? null ) || ! is_int( $file['size'] ?? null ) ) {
			// Exception arguments are data; escape at the HTTP/template boundary.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( __( 'Choose a file to import.', 'vidyalms' ) );
		}

		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

		if ( $error !== UPLOAD_ERR_OK ) {
			// Exception arguments are data; escape at the HTTP/template boundary.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( self::uploadErrorMessage( $error ) );
		}

		$temporary = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';

		if ( $temporary === '' || ! is_uploaded_file( $temporary ) ) {
			// Exception arguments are data; escape at the HTTP/template boundary.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( __( 'Choose a file to import.', 'vidyalms' ) );
		}

		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
		$cap  = self::maxUploadBytes();

		if ( $size <= 0 ) {
			// Exception arguments are data; escape at the HTTP/template boundary.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new \RuntimeException( __( 'That file is empty.', 'vidyalms' ) );
		}

		if ( $size > $cap ) {
			throw new \RuntimeException(
				sprintf(
					// Exception arguments are data; escape at the HTTP/template boundary.
					/* translators: 1: uploaded size, 2: the limit. */
					__( 'That file is %1$s, and the limit is %2$s.', 'vidyalms' ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					// Exception arguments are data; escape at the HTTP/template boundary.
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					size_format( $size ),
					// Exception arguments are data; escape at the HTTP/template boundary.
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					size_format( $cap )
				)
			);
		}

		$name      = isset( $file['name'] ) ? (string) $file['name'] : '';
		$extension = ImportSources::extensionOf( $name );

		if ( $extension === '' || ! in_array( $extension, $descriptor['extensions'], true ) ) {
			throw new \RuntimeException(
				sprintf(
					// Exception arguments are data; escape at the HTTP/template boundary.
					/* translators: 1: the rejected extension, 2: the accepted list. */
					__( 'This importer does not read ".%1$s" files. It accepts: %2$s.', 'vidyalms' ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					// Exception arguments are data; escape at the HTTP/template boundary.
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					$extension !== '' ? $extension : __( 'none', 'vidyalms' ),
					// Exception arguments are data; escape at the HTTP/template boundary.
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					implode( ', ', $descriptor['extensions'] )
				)
			);
		}

		$contents = \Shondalai\VidyaLMS\WordPress\Files\WpUploadReceiver::read(
			$file,
			\Shondalai\VidyaLMS\Core\Upload\UploadPolicy::importSource( $descriptor['extensions'], $cap ),
			Plugin::storage(),
			$cap
		);

		$path = sprintf(
			'%s/%d/%s-%s',
			ImportSources::UPLOAD_FOLDER,
			max( 0, get_current_user_id() ),
			bin2hex( random_bytes( 16 ) ),
			ImportSources::safeFileName( $name, $extension )
		);

		Plugin::storage()->putUpload( $path, $contents, false, (int) get_current_user_id() );

		return $path;
	}

	private function startedMessage( ImportJob $job ): string {
		return match ( $job->status ) {
			ImportJob::STATUS_PREVIEWED => sprintf(
				/* translators: %d: job id. */
				__( 'Preview %d finished. Nothing was written.', 'vidyalms' ),
				$job->id
			),
			ImportJob::STATUS_COMPLETED => sprintf(
				/* translators: %d: job id. */
				__( 'Import job %d finished.', 'vidyalms' ),
				$job->id
			),
			ImportJob::STATUS_FAILED    => sprintf(
				/* translators: %d: job id. */
				__( 'Import job %d failed. What it created before the failure is still there; read the log, fix the source and run it again. If the source is a JSON document, give it the "key" the log names before you upload the corrected file, or the correction imports as a second course.', 'vidyalms' ),
				$job->id
			),
			default                     => sprintf(
				/* translators: %d: job id. */
				__( 'Import job %d is queued. It runs in the background; this screen follows it.', 'vidyalms' ),
				$job->id
			),
		};
	}

	/**
	 * @return string "run", "preview" or an empty string
	 */
	private function requestedAction(): string {
		// Read-only action selector; both import execution paths verify their nonce before doing any work.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST['vidyalms_import_action'] ) || is_array( $_POST['vidyalms_import_action'] ) ) {
			return '';
		}

		// Read-only action selector; both import execution paths verify their nonce before doing any work.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action = sanitize_key( wp_unslash( (string) $_POST['vidyalms_import_action'] ) );

		return in_array( $action, array( 'run', 'preview' ), true ) ? $action : '';
	}

	private static function uploadErrorMessage( int $error ): string {
		return match ( $error ) {
			UPLOAD_ERR_NO_FILE   => __( 'Choose a file to import.', 'vidyalms' ),
			UPLOAD_ERR_INI_SIZE,
			UPLOAD_ERR_FORM_SIZE => __( 'That file is larger than PHP accepts. Raise upload_max_filesize and post_max_size, or split the source.', 'vidyalms' ),
			UPLOAD_ERR_PARTIAL   => __( 'The upload was interrupted. Try again.', 'vidyalms' ),
			UPLOAD_ERR_NO_TMP_DIR,
			UPLOAD_ERR_CANT_WRITE,
			UPLOAD_ERR_EXTENSION => __( 'The server could not store the upload. Check the PHP temporary folder and its permissions.', 'vidyalms' ),
			default              => __( 'The upload failed.', 'vidyalms' ),
		};
	}

	private function redirect(): never {
		wp_safe_redirect( Menu::url( Menu::SLUG_IMPORT ) );
		exit;
	}
}
