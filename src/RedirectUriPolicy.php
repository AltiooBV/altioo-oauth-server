<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer;

/**
 * Which redirect URIs a client may register. Matching is exact, byte for byte, with no
 * normalisation, because each loosening has been an open redirect somewhere.
 *
 * The one exception is an entry ending in "*", for a client that gives each integration its
 * own callback under a fixed path (ChatGPT's https://chatgpt.com/connector/oauth/{id}). It
 * matches exactly one more path segment of unreserved characters: no slash, query, fragment
 * or dot-segment. The entry itself must be https, name a host and a path, and end with "/*".
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
		foreach ($this->aAllowlist as $sEntry) {
			if (self::MatchesPrefixEntry($sEntry, $sUri)) {
				return true;
			}
		}

		return $this->bAllowLoopback && self::IsLoopback($sUri);
	}

	/**
	 * Whether authorize may send a person back to $sUri for a client: one of the client's own
	 * redirect URIs and, for a client that registered itself, one the allowlist still allows.
	 * The allowlist is applied again here, not only at registration, so that an administrator
	 * who withdraws an entry, or turns allow_loopback off, withdraws it from the clients already
	 * registered with it. A client an administrator created is its own allowlist.
	 *
	 * @param list<string> $aClientUris
	 */
	public function AllowsForClient(string $sUri, array $aClientUris, bool $bSelfRegistered): bool
	{
		return in_array($sUri, $aClientUris, true) && (!$bSelfRegistered || $this->IsAllowed($sUri));
	}

	public static function MatchesPrefixEntry(string $sEntry, string $sUri): bool
	{
		if (preg_match('#^https://[A-Za-z0-9.-]+(/[A-Za-z0-9._~-]+)+/\*$#D', $sEntry) !== 1) {
			return false;
		}
		$sPrefix = substr($sEntry, 0, -1);
		if (!str_starts_with($sUri, $sPrefix)) {
			return false;
		}
		$sSegment = substr($sUri, strlen($sPrefix));

		return preg_match('/^[A-Za-z0-9_~-][A-Za-z0-9._~-]*$/D', $sSegment) === 1 && $sSegment !== '..';
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
