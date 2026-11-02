<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Test\Unit;

use Altioo\iTop\Extension\OAuthServer\ProtectedResource;
use Altioo\iTop\Extension\OAuthServer\ScopePolicy;
use Altioo\iTop\Extension\OAuthServer\TokenAudience;
use PHPUnit\Framework\TestCase;

final class TokenAudienceTest extends TestCase
{
	private const PORTAL_A = 'https://itop.example/env-production/altioo-mcp/index.php?portal_id=itop-portal';
	private const PORTAL_B = 'https://itop.example/env-production/altioo-mcp/index.php?portal_id=other-portal';

	public function testATokenIsForTheUrlItsResourceHasNow(): void
	{
		$oAudience = TokenAudience::ForResource('portal-a', $this->Resources());
		$this->assertSame('portal-a', $oAudience->sResourceKey);
		$this->assertSame(self::PORTAL_A, $oAudience->sResourceUrl);
		$this->assertTrue($oAudience->IsFor(self::PORTAL_A));
	}

	public function testATokenForOnePortalIsNotForAnother(): void
	{
		$this->assertFalse(TokenAudience::ForResource('portal-a', $this->Resources())->IsFor(self::PORTAL_B));
	}

	public function testTheUrlIsComparedExactly(): void
	{
		$oAudience = TokenAudience::ForResource('portal-a', $this->Resources());
		$this->assertFalse($oAudience->IsFor('https://itop.example/env-production/altioo-mcp/index.php'));
		$this->assertFalse($oAudience->IsFor(self::PORTAL_A.'&x=1'));
		$this->assertFalse($oAudience->IsFor(strtoupper(self::PORTAL_A)));
	}

	public function testAResourceNoLongerConfiguredIsForNoUrl(): void
	{
		$oAudience = TokenAudience::ForResource('removed', $this->Resources());
		$this->assertSame('removed', $oAudience->sResourceKey);
		$this->assertSame('', $oAudience->sResourceUrl);
		$this->assertFalse($oAudience->IsFor(''));
		$this->assertFalse($oAudience->IsFor(self::PORTAL_A));
	}

	public function testATokenWithNoKeyHasNoAudience(): void
	{
		// No iTop here: a key no connection can own answers before any query.
		$this->assertNull(TokenAudience::Of(0));
		$this->assertNull(TokenAudience::Of(-1));
	}

	/**
	 * @return array<string, ProtectedResource>
	 */
	private function Resources(): array
	{
		return [
			'portal-a' => new ProtectedResource('portal-a', self::PORTAL_A, new ScopePolicy(['MCP'])),
			'portal-b' => new ProtectedResource('portal-b', self::PORTAL_B, new ScopePolicy(['MCP'])),
		];
	}
}
