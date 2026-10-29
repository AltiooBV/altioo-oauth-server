<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer;

/**
 * The authorize requests a session keeps between the first leg and consent. Anyone can start
 * them, and a session that only ever grew would be loaded and written whole on every request.
 *
 * @since 0.1.0
 */
final class PendingRequests
{
	/**
	 * Drops expired requests, then the oldest beyond $iKeep.
	 *
	 * @param mixed $pending what the session holds, whatever it is
	 * @return array<string, array<string, mixed>>
	 */
	public static function Prune(mixed $pending, int $iNow, int $iKeep): array
	{
		$aPending = array_filter(is_array($pending) ? $pending : [], static fn($aRequest) => is_array($aRequest) && (int) ($aRequest['expires'] ?? 0) >= $iNow);
		uasort($aPending, static fn(array $a, array $b) => (int) $a['expires'] <=> (int) $b['expires']);

		return $iKeep <= 0 ? [] : array_slice($aPending, -$iKeep, null, true);
	}
}
