<?php
/**
 * Power Agreement uninstall handler.
 *
 * Triggered by WordPress when the plugin is deleted via the admin UI.
 *
 * Note: Order metadata is intentionally preserved — it represents legal
 * evidence of consent and outlives the plugin's installation lifecycle.
 *
 * @package PowerAgreement
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'power_agreement_settings' );
