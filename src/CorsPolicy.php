<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer;

/**
 * CORS for an origin the administrator listed, and never "*": the token response is a
 * credential, and a page on another origin has no business reading it unless allowed.
 *
 * @since 0.1.0
 */
final class CorsPolicy
{
	/**
	 * @param list<string> $aAllowed
	 * @return list<string> the headers to send; empty for an origin that is not listed
	 */
	public static function Headers(string $sOrigin, array $aAllowed, string $sMethod): array
	{
		if ($sOrigin === '' || !in_array($sOrigin, $aAllowed, true)) {
			return [];
		}
		$aHeaders = ['Access-Control-Allow-Origin: '.$sOrigin, 'Vary: Origin'];
		if (self::IsPreflight($sMethod)) {
			$aHeaders[] = 'Access-Control-Allow-Methods: GET, POST';
			$aHeaders[] = 'Access-Control-Allow-Headers: Authorization, Content-Type';
			$aHeaders[] = 'Access-Control-Max-Age: 600';
		}

		return $aHeaders;
	}

	public static function IsPreflight(string $sMethod): bool
	{
		return $sMethod === 'OPTIONS';
	}
}
