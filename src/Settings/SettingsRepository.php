<?php
/**
 * Settings repository for Power Agreement.
 *
 * @package PowerAgreement\Settings
 */

declare(strict_types=1);

namespace PowerAgreement\Settings;

/**
 * Single source of truth for the plugin's persisted settings.
 *
 * Centralises:
 * - the option key
 * - the canonical default values (resolved at read-time so __() picks the
 *   current locale)
 * - the sanitisation / truncation rules used by Settings API & tests alike
 *
 * The repository deliberately exposes typed getters so callers cannot
 * accidentally read the wrong shape from `get_option()`.
 */
final class SettingsRepository {

	public const OPTION = 'power_agreement_settings';

	public const DISPLAY_MODE_ACCORDION     = 'accordion';
	public const DISPLAY_MODE_INLINE_SCROLL = 'inline_scroll';

	public const BUTTON_COLOR_DEFAULT = '#1f2937';

	/**
	 * Allowed values for the display_mode setting.
	 *
	 * @return list<string>
	 */
	public static function displayModes(): array {
		return array(
			self::DISPLAY_MODE_INLINE_SCROLL,
			self::DISPLAY_MODE_ACCORDION,
		);
	}

	private const TITLE_MAX_LEN        = 100;
	private const CONSENT_TEXT_MAX_LEN = 200;

	/**
	 * @return array{enabled: bool, title: string, content: string, consent_text: string, display_mode: string, button_color: string}
	 */
	public static function defaults(): array {
		return array(
			'enabled'      => false,
			'title'        => __( 'Agreement', 'power-agreement' ),
			'content'      => '',
			'consent_text' => __( 'I have read and agree to the agreement above.', 'power-agreement' ),
			'display_mode' => self::DISPLAY_MODE_INLINE_SCROLL,
			'button_color' => self::BUTTON_COLOR_DEFAULT,
		);
	}

	/**
	 * @return array{enabled: bool, title: string, content: string, consent_text: string, display_mode: string, button_color: string}
	 */
	public function settings(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$merged = wp_parse_args( $stored, self::defaults() );

		// Normalise types — older payloads might have stringy bools.
		$merged['enabled']      = (bool) $merged['enabled'];
		$merged['title']        = (string) $merged['title'];
		$merged['content']      = (string) $merged['content'];
		$merged['consent_text'] = (string) $merged['consent_text'];
		$merged['display_mode'] = self::normaliseDisplayMode( (string) $merged['display_mode'] );
		$merged['button_color'] = self::normaliseHexColor( (string) $merged['button_color'] );

		return $merged;
	}

	public function isEnabled(): bool {
		return $this->settings()['enabled'];
	}

	public function title(): string {
		return $this->settings()['title'];
	}

	public function content(): string {
		return $this->settings()['content'];
	}

	public function consentText(): string {
		return $this->settings()['consent_text'];
	}

	public function displayMode(): string {
		return $this->settings()['display_mode'];
	}

	public function buttonColor(): string {
		return $this->settings()['button_color'];
	}

	/**
	 * Persist a sanitised payload.
	 *
	 * @param array<string, mixed> $raw Untrusted input from admin form / API.
	 *
	 * @return bool Whether the option was actually written (false if value
	 *              was unchanged — same semantics as update_option()).
	 */
	public function save( array $raw ): bool {
		return (bool) update_option( self::OPTION, $this->sanitize( $raw ) );
	}

	/**
	 * Apply field-by-field cleansing rules.
	 *
	 * @param array<string, mixed> $raw
	 * @return array{enabled: bool, title: string, content: string, consent_text: string, display_mode: string, button_color: string}
	 */
	public function sanitize( array $raw ): array {
		$defaults = self::defaults();

		$enabled = array_key_exists( 'enabled', $raw )
			? self::toBool( $raw['enabled'] )
			: $defaults['enabled'];

		$title = array_key_exists( 'title', $raw )
			? sanitize_text_field( (string) $raw['title'] )
			: $defaults['title'];
		$title = mb_substr( $title, 0, self::TITLE_MAX_LEN );

		$content = array_key_exists( 'content', $raw )
			? wp_kses_post( (string) $raw['content'] )
			: $defaults['content'];

		$consent_text = array_key_exists( 'consent_text', $raw )
			? sanitize_text_field( (string) $raw['consent_text'] )
			: $defaults['consent_text'];
		$consent_text = mb_substr( $consent_text, 0, self::CONSENT_TEXT_MAX_LEN );

		$display_mode = array_key_exists( 'display_mode', $raw )
			? self::normaliseDisplayMode( (string) $raw['display_mode'] )
			: $defaults['display_mode'];

		$button_color = array_key_exists( 'button_color', $raw )
			? self::normaliseHexColor( (string) $raw['button_color'] )
			: $defaults['button_color'];

		return array(
			'enabled'      => $enabled,
			'title'        => $title,
			'content'      => $content,
			'consent_text' => $consent_text,
			'display_mode' => $display_mode,
			'button_color' => $button_color,
		);
	}

	/**
	 * Reduce a value to a valid 3- or 6-digit hex colour, falling back
	 * to the default when WordPress's `sanitize_hex_color` rejects it.
	 */
	private static function normaliseHexColor( string $color ): string {
		if ( function_exists( 'sanitize_hex_color' ) ) {
			$clean = sanitize_hex_color( $color );
		} elseif ( preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', $color ) ) {
			$clean = $color;
		} else {
			$clean = null;
		}
		return ( null === $clean || '' === $clean ) ? self::BUTTON_COLOR_DEFAULT : $clean;
	}

	/**
	 * Reduce any value to a known display mode, defaulting to the inline-scroll
	 * preview when the input does not match one of the allowed modes.
	 */
	private static function normaliseDisplayMode( string $mode ): string {
		return in_array( $mode, self::displayModes(), true )
			? $mode
			: self::DISPLAY_MODE_INLINE_SCROLL;
	}

	/**
	 * Cast common truthy/falsy representations to bool.
	 *
	 * Mirrors {@see rest_sanitize_boolean()} but without WP_Error semantics
	 * so PHPStan can narrow the result.
	 *
	 * @param mixed $value
	 */
	private static function toBool( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			$lower = strtolower( trim( $value ) );
			if ( in_array( $lower, array( 'false', '0', 'no', 'off', '' ), true ) ) {
				return false;
			}
		}
		if ( 0 === $value || '0' === $value ) {
			return false;
		}
		return (bool) $value;
	}
}
