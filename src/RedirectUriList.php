<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer;

/**
 * A client's redirect URIs as stored: a JSON list when the client registered itself, one per
 * line when an administrator typed them in the console.
 *
 * @since 0.1.0
 */
final class RedirectUriList
{
	/**
	 * @return list<string>
	 */
	public static function Parse(string $sStored): array
	{
		$aUris = json_decode($sStored, true);
		if (!is_array($aUris)) {
			$aUris = preg_split('/\R/', $sStored) ?: [];
		}

		return array_values(array_filter(array_map(static fn($s) => is_string($s) ? trim($s) : '', $aUris), static fn(string $s) => $s !== ''));
	}
}
