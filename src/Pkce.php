<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer;

/**
 * PKCE with S256 only (RFC 7636). There is no plain method: OAuth 2.1 requires S256 of any
 * client that can compute it, and every client this server is for can.
 *
 * @since 0.1.0
 */
final class Pkce
{
	/** Both a challenge and a verifier are 43 to 128 unreserved characters (RFC 7636 §4.1, §4.2). */
	private const SHAPE = '/^[A-Za-z0-9._~-]{43,128}$/D';

	public static function IsValidChallenge(string $sChallenge): bool
	{
		return preg_match(self::SHAPE, $sChallenge) === 1;
	}

	public static function Verify(string $sChallenge, string $sVerifier): bool
	{
		if (preg_match(self::SHAPE, $sVerifier) !== 1) {
			return false;
		}

		return hash_equals($sChallenge, self::Challenge($sVerifier));
	}

	public static function Challenge(string $sVerifier): string
	{
		return rtrim(strtr(base64_encode(hash('sha256', $sVerifier, true)), '+/', '-_'), '=');
	}
}
