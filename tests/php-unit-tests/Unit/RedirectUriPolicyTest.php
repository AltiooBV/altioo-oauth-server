<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Test\Unit;

use Altioo\iTop\Extension\OAuthServer\RedirectUriPolicy;
use PHPUnit\Framework\TestCase;

final class RedirectUriPolicyTest extends TestCase
{
	private const CLAUDE = 'https://claude.ai/api/mcp/auth_callback';

	/**
	 * @dataProvider nearMisses
	 */
	public function testOnlyTheExactUriIsAllowed(string $sUri): void
	{
		$oPolicy = new RedirectUriPolicy([self::CLAUDE], false);
		$this->assertTrue($oPolicy->IsAllowed(self::CLAUDE));
		$this->assertFalse($oPolicy->IsAllowed($sUri));
	}

	public static function nearMisses(): array
	{
		return [
			'trailing slash' => [self::CLAUDE.'/'],
			'extra query' => [self::CLAUDE.'?next=https://evil.example'],
			'fragment' => [self::CLAUDE.'#x'],
			'upper-case host' => ['https://CLAUDE.ai/api/mcp/auth_callback'],
			'user info' => ['https://claude.ai@evil.example/api/mcp/auth_callback'],
			'sub-domain of the attacker' => ['https://claude.ai.evil.example/api/mcp/auth_callback'],
			'plain http' => ['http://claude.ai/api/mcp/auth_callback'],
			'explicit port' => ['https://claude.ai:443/api/mcp/auth_callback'],
		];
	}

	public function testLoopbackIsOffByDefault(): void
	{
		$this->assertFalse((new RedirectUriPolicy([self::CLAUDE], false))->IsAllowed('http://127.0.0.1:33418/callback'));
	}

	/**
	 * @dataProvider loopback
	 */
	public function testLoopbackAcceptsAnyPortWhenOn(string $sUri, bool $bAllowed): void
	{
		$this->assertSame($bAllowed, (new RedirectUriPolicy([], true))->IsAllowed($sUri));
	}

	public static function loopback(): array
	{
		return [
			'localhost, no port' => ['http://localhost/callback', true],
			'127.0.0.1 with a port' => ['http://127.0.0.1:33418/callback', true],
			'IPv6 loopback' => ['http://[::1]:8080/', true],
			'no path' => ['http://localhost:8080', true],
			'https is not loopback' => ['https://localhost/callback', false],
			'localhost as a sub-domain' => ['http://localhost.evil.example/callback', false],
			'user info' => ['http://user@localhost/callback', false],
			'query' => ['http://localhost/callback?x=1', false],
			'trailing newline' => ["http://localhost/callback\n", false],
			'another 127/8 address' => ['http://127.0.0.2/callback', false],
		];
	}
}
