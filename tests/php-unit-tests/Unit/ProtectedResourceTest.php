<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Test\Unit;

use Altioo\iTop\Extension\OAuthServer\ProtectedResource;
use Altioo\iTop\Extension\OAuthServer\ScopePolicy;
use PHPUnit\Framework\TestCase;

final class ProtectedResourceTest extends TestCase
{
	public function testAResourceWithNoRequiredProfileAcceptsAnyone(): void
	{
		$this->assertTrue($this->Resource([])->AcceptsProfiles([]));
	}

	public function testOneRequiredProfileIsEnough(): void
	{
		$oResource = $this->Resource(['Administrator', 'MCP Services User']);
		$this->assertTrue($oResource->AcceptsProfiles(['Portal user', 'MCP Services User']));
		$this->assertFalse($oResource->AcceptsProfiles(['Portal user']));
	}

	private function Resource(array $aRequired): ProtectedResource
	{
		return new ProtectedResource('mcp', 'https://itop.example/env-production/altioo-mcp/index.php', new ScopePolicy(['MCP']), $aRequired);
	}
}
