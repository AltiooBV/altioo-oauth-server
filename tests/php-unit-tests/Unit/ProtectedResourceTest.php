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

	public function testAnAccountWithoutTheConsoleIsOfferedOnlyThePortalGradesAndTheLimits(): void
	{
		$oPortal = $this->Graded()->ForAccount(false);
		$this->assertSame(self::PORTAL_GRADES, $oPortal->aGrades);
		$this->assertSame(['MCP-read', 'MCP', 'MCP-advisory'], $oPortal->oScopes->Offered([]));
		$this->assertSame(self::LIMITS, $oPortal->aLimits);
	}

	public function testAConsoleAccountIsOfferedTheResourceAsItIs(): void
	{
		$oResource = $this->Graded();
		$this->assertSame($oResource, $oResource->ForAccount(true));
	}

	public function testWithoutPortalGradesEveryAccountIsOfferedTheSame(): void
	{
		$oResource = $this->Resource([]);
		$this->assertSame($oResource, $oResource->ForAccount(false));
	}

	private const GRADES = [
		['label' => 'Read', 'scopes' => ['MCP-read']],
		['label' => 'Write', 'scopes' => ['MCP-write']],
		['label' => 'Delete', 'scopes' => ['MCP-write', 'MCP-delete']],
		['label' => 'All', 'scopes' => ['MCP']],
	];
	private const PORTAL_GRADES = [
		['label' => 'Read', 'scopes' => ['MCP-read']],
		['label' => 'All in the portal', 'scopes' => ['MCP']],
	];
	private const LIMITS = [
		['label' => 'Rehearse', 'scopes' => ['MCP-advisory']],
	];

	private function Graded(): ProtectedResource
	{
		return new ProtectedResource('mcp', 'https://itop.example/env-production/altioo-mcp/index.php',
			new ScopePolicy(['MCP-read', 'MCP-write', 'MCP-delete', 'MCP', 'MCP-advisory', 'MCP-toolset-objects']),
			[], self::GRADES, self::LIMITS, '', ['header'], self::PORTAL_GRADES);
	}

	private function Resource(array $aRequired): ProtectedResource
	{
		return new ProtectedResource('mcp', 'https://itop.example/env-production/altioo-mcp/index.php', new ScopePolicy(['MCP']), $aRequired);
	}
}
