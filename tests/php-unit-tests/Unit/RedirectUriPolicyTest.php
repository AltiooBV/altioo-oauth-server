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

	/**
	 * @dataProvider perIntegration
	 */
	public function testAStarEntryMatchesOneMoreSegmentOnly(string $sUri, bool $bAllowed): void
	{
		$this->assertSame($bAllowed, (new RedirectUriPolicy(['https://chatgpt.com/connector/oauth/*'], false))->IsAllowed($sUri));
	}

	public static function perIntegration(): array
	{
		return [
			'an integration id' => ['https://chatgpt.com/connector/oauth/abc_123-XYZ', true],
			'the bare prefix' => ['https://chatgpt.com/connector/oauth/', false],
			'two segments' => ['https://chatgpt.com/connector/oauth/abc/def', false],
			'dot-segment' => ['https://chatgpt.com/connector/oauth/..', false],
			'a segment starting with a dot' => ['https://chatgpt.com/connector/oauth/.x', false],
			'query' => ['https://chatgpt.com/connector/oauth/abc?next=https://evil.example', false],
			'fragment' => ['https://chatgpt.com/connector/oauth/abc#x', false],
			'encoded slash' => ['https://chatgpt.com/connector/oauth/a%2Fb', false],
			'another host' => ['https://chatgpt.com.evil.example/connector/oauth/abc', false],
			'trailing newline' => ["https://chatgpt.com/connector/oauth/abc\n", false],
		];
	}

	/**
	 * @dataProvider forClient
	 */
	public function testAuthorizeHoldsASelfRegisteredClientToTodaysAllowlist(string $sUri, array $aClientUris, bool $bSelfRegistered, bool $bLoopback, bool $bAllowed): void
	{
		$this->assertSame($bAllowed, (new RedirectUriPolicy([self::CLAUDE], $bLoopback))->AllowsForClient($sUri, $aClientUris, $bSelfRegistered));
	}

	public static function forClient(): array
	{
		$sDropped = 'https://vscode.dev/redirect';
		$sLocal = 'http://127.0.0.1:33418/callback';

		return [
			'self-registered, still allowed' => [self::CLAUDE, [self::CLAUDE], true, false, true],
			'self-registered, dropped from the allowlist since' => [$sDropped, [self::CLAUDE, $sDropped], true, false, false],
			'self-registered, loopback turned off since' => [$sLocal, [$sLocal], true, false, false],
			'self-registered, loopback still on' => [$sLocal, [$sLocal], true, true, true],
			'allowed, but not one of the client\'s own' => [self::CLAUDE, [$sDropped], true, false, false],
			'created by an administrator, off the allowlist' => [$sDropped, [$sDropped], false, false, true],
			'created by an administrator, not one of its own' => [self::CLAUDE, [$sDropped], false, false, false],
		];
	}

	/**
	 * @dataProvider unsafeEntries
	 */
	public function testAStarEntryThatIsTooWideMatchesNothing(string $sEntry, string $sUri): void
	{
		$this->assertFalse((new RedirectUriPolicy([$sEntry], false))->IsAllowed($sUri));
	}

	public static function unsafeEntries(): array
	{
		return [
			'host only' => ['https://chatgpt.com/*', 'https://chatgpt.com/anything'],
			'no slash before the star' => ['https://chatgpt.com/connector*', 'https://chatgpt.com/connectorX'],
			'plain http' => ['http://chatgpt.com/connector/oauth/*', 'http://chatgpt.com/connector/oauth/abc'],
			'a star in the host' => ['https://*.example/cb/*', 'https://a.example/cb/x'],
		];
	}
}
