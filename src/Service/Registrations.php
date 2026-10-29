<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Service;

use Altioo\iTop\Extension\OAuthServer\Controller\Router;
use DBObjectSearch;
use DBObjectSet;
use IssueLog;
use AltiooOAuthClient;

/**
 * The clients that registered themselves and have not completed a connection yet. Anyone may
 * register, so their number is bounded by making room rather than by refusing: a limit that
 * refused would let a flood of registrations from a handful of addresses lock everyone else
 * out of connecting until the flood aged out.
 *
 * @since 0.1.0
 */
final class Registrations
{
	/**
	 * Deletes the oldest unused registrations until fewer than $iLimit are left, so that one more
	 * fits. A registration a person has already consented to (it holds a code) is kept: an
	 * attacker cannot make those, so a flood can only push out registrations whose person has
	 * not signed in and consented yet, who then has to start again.
	 *
	 * @return int how many were deleted
	 */
	public static function MakeRoom(int $iLimit, string $sForIp): int
	{
		$oSearch = DBObjectSearch::FromOQL("SELECT AltiooOAuthClient WHERE source = 'dcr' AND ISNULL(last_used_date)");
		$oSearch->AllowAllData();
		$oSet = new DBObjectSet($oSearch, ['creation_date' => true]);
		$iExcess = $oSet->Count() - ($iLimit - 1);
		$iDeleted = 0;
		while ($iExcess > 0 && ($oClient = $oSet->Fetch()) instanceof AltiooOAuthClient) {
			if (self::HasCode((int) $oClient->GetKey())) {
				continue;
			}
			$oClient->AllowDelete();
			$oClient->DBDelete();
			$iExcess--;
			$iDeleted++;
		}
		if ($iDeleted > 0) {
			// At Warning, like a refusal: a stream of these is a flood of registrations, and it is
			// what explains a person's "unknown application" on a connection they had just started.
			IssueLog::Warning("Unused registration limit reached: deleted the $iDeleted oldest unused registration(s)", Router::LOG_CHANNEL, ['ip' => $sForIp]);
		}

		return $iDeleted;
	}

	private static function HasCode(int $iClientKey): bool
	{
		$oSearch = DBObjectSearch::FromOQL('SELECT AltiooOAuthCode WHERE client_id = :client');
		$oSearch->AllowAllData();

		return (new DBObjectSet($oSearch, [], ['client' => $iClientKey]))->CountExceeds(0);
	}
}
