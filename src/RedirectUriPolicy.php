<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer;

/**
 * Which redirect URIs a client may register. Matching is exact, byte for byte: no prefix, no
 * wildcard, no normalisation, because each of those has been an open redirect somewhere.
 *
 * @since 0.1.0
 */
final class RedirectUriPolicy
{
	/**
	 * @param list<string> $aAllowlist
	 */
	public function __construct(private readonly array $aAllowlist, private readonly bool $bAllowLoopback)
	{
	}

	public function IsAllowed(string $sUri): bool
	{
		if (in_array($sUri, $this->aAllowlist, true)) {
			return true;
		}

		return $this->bAllowLoopback && self::IsLoopback($sUri);
	}

	/**
	 * A native client listens on a port it picks at run time, so any port is accepted on the
	 * loopback address (RFC 8252 §7.3). Plain http only, no user info, query or fragment.
	 */
	public static function IsLoopback(string $sUri): bool
	{
		return preg_match('#^http://(localhost|127\.0\.0\.1|\[::1\])(:[0-9]{1,5})?(/[A-Za-z0-9._~/%-]*)?$#D', $sUri) === 1;
	}
}
