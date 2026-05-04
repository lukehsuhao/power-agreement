<?php
/**
 * Foundation smoke test: ensure all Stage 1 classes are autoloadable.
 *
 * @package PowerAgreement\Tests\Unit
 */

declare(strict_types=1);

namespace PowerAgreement\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PowerAgreement\Compat\HposCompat;
use PowerAgreement\Compat\Requirements;
use PowerAgreement\Plugin;

final class PluginAutoloadTest extends TestCase {

	public function testPluginClassExists(): void {
		self::assertTrue( class_exists( Plugin::class ) );
	}

	public function testCompatClassesExist(): void {
		self::assertTrue( class_exists( Requirements::class ) );
		self::assertTrue( class_exists( HposCompat::class ) );
	}

	public function testPluginRecordsTheBootstrapPath(): void {
		$plugin = new Plugin( '/abs/path/to/power-agreement.php' );
		self::assertSame( '/abs/path/to/power-agreement.php', $plugin->getPluginFile() );
	}

	public function testVersionIsSemver(): void {
		self::assertMatchesRegularExpression( '/^\d+\.\d+\.\d+$/', Plugin::version() );
	}
}
