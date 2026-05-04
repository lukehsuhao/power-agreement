<?php
/**
 * Version requirement checker.
 *
 * @package PowerAgreement\Compat
 */

declare(strict_types=1);

namespace PowerAgreement\Compat;

/**
 * Pure-PHP helper for verifying environment versions (PHP, WP, WC).
 *
 * Pure logic only — no WordPress functions, so it is unit-testable
 * without bootstrapping WordPress.
 */
final class Requirements {

	/**
	 * Run a list of version checks.
	 *
	 * @param array<string, array{actual: string|null, required: string}> $requirements
	 *   Map of check key (e.g. "php", "wp", "wc") to { actual, required }.
	 *   `actual` may be null to express "component not present".
	 *
	 * @return true|array<string, string>
	 *   Returns true when every check passes, otherwise an associative array
	 *   of failed-key => human-readable reason. Caller is responsible for
	 *   surfacing the messages (admin notice, CLI output, etc.).
	 */
	public static function check( array $requirements ) {
		$failures = array();

		foreach ( $requirements as $key => $check ) {
			$required = $check['required'] ?? '';
			$actual   = $check['actual'] ?? null;

			if ( $actual === null ) {
				$failures[ $key ] = sprintf(
					'requires %s %s or later (component not detected)',
					strtoupper( (string) $key ),
					$required
				);
				continue;
			}

			if ( version_compare( $actual, $required, '<' ) ) {
				$failures[ $key ] = sprintf(
					'requires %s %s or later (current: %s)',
					strtoupper( (string) $key ),
					$required,
					$actual
				);
			}
		}

		return $failures === array() ? true : $failures;
	}
}
