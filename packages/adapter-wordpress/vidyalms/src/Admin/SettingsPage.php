<?php

declare( strict_types=1 );

/**
 * The VidyaLMS settings screen.
 *
 * @package VidyaLMS
 */

namespace Shondalai\VidyaLMS\WordPress\Admin;


defined( 'ABSPATH' ) || exit;

/** Mount the settings SPA. Authorization and writes belong to the LMS API. */
final class SettingsPage {

    public function boot(): void {
        // Runtime settings are written only through the authenticated VidyaLMS API.
        add_action('admin_init', static function (): void {
            register_setting('vidyalms_uninstall', \Shondalai\VidyaLMS\WordPress\Setup\Uninstaller::DELETE_DATA_OPTION, [
                'type' => 'boolean',
                'default' => false,
                'sanitize_callback' => static fn ($value): bool => \in_array($value, [true, 1, '1'], true),
            ]);
        });
    }

	public function render(): void {
		// The same one gate every other VidyaLMS entry carries, and for the
		// same reason: GET /admin/settings decides who may read the engine's
		// configuration, and the app draws the permission state from that
		// 403. Gating the page on CAP_MANAGE instead put a second copy of the
		// rule here, and it was the wrong copy: the menu drew the entry for
		// anyone holding an operational capability and the page then refused
		// them. wp_die() cannot even answer 403 from here, because the admin
		// header has already been sent, so the refusal arrived as a 200
		// carrying an error page where the screen should have been.
		if ( ! current_user_can( Menu::CAP_ACCESS ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this VidyaLMS screen.', 'vidyalms' ),
				'',
				array( 'response' => 403 )
			);
		}

		echo '<div class="wrap vidyalms-wrap">';

		if ( Menu::kernel() !== null ) {
			AdminApps::renderScreen( 'settings' );
		} else {
			echo '<h1>' . esc_html__( 'VidyaLMS Settings', 'vidyalms' ) . '</h1>';
			Menu::renderKernelNotice();
		}

        if (current_user_can('manage_options')) {
            $option = \Shondalai\VidyaLMS\WordPress\Setup\Uninstaller::DELETE_DATA_OPTION;
            echo '<h2>' . esc_html__('Plugin removal', 'vidyalms') . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('options.php')) . '">';
            settings_fields('vidyalms_uninstall');
            echo '<input type="hidden" name="' . esc_attr($option) . '" value="0">';
            echo '<label><input type="checkbox" name="' . esc_attr($option) . '" value="1" '
                . checked(\in_array(get_option($option, false), [true, 1, '1'], true), true, false) . '> '
                . esc_html__('Delete this site’s VidyaLMS data and private files when the plugin is deleted.', 'vidyalms')
                . '</label><p>' . esc_html__('Leave unchecked to preserve courses, learner records and files for reinstallation. This choice applies only to this site.', 'vidyalms') . '</p>';
            submit_button(__('Save removal preference', 'vidyalms'));
            echo '</form>';
        }
        echo '</div>';
    }
}
