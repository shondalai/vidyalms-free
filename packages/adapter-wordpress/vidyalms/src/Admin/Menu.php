<?php

declare( strict_types=1 );

/**
 * The VidyaLMS wp-admin menu and the services its screens share.
 *
 * @package VidyaLMS
 */

namespace Shondalai\VidyaLMS\WordPress\Admin;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\WordPress\Content\Rewrites;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the top level menu, owns the admin screen objects and resolves the
 * three things every screen needs: the kernel, the read-only database port and
 * the caller as a core Principal.
 *
 * Screens are gated on the VidyaLMS capabilities, never on manage_options, so
 * a site owner can grant vidyalms_teach or vidyalms_reports to an editor role
 * and delegate part of the LMS without handing over the whole site.
 */
final class Menu {

	public const SLUG_ROOT          = 'vidyalms';
	/** Pages stay registered under an undrawn parent; the workspace owns navigation. */
	public const SLUG_PAGES         = 'vidyalms-pages';
	public const SLUG_COURSES       = 'vidyalms-courses';
	public const SLUG_COURSE        = 'vidyalms-course';
	public const SLUG_ENROLMENTS    = 'vidyalms-enrolments';
	public const SLUG_LEARNERS      = 'vidyalms-learners';
	public const SLUG_CERTIFICATES  = 'vidyalms-certificates';
	public const SLUG_NOTIFICATIONS = 'vidyalms-notifications';
	public const SLUG_EMAIL_TEMPLATES = 'vidyalms-email-templates';
	public const SLUG_AUTOMATIONS   = 'vidyalms-automations';
	public const SLUG_ORGANISATIONS = 'vidyalms-organisations';
	public const SLUG_REPORTS = 'vidyalms-reports';
	public const SLUG_OPERATIONS = 'vidyalms-operations';

	/** The AI screen: enablement, the stop, the policy, the runs and the spend. */
	public const SLUG_AI = 'vidyalms-ai';
	public const SLUG_IMPORT_CENTER = 'vidyalms-import-center';
	public const CAP_ORGANISATIONS = 'vidyalms_access_organisations';
	public const SLUG_IMPORT        = 'vidyalms-import';
	public const SLUG_SETTINGS      = 'vidyalms-settings';

	public const CAP_MANAGE  = 'vidyalms_manage';
	public const CAP_TEACH   = 'vidyalms_teach';
	public const CAP_REPORTS = 'vidyalms_reports';


	/**
	 * The meta capability the top level entry is drawn with: holding any one
	 * of the operational capabilities. A person who only teaches holds
	 * vidyalms_teach and not vidyalms_manage, and gating the parent on manage
	 * would hide the whole menu from them, workbench included. Mapped in
	 * mapAccess(), never granted to a role.
	 */
	public const CAP_ACCESS = 'vidyalms_access';

	/**
	 * The capabilities CAP_ACCESS stands for.
	 *
	 * @var array<int, string>
	 */
	private const OPERATIONAL_CAPS = array(
		self::CAP_MANAGE,
		self::CAP_TEACH,
		'vidyalms_grade',
		'vidyalms_enrol',
		self::CAP_REPORTS,
		'vidyalms_manage_groups',
	);

	/**
	 * Per user screen options. WordPress saves them before admin_init runs, so
	 * their filters are registered at plugin load; naming them here keeps the
	 * list table classes out of every admin request.
	 */
	public const IMPORT_JOBS_PER_PAGE = 'vidyalms_import_jobs_per_page';

	/**
	 * The integration seam with the plugin bootstrap. A Plugin class exposing
	 * kernel() and database() is used when it is loaded; otherwise the two
	 * filters are asked, so the admin never constructs a kernel of its own.
	 */
	public const KERNEL_FILTER   = 'vidyalms_kernel';
	public const DATABASE_FILTER = 'vidyalms_database';

	private const CONTAINER        = 'Shondalai\\VidyaLMS\\WordPress\\Plugin';
	private const NOTICE_TRANSIENT = 'vidyalms_admin_notices_';
	private const HANDLE           = 'vidyalms-admin';

	/**
	 * Menu slug to the screen hook suffix WordPress assigned it.
	 *
	 * @var array<string, string>
	 */
	private array $hooks = array();

	/**
	 * Every screen the generic admin page serves, including contributed ones,
	 * keyed by its native WordPress slug. Settings has a page of its own because
	 * the WordPress settings form lives below that screen's mount.
	 *
	 * @var array<string, ScreenPage>
	 */
	private array $screens = array();

	/**
	 * The immutable screen catalogue captured for this menu registration.
	 *
	 * @var list<AdminScreen>
	 */
	private array $screenDefinitions = array();

	private WorkbenchPage $workbench;

	private ImportPage $import;

	private SettingsPage $settings;

	public function __construct() {
		$this->screenDefinitions = AdminScreens::all();

		foreach ( $this->screenDefinitions as $definition ) {
			// Settings owns a WordPress form below its mount, so its native
			// renderer remains SettingsPage while its URL and menu metadata live
			// in the same catalogue as every other admin SPA screen.
			if ( $definition->slug === self::SLUG_SETTINGS ) {
				continue;
			}

			$this->screens[ $definition->slug ] = new ScreenPage(
				$definition->key,
				$definition->capability,
				$definition->pageTitle
			);
		}

		$this->workbench = new WorkbenchPage();
		$this->import    = new ImportPage();
		$this->settings  = new SettingsPage();
	}

	/**
	 * Hooks the admin interface up. The plugin bootstrap calls this once, from
	 * an is_admin() branch.
	 */
	public static function boot(): self {
		$menu = new self();

		add_action( 'admin_menu', array( $menu, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $menu, 'enqueue' ) );
		add_filter( 'map_meta_cap', array( $menu, 'mapAccess' ), 10, 3 );
		add_filter( 'set_screen_option_' . self::IMPORT_JOBS_PER_PAGE, array( $menu, 'saveScreenOption' ), 10, 3 );

		// admin-ajax.php is an admin request, so the poll endpoint is
		// registered here with the rest of the admin surface. It checks the
		// capability and the nonce itself.
		add_action( 'wp_ajax_' . ImportPage::AJAX_ACTION, array( $menu->import, 'ajaxPoll' ) );
		add_action( 'wp_ajax_vidyalms_import_workspace', array( $menu->import, 'ajaxWorkspace' ) );

		$menu->settings->boot();

		return $menu;
	}

	/**
	 * Builds the menu.
	 *
	 * Every VidyaLMS entry carries CAP_ACCESS, holding any one of the
	 * operational capabilities, and the routes behind each screen decide the
	 * rest. Two reasons, and neither is laziness.
	 *
	 * The first is that a per-entry capability was wrong in both directions:
	 * Dashboard was gated on vidyalms_manage while GET /admin/dashboard
	 * requires reports, so a reports role could not open the one screen it was
	 * entitled to, and Enrolments, Learners and Certificates were gated on
	 * vidyalms_reports while their routes require manage, so a reports role
	 * saw three entries whose every request answered 403. Nothing makes
	 * vidyalms_manage imply vidyalms_reports, so the two sets never met.
	 *
	 * The second is parity. Joomla's admin menu has no per-entry ACL at all:
	 * mod_menu filters every component entry on core.manage of the component
	 * asset, and the installer ignores anything else on a <menu> element. One
	 * gate for the whole group is therefore the only answer both hosts can
	 * give, and it is the answer the apps are built for: a refused route
	 * renders the designed permission state, not a broken screen.
	 */
	public function register(): void {
		$this->hooks = array();

		$this->hooks[ self::SLUG_ROOT ] = add_menu_page(
			__( 'VidyaLMS', 'vidyalms' ),
			__( 'VidyaLMS', 'vidyalms' ),
			self::CAP_ACCESS,
			self::SLUG_ROOT,
			array( $this->screens[ self::SLUG_ROOT ], 'render' ),
			'dashicons-welcome-learn-more',
			26
		);

		// The root is the only visible WordPress entry. All other pages, including
		// provider contributions, retain their capability, renderer and asset hooks
		// under an undrawn parent so workspace links remain directly addressable.
		foreach ( $this->screenDefinitions as $definition ) {
			if ( $definition->slug === self::SLUG_ROOT || $definition->slug === self::SLUG_SETTINGS ) {
				continue;
			}

			$this->submenu(
				$definition->slug,
				$definition->pageTitle,
				$definition->menuTitle,
				$definition->capability,
				array( $this->screens[ $definition->slug ], 'render' )
			);
		}

		$import = $this->submenu(
			self::SLUG_IMPORT,
			__( 'VidyaLMS Import', 'vidyalms' ),
			__( 'Import', 'vidyalms' ),
			self::CAP_ACCESS,
			array( $this->import, 'render' )
		);

		if ( $import !== '' ) {
			add_action( 'load-' . $import, array( $this->import, 'load' ) );
		}

		$this->submenu(
			self::SLUG_SETTINGS,
			__( 'VidyaLMS Settings', 'vidyalms' ),
			__( 'Settings', 'vidyalms' ),
			self::CAP_ACCESS,
			array( $this->settings, 'render' )
		);

		// Workbench links use the same hidden registration as the other pages.
		$this->submenu(
			self::SLUG_COURSE,
			__( 'Course workbench', 'vidyalms' ),
			__( 'Course workbench', 'vidyalms' ),
			self::CAP_ACCESS,
			array( $this->workbench, 'render' )
		);
	}

	/**
	 * Answers CAP_ACCESS: holding any one of the operational capabilities.
	 * Never granted to a role, so it can only ever be as wide as the five it
	 * stands for.
	 *
	 * @param array<int, string> $caps    Required primitive capabilities.
	 * @param string             $cap     Capability being checked.
	 * @param int                $userId  User being checked.
	 *
	 * @return array<int, string>
	 */
	public function mapAccess( $caps, $cap, $userId ) {
		if ( $cap !== self::CAP_ACCESS && $cap !== self::CAP_ORGANISATIONS ) {
			return $caps;
		}

		$user = get_userdata( (int) $userId );

		if ( ! $user instanceof \WP_User ) {
			return array( 'do_not_allow' );
		}

		if ( user_can( $user, 'manage_options' ) || is_super_admin( (int) $userId ) ) {
			return array( 'exist' );
		}

		$operationalCaps = $cap === self::CAP_ORGANISATIONS
			? array( self::CAP_MANAGE, 'vidyalms_manage_groups', 'manage_options', 'vidyalms_admin' )
			: self::OPERATIONAL_CAPS;
		foreach ( $operationalCaps as $operational ) {
			if ( user_can( $user, $operational ) ) {
				return array( 'exist' );
			}
		}

		return array( 'do_not_allow' );
	}

	/**
	 * Loads the admin stylesheet and script on VidyaLMS screens only, plus the
	 * React bundle the screen mounts.
	 */
	public function enqueue( string $hookSuffix ): void {
		if ( ! in_array( $hookSuffix, $this->hooks, true ) ) {
			return;
		}

		$version = self::version();

		// The stylesheet dresses the page frame and the Import screen. There
		// is no admin script any more: the media picker, the slug helper, the
		// completion threshold toggle and the archive confirmation belonged to
		// the PHP course form and the PHP course list, and both are React now.
		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/css/admin.css', self::pluginFile() ), array(), $version );

		if ( ( $this->hooks[ self::SLUG_COURSE ] ?? '' ) === $hookSuffix ) {
			AdminApps::enqueue( AdminApps::WORKBENCH_HANDLE, 'instructor' );
		} elseif ( $this->mountsAdminApp( $hookSuffix ) ) {
			AdminApps::enqueue( AdminApps::ADMIN_HANDLE, 'admin' );
		}

		if ( ( $this->hooks[ self::SLUG_IMPORT ] ?? '' ) === $hookSuffix ) {
			$this->enqueueImport( $version );
		}
	}

	/**
	 * Whether this screen renders the admin bundle: every registered generic
	 * page plus Settings, which carries the mount above the WordPress form.
	 */
	private function mountsAdminApp( string $hookSuffix ): bool {
		foreach ( array_merge( array_keys( $this->screens ), array( self::SLUG_SETTINGS ) ) as $slug ) {
			if ( ( $this->hooks[ $slug ] ?? '' ) === $hookSuffix ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The import screen's own script. It carries the poll endpoint and its
	 * nonce; which jobs to watch is read from the rows themselves, because
	 * admin_enqueue_scripts runs before the list table has queried anything.
	 *
	 * @param string $version Asset version.
	 */
	private function enqueueImport( string $version ): void {
		$handle = self::HANDLE . '-import';

		wp_enqueue_script( $handle, plugins_url( 'assets/js/import.js', self::pluginFile() ), array(), $version, true );

		wp_localize_script(
			$handle,
			'vidyalmsImport',
			array(
				'endpoint'       => admin_url( 'admin-ajax.php' ),
				'action'         => ImportPage::AJAX_ACTION,
				'nonce'          => wp_create_nonce( ImportPage::NONCE_ACTION ),
				'interval'       => '4000',
				/* translators: %d: number of imported items needing attention. */
				'troubleHeading' => __( '%d items need your attention', 'vidyalms' ),
			)
		);
	}

	/**
	 * Screen options are per user; WordPress passes the raw value through.
	 *
	 * @param mixed  $status Value returned by an earlier filter.
	 * @param string $option Option name being saved.
	 * @param mixed  $value  Submitted value.
	 *
	 * @return mixed
	 */
	public function saveScreenOption( $status, $option, $value ) {
		$perPage = is_scalar( $value ) ? (int) $value : 0;

		return ( $perPage >= 1 && $perPage <= 200 ) ? $perPage : $status;
	}

	/**
	 * The shared kernel, or null when the plugin failed to boot one.
	 */
	public static function kernel(): ?CoreKernel {
		if ( class_exists( self::CONTAINER ) && method_exists( self::CONTAINER, 'kernel' ) ) {
			try {
				/** @var mixed $resolved */
				$resolved = call_user_func( array( self::CONTAINER, 'kernel' ) );

				if ( $resolved instanceof CoreKernel ) {
					return $resolved;
				}
			} catch ( \Throwable $e ) {
				return null;
			}
		}

		/** @var mixed $filtered */
		$filtered = apply_filters( self::KERNEL_FILTER, null );

		return $filtered instanceof CoreKernel ? $filtered : null;
	}

	/**
	 * Read access to the LMS tables for the list screens. Writes never come
	 * through here: they go through the kernel's use cases.
	 */
	public static function database(): ?DatabasePort {
		if ( class_exists( self::CONTAINER ) && method_exists( self::CONTAINER, 'database' ) ) {
			try {
				/** @var mixed $resolved */
				$resolved = call_user_func( array( self::CONTAINER, 'database' ) );

				if ( $resolved instanceof DatabasePort ) {
					return $resolved;
				}
			} catch ( \Throwable $e ) {
				return null;
			}
		}

		/** @var mixed $filtered */
		$filtered = apply_filters( self::DATABASE_FILTER, null );

		return $filtered instanceof DatabasePort ? $filtered : null;
	}

	/**
	 * The current WordPress identity as the core sees it.
	 */
	public static function principal(): Principal {
		$userId = get_current_user_id();

		return $userId > 0 ? Principal::user( $userId ) : Principal::guest();
	}

	/**
	 * Asks the core guard, not the WordPress capability alone, so a screen
	 * shows only what the use cases would actually allow.
	 */
	public static function can( string $capability ): bool {
		$kernel = self::kernel();

		return $kernel !== null && $kernel->guard->can( self::principal(), $capability );
	}

	/**
	 * @param array<string, int|string> $args
	 */
	public static function url( string $slug, array $args = array() ): string {
		$url = admin_url( 'admin.php?page=' . rawurlencode( $slug ) );

		return $args === array() ? $url : add_query_arg( $args, $url );
	}

	public static function renderKernelNotice(): void {
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'The VidyaLMS engine is not available. Deactivate and activate the plugin, and check the site error log.', 'vidyalms' )
		);
	}

	/**
	 * Carries a message across the redirect that follows a write.
	 */
	public static function queueNotice( string $type, string $message ): void {
		$userId = get_current_user_id();

		if ( $userId <= 0 ) {
			return;
		}

		/** @var mixed $stored */
		$stored  = get_transient( self::NOTICE_TRANSIENT . $userId );
		$notices = is_array( $stored ) ? $stored : array();

		$notices[] = array(
			'type'    => $type,
			'message' => $message,
		);

		set_transient( self::NOTICE_TRANSIENT . $userId, array_slice( $notices, -20 ), MINUTE_IN_SECONDS );
	}

	public static function renderNotices(): void {

		$userId = get_current_user_id();

		if ( $userId <= 0 ) {
			return;
		}

		$key = self::NOTICE_TRANSIENT . $userId;

		/** @var mixed $stored */
		$stored = get_transient( $key );

		if ( ! is_array( $stored ) || $stored === array() ) {
			return;
		}

		delete_transient( $key );

		foreach ( $stored as $notice ) {
			if ( ! is_array( $notice ) ) {
				continue;
			}

			$type    = is_string( $notice['type'] ?? null ) ? $notice['type'] : 'info';
			$message = is_string( $notice['message'] ?? null ) ? $notice['message'] : '';

			if ( $message === '' ) {
				continue;
			}

			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( in_array( $type, array( 'success', 'error', 'warning', 'info' ), true ) ? $type : 'info' ),
				esc_html( $message )
			);
		}
	}

	/**
	 * The core stores UTC "Y-m-d H:i:s"; the reader sees site time.
	 */
	public static function formatDate( string $utc ): string {
		$utc = trim( $utc );

		if ( $utc === '' || str_starts_with( $utc, '0000-00-00' ) ) {
			return '';
		}

		$timestamp = strtotime( $utc . ' UTC' );

		if ( $timestamp === false ) {
			return '';
		}

		$format = get_option( 'date_format' );

		return (string) wp_date( is_string( $format ) && $format !== '' ? $format : 'Y-m-d', $timestamp );
	}

	/**
	 * The main plugin file, used only to resolve asset URLs.
	 */
	public static function pluginFile(): string {
		if ( defined( 'VIDYALMS_PLUGIN_FILE' ) && is_string( constant( 'VIDYALMS_PLUGIN_FILE' ) ) ) {
			return (string) constant( 'VIDYALMS_PLUGIN_FILE' );
		}

		return dirname( __DIR__, 2 ) . '/vidyalms.php';
	}

	private static function version(): string {
		if ( defined( 'VIDYALMS_VERSION' ) && is_string( constant( 'VIDYALMS_VERSION' ) ) ) {
			return (string) constant( 'VIDYALMS_VERSION' );
		}

		return '1.0.0';
	}

	/**
	 * @param callable|string $callback Screen renderer, or an empty string for
	 *                                   an entry that links to a screen this
	 *                                   plugin does not own.
	 * @param string          $parent   Slug to register under; a slug that is
	 *                                   not a top level menu registers an
	 *                                   addressable page WordPress never draws.
	 *
	 * @return string the hook suffix, or an empty string when the user may not see the page
	 */
	private function submenu(
		string $slug,
		string $pageTitle,
		string $menuTitle,
		string $capability,
		$callback,
		string $parent = self::SLUG_PAGES
	): string {
		$hook = add_submenu_page( $parent, $pageTitle, $menuTitle, $capability, $slug, $callback );

		if ( ! is_string( $hook ) || $hook === '' ) {
			return '';
		}

		$this->hooks[ $slug ] = $hook;

		return $hook;
	}
}
