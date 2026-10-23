<?php
/**
 * Plugin bootstrap.
 *
 * @package PratikLondhe\Embed3DModels
 */

namespace PratikLondhe\Embed3DModels;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the services once and registers their hooks.
 */
final class Plugin {

	/**
	 * The running instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Starts the plugin. Runs on `plugins_loaded`.
	 */
	public static function boot(): void {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register();
		}
	}

	/**
	 * The running instance, for other plugins.
	 *
	 * @return Plugin|null
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * The plugin's services: one per concern (settings screen, abilities, WP-CLI, ...).
	 *
	 * @return Service[]
	 */
	private function services(): array {
		$validator = new Media\Validator();
		$models    = new Media\Models( new Media\Converter() );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'embed-3d-models', new Cli\Command( $models, $validator ) );
		}

		return array(
			new Media\Uploads( $validator, $models ),
			new Block\Block( new Block\Renderer( $models ) ),
			new Rest\ModelsController( $models ),
			new Abilities( $models ),
			new Health\SiteHealth( $models ),
		);
	}

	/**
	 * Registers every service.
	 */
	private function register(): void {
		foreach ( $this->services() as $service ) {
			$service->register();
		}
	}
}
