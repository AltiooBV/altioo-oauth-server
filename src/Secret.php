<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer;

/**
 * A code or a refresh token is handed out as "<row key>.<secret>". The row is found by its
 * key and the secret checked against the one-way hash stored on it, so the database never
 * holds a usable value and needs no index on one.
 *
 * @since 0.1.0
 */
final class Secret
{
	public static function NewSecret(): string
	{
		return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
	}

	public static function NewClientId(): string
	{
		return bin2hex(random_bytes(16));
	}

	public static function Join(int $iKey, string $sSecret): string
	{
		return $iKey.'.'.$sSecret;
	}

	/**
	 * @return array{0: int, 1: string}|null the row key and the secret, or null when the value
	 *     does not have that shape
	 */
	public static function Split(string $sValue): ?array
	{
		if (preg_match('/^([1-9][0-9]{0,18})\.([A-Za-z0-9_-]{43})$/D', $sValue, $aMatches) !== 1) {
			return null;
		}

		return [(int) $aMatches[1], $aMatches[2]];
	}
}
