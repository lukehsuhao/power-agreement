<?php
/**
 * Decides when consent must be enforced and produces error messages.
 *
 * @package PowerAgreement\Checkout
 */

declare(strict_types=1);

namespace PowerAgreement\Checkout;

use PowerAgreement\Settings\SettingsRepository;

/**
 * Single source of truth for "is the agreement active right now?"
 *
 * Both Classic and Block checkout paths call into this so behaviour
 * cannot drift between the two integrations.
 */
final class ConsentValidator {

	public const ERROR_CODE = 'power_agreement_required';

	private SettingsRepository $repo;

	public function __construct( SettingsRepository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Whether the agreement should be presented and enforced for this request.
	 *
	 * Disabled in admin, disabled if the toggle is off, and disabled if the
	 * body is empty (so we don't bother customers with a meaningless tickbox).
	 */
	public function shouldEnforce(): bool {
		return $this->repo->isEnabled() && '' !== $this->repo->content();
	}

	/**
	 * The user-visible error shown when consent is missing on submit.
	 */
	public function errorMessage(): string {
		return sprintf(
			/* translators: %s: the consent checkbox label */
			__( 'Please check "%s" to place your order.', 'power-agreement' ),
			$this->repo->consentText()
		);
	}
}
