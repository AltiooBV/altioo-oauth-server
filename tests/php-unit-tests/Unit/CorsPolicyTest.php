<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Test\Unit;

use Altioo\iTop\Extension\OAuthServer\CorsPolicy;
use PHPUnit\Framework\TestCase;

final class CorsPolicyTest extends TestCase
{
	private const INSPECTOR = 'http://localhost:6274';

	public function testNothingIsSentByDefault(): void
	{
		$this->assertSame([], CorsPolicy::Headers(self::INSPECTOR, [], 'POST'));
	}

	public function testAnUnlistedOriginGetsNothing(): void
	{
		$this->assertSame([], CorsPolicy::Headers('https://evil.example', [self::INSPECTOR], 'POST'));
		$this->assertSame([], CorsPolicy::Headers('', [self::INSPECTOR], 'POST'));
	}

	public function testAListedOriginIsEchoedNeverAStar(): void
	{
		$this->assertSame(['Access-Control-Allow-Origin: '.self::INSPECTOR, 'Vary: Origin'], CorsPolicy::Headers(self::INSPECTOR, [self::INSPECTOR], 'POST'));
	}

	public function testTheDiscoveryDocumentsAreReadableFromAnyOriginWithoutCredentials(): void
	{
		$this->assertSame(['Access-Control-Allow-Origin: *'], CorsPolicy::PublicHeaders('GET'));
		$aHeaders = CorsPolicy::PublicHeaders('OPTIONS');
		$this->assertContains('Access-Control-Allow-Headers: MCP-Protocol-Version', $aHeaders);
		$this->assertSame([], array_filter($aHeaders, static fn(string $s) => stripos($s, 'Allow-Credentials') !== false));
	}

	public function testAPreflightIsAnswered(): void
	{
		$aHeaders = CorsPolicy::Headers(self::INSPECTOR, [self::INSPECTOR], 'OPTIONS');
		$this->assertContains('Access-Control-Allow-Headers: Authorization, Content-Type', $aHeaders);
		$this->assertContains('Access-Control-Allow-Methods: GET, POST', $aHeaders);
	}
}
