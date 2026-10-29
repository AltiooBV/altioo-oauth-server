<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The version is written in two files the setup reads, and nothing else keeps them together.
 */
final class ModuleMetadataTest extends TestCase
{
	public function testExtensionXmlAndTheModuleDeclareTheSameVersion(): void
	{
		$sRoot = dirname(__DIR__, 3);
		$sExtension = (string) simplexml_load_file($sRoot.'/extension.xml')->version;
		$this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/D', $sExtension);
		$this->assertStringContainsString("'altioo-oauth-server/$sExtension'", (string) file_get_contents($sRoot.'/module.altioo-oauth-server.php'));
	}
}
