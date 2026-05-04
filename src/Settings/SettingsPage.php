<?php
/**
 * Admin settings page for Power Agreement.
 *
 * @package PowerAgreement\Settings
 */

declare(strict_types=1);

namespace PowerAgreement\Settings;

/**
 * Renders the WooCommerce → Power Agreement admin page.
 *
 * Uses the standard Settings API for nonce handling and persistence and
 * delegates value sanitisation to {@see SettingsRepository::sanitize()}.
 */
final class SettingsPage {

	public const MENU_SLUG     = 'power-agreement';
	public const SETTING_GROUP = 'power_agreement';
	public const SECTION_ID    = 'power_agreement_main';

	private SettingsRepository $repo;

	public function __construct( SettingsRepository $repo ) {
		$this->repo = $repo;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_editor' ) );
	}

	public function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Power Agreement', 'power-agreement' ),
			__( 'Power Agreement', 'power-agreement' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	public function register_settings(): void {
		register_setting(
			self::SETTING_GROUP,
			SettingsRepository::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => SettingsRepository::defaults(),
			)
		);

		add_settings_section(
			self::SECTION_ID,
			__( 'Checkout Agreement', 'power-agreement' ),
			array( $this, 'render_section_intro' ),
			self::MENU_SLUG
		);

		add_settings_field(
			'enabled',
			__( 'Enable agreement on checkout', 'power-agreement' ),
			array( $this, 'field_enabled' ),
			self::MENU_SLUG,
			self::SECTION_ID
		);

		add_settings_field(
			'title',
			__( 'Section title', 'power-agreement' ),
			array( $this, 'field_title' ),
			self::MENU_SLUG,
			self::SECTION_ID
		);

		add_settings_field(
			'content',
			__( 'Agreement body', 'power-agreement' ),
			array( $this, 'field_content' ),
			self::MENU_SLUG,
			self::SECTION_ID
		);

		add_settings_field(
			'consent_text',
			__( 'Consent checkbox label', 'power-agreement' ),
			array( $this, 'field_consent_text' ),
			self::MENU_SLUG,
			self::SECTION_ID
		);
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return array{enabled: bool, title: string, content: string, consent_text: string}
	 */
	public function sanitize( array $raw ): array {
		return $this->repo->sanitize( $raw );
	}

	public function enqueue_editor( string $hook_suffix ): void {
		// Only on our own settings page.
		if ( ! is_string( $hook_suffix ) || strpos( $hook_suffix, self::MENU_SLUG ) === false ) {
			return;
		}
		wp_enqueue_editor();
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php
			if ( $this->repo->isEnabled() && '' === $this->repo->content() ) {
				echo '<div class="notice notice-warning"><p>'
					. esc_html__( 'Agreement is enabled but the body is empty — checkout will skip rendering until you add some content.', 'power-agreement' )
					. '</p></div>';
			}
			?>
			<form action="options.php" method="post">
				<?php
				settings_fields( self::SETTING_GROUP );
				do_settings_sections( self::MENU_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public function render_section_intro(): void {
		echo '<p>'
			. esc_html__( 'Configure the agreement that customers must accept before placing an order.', 'power-agreement' )
			. '</p>';
	}

	public function field_enabled(): void {
		$value = $this->repo->isEnabled();
		$name  = SettingsRepository::OPTION . '[enabled]';
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $value, true ); ?> />
			<?php esc_html_e( 'Show the agreement section on checkout and require consent.', 'power-agreement' ); ?>
		</label>
		<?php
	}

	public function field_title(): void {
		$value = $this->repo->title();
		$name  = SettingsRepository::OPTION . '[title]';
		?>
		<input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" maxlength="100" />
		<?php
	}

	public function field_content(): void {
		$value     = $this->repo->content();
		$editor_id = 'power_agreement_content';
		$name      = SettingsRepository::OPTION . '[content]';

		wp_editor(
			$value,
			$editor_id,
			array(
				'textarea_name' => $name,
				'textarea_rows' => 12,
				'media_buttons' => false,
				'tinymce'       => array(
					'toolbar1' => 'bold,italic,bullist,numlist,blockquote,link,unlink,undo,redo',
				),
			)
		);
	}

	public function field_consent_text(): void {
		$value = $this->repo->consentText();
		$name  = SettingsRepository::OPTION . '[consent_text]';
		?>
		<input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" maxlength="200" />
		<?php
	}
}
