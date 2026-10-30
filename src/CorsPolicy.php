<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer;

/**
 * CORS for an origin the administrator listed, and never "*": the token response is a
 * credential, and a page on another origin has no business reading it unless allowed. The
 * discovery documents are the exception: they are the same for everyone, carry nothing a
 * caller could not fetch without a browser, and a browser-based client cannot even find the
 * server without reading them.
 *
 * @since 0.1.0
 */
final class CorsPolicy
{
	/**
	 * For the discovery documents, whatever the origin. No Allow-Credentials, so a browser
	 * sends no cookie with the request and the answer cannot depend on who is signed in.
	 *
	 * @return list<string>
	 */
	public static function PublicHeaders(string $sMethod): array
	{
		$aHeaders = ['Access-Control-Allow-Origin: *'];
		if (self::IsPreflight($sMethod)) {
			// MCP clients send their protocol version with every request, discovery included.
			$aHeaders[] = 'Access-Control-Allow-Methods: GET';
			$aHeaders[] = 'Access-Control-Allow-Headers: MCP-Protocol-Version';
			$aHeaders[] = 'Access-Control-Max-Age: 600';
		}

		return $aHeaders;
	}

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
