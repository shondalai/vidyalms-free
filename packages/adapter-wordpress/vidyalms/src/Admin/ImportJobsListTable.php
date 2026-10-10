<?php

declare( strict_types=1 );

/**
 * The import history list table.
 *
 * @package VidyaLMS
 */

namespace Shondalai\VidyaLMS\WordPress\Admin;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainException;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Read only, and newest first, which is the only order the core's
 * ListImportJobs use case answers. There is no bulk action and no delete: a
 * finished import is the record of what happened to a site's data, and the
 * platform's retention sweep is what removes it.
 *
 * Every row carries its own log, because that is where an importer says what it
 * could not bring across. Warning and error notes are lifted out of the log and
 * shown above it, so a migrating customer does not have to read two hundred
 * lines to find the six that matter.
 */
final class ImportJobsListTable extends \WP_List_Table {

	public const PER_PAGE_OPTION = Menu::IMPORT_JOBS_PER_PAGE;

	/**
	 * The log is shown inline, and a very long one would push the list off the
	 * screen. The rest is still in the job; only the display is trimmed, and it
	 * says so.
	 */
	private const LOG_LINES = 60;

	private const TROUBLE_LINES = 10;

	private bool $authorised;

	/**
	 * @var array<int, int>
	 */
	private array $unfinished = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'vidyalms_import_job',
				'plural'   => 'vidyalms_import_jobs',
				'ajax'     => false,
			)
		);

		$this->authorised = Menu::can( Capability::MANAGE );
	}

	/**
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'status'   => __( 'Status', 'vidyalms' ),
			'job'      => __( 'Importer and source', 'vidyalms' ),
			'totals'   => __( 'Brought across', 'vidyalms' ),
			'finished' => __( 'Finished', 'vidyalms' ),
		);
	}

	public function prepare_items(): void {
		$this->items      = array();
		$this->unfinished = array();

		$kernel = Menu::kernel();

		if ( ! $this->authorised || $kernel === null ) {
			return;
		}

		$perPage = $this->get_items_per_page( self::PER_PAGE_OPTION, 20 );

		try {
			$page = $kernel->listImportJobs->execute( Menu::principal(), $this->get_pagenum(), $perPage );
		} catch ( DomainException $refused ) {
			return;
		}

		foreach ( $page->items as $job ) {
			$this->items[] = $job;

			// A run whose heartbeat stopped is not moving and never will:
			// polling it is what left the old screen refreshing a dead job for
			// ever. It is shown as stopped responding instead.
			if ( ! $job->isFinished() && ! ImportPage::hasStalled( $job ) ) {
				$this->unfinished[] = $job->id;
			}
		}

		$this->set_pagination_args(
			array(
				'total_items' => $page->total,
				'per_page'    => $page->perPage,
			)
		);
	}

	/**
	 * @return array<int, int>
	 */
	public function unfinishedIds(): array {
		return $this->unfinished;
	}

	public function no_items(): void {
		if ( ! $this->authorised ) {
			esc_html_e( 'You do not have permission to read import jobs.', 'vidyalms' );

			return;
		}

		esc_html_e( 'Nothing has been imported yet.', 'vidyalms' );
	}

	/**
	 * The row carries its job id and whether it is still moving, which is how
	 * the poll script decides what to watch: admin_enqueue_scripts runs before
	 * this table has queried anything, so the ids cannot travel in the script's
	 * configuration.
	 *
	 * @param ImportJob $item The job.
	 */
	public function single_row( $item ): void {
		printf(
			'<tr data-vidyalms-job="%1$d" data-vidyalms-pending="%2$d">',
			(int) $item->id,
			$item->isFinished() || ImportPage::hasStalled( $item ) ? 0 : 1
		);
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * @param ImportJob $item       The job.
	 * @param string    $columnName Column being rendered.
	 */
	protected function column_default( $item, $columnName ) {
		switch ( $columnName ) {
			case 'status':
				$stalled  = ImportPage::hasStalled( $item );
				$rendered = sprintf(
					'<span class="vidyalms-pill %1$s" data-vidyalms-status>%2$s</span>',
					esc_attr( ImportPage::statusClass( $item->status, $stalled ) ),
					esc_html( ImportPage::statusLabel( $item->status, $stalled ) )
				);

				if ( $item->isDryRun() ) {
					$rendered .= sprintf(
						'<div class="vidyalms-row-meta">%s</div>',
						esc_html__( 'Preview only', 'vidyalms' )
					);
				}

				return $rendered . sprintf( '<div class="vidyalms-row-meta">#%d</div>', (int) $item->id );

			case 'job':
				return $this->renderJob( $item );

			case 'totals':
				return $this->renderTotals( $item );

			case 'finished':
				$finished = $item->finishedAt !== null
					? Menu::formatDate( $item->finishedAt->format( 'Y-m-d H:i:s' ) )
					: '';

				return sprintf(
					'<span data-vidyalms-finished>%s</span>',
					$finished !== ''
						? esc_html( $finished )
						: '<span class="vidyalms-muted">' . esc_html__( 'Not yet', 'vidyalms' ) . '</span>'
				);
		}

		return '';
	}

	/**
	 * @param ImportJob $job The job.
	 */
	private function renderJob( ImportJob $job ): string {
		$rendered = sprintf( '<strong>%s</strong>', esc_html( $job->importer ) );

		$rendered .= sprintf(
			'<div class="vidyalms-row-meta">%s</div>',
			esc_html( $job->sourcePath !== null && $job->sourcePath !== '' ? $job->sourcePath : __( 'No source recorded', 'vidyalms' ) )
		);

		$rendered .= '<div data-vidyalms-trouble>' . $this->renderTrouble( $job ) . '</div>';
		$rendered .= $this->renderStalled( $job );

		$log   = (string) ( $job->log ?? '' );
		$lines = $log === '' ? array() : explode( "\n", $log );
		$shown = array_slice( $lines, -self::LOG_LINES );

		if ( count( $lines ) > count( $shown ) ) {
			array_unshift(
				$shown,
				sprintf(
					/* translators: %d: number of earlier log lines not shown. */
					__( '... %d earlier lines not shown ...', 'vidyalms' ),
					count( $lines ) - count( $shown )
				)
			);
		}

		$rendered .= sprintf(
			'<details%1$s><summary>%2$s</summary><pre class="vidyalms-import-log" data-vidyalms-log>%3$s</pre></details>',
			$job->isFinished() ? '' : ' open',
			esc_html__( 'Log', 'vidyalms' ),
			esc_html( implode( "\n", $shown ) )
		);

		return $rendered;
	}

	/**
	 * A run whose heartbeat stopped, with the time it last wrote and the two
	 * ways out of it.
	 *
	 * The block is rendered for every unfinished job and hidden until it is
	 * needed, so the poll script can reveal it without inventing the links: a
	 * nonce is not something a script may mint, and neither action is one a
	 * script may take on its own.
	 *
	 * @param ImportJob $job The job.
	 */
	private function renderStalled( ImportJob $job ): string {
		if ( $job->isFinished() ) {
			return '';
		}

		$stalled   = ImportPage::hasStalled( $job );
		$heartbeat = $job->heartbeatAt();

		return sprintf(
			'<div class="notice notice-warning inline vidyalms-import-stalled" data-vidyalms-stalled%1$s>'
			. '<p><strong>%2$s</strong></p><p>%3$s</p><p>%4$s <span data-vidyalms-heartbeat>%5$s</span></p>'
			. '<p><a class="button" href="%6$s">%7$s</a> <a class="button button-primary" href="%8$s">%9$s</a></p></div>',
			$stalled ? '' : ' style="display:none"',
			esc_html__( 'This run has stopped responding.', 'vidyalms' ),
			esc_html__(
				'It is still recorded as running, and it has written nothing for a quarter of an hour, so its process was probably killed. Nothing changes it by itself: mark it failed to close it, or run the same source again, which updates whatever it already created instead of duplicating it.',
				'vidyalms'
			),
			esc_html__( 'Last wrote:', 'vidyalms' ),
			esc_html( ImportPage::formatMoment( $heartbeat ) ),
			esc_url( ImportPage::rowActionUrl( ImportPage::ACTION_ABANDON, $job->id ) ),
			esc_html__( 'Mark it failed', 'vidyalms' ),
			esc_url( ImportPage::rowActionUrl( ImportPage::ACTION_RETRY, $job->id ) ),
			esc_html__( 'Run the same source again', 'vidyalms' )
		);
	}

	/**
	 * @param ImportJob $job The job.
	 */
	private function renderTrouble( ImportJob $job ): string {
		$trouble = ImportPage::troubleLines( $job->log );

		if ( $trouble === array() ) {
			return '';
		}

		$rendered = '<div class="notice notice-warning inline vidyalms-import-trouble"><p><strong>'
			. esc_html(
				sprintf(
					/* translators: %d: number of warning and error notes. */
					__( '%d items need your attention', 'vidyalms' ),
					count( $trouble )
				)
			)
			. '</strong></p><ul>';

		foreach ( array_slice( $trouble, 0, self::TROUBLE_LINES ) as $note ) {
			$rendered .= sprintf(
				'<li><strong>%1$s</strong> %2$s</li>',
				esc_html( ImportPage::noteLabel( $note->level ) ),
				esc_html( $note->message )
			);
		}

		return $rendered . '</ul></div>';
	}

	/**
	 * @param ImportJob $job The job.
	 */
	private function renderTotals( ImportJob $job ): string {
		$totals = ImportPage::orderedTotals( $job->totals );

		if ( $totals === array() ) {
			return '<span class="vidyalms-muted" data-vidyalms-totals>-</span>';
		}

		$rendered = '<span data-vidyalms-totals>';

		foreach ( $totals as $key => $count ) {
			$rendered .= sprintf(
				'<span class="vidyalms-pill vidyalms-pill-draft">%1$s: %2$s</span> ',
				esc_html( ImportPage::totalLabel( $key ) ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		return $rendered . '</span>';
	}
}
