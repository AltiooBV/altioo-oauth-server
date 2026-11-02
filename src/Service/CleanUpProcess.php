<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Service;

use Altioo\iTop\Extension\OAuthServer\Config;
use Altioo\iTop\Extension\OAuthServer\Controller\Router;
use DBObject;
use DBObjectSearch;
use DBObjectSet;
use iBackgroundProcess;
use IssueLog;
use AltiooOAuthGrant;

/**
 * Hourly, from iTop's cron: ends connections past their lifetime, so that their token goes
 * even if the client never comes back to refresh it; deletes spent codes, connections ended
 * longer ago than the retention period, and clients that registered and were never used.
 *
 * @since 0.1.0
 */
final class CleanUpProcess implements iBackgroundProcess
{
	/** A spent code is kept a day, so that a replay of it can still end the grant it produced. */
	private const CODE_RETENTION = 86400;
	/** A client that registered and never completed a connection. */
	private const UNUSED_CLIENT_RETENTION = 86400;

	public function GetPeriodicity()
	{
		return 3600;
	}

	public function Process($iUnixTimeLimit)
	{
		$iNow = time();
		$iEnded = $this->EndPastLifetime($iNow, (int) $iUnixTimeLimit);
		// Each connection ended above is recorded as its client's; what follows is the clean-up's
		// own work, and would otherwise carry the name of the last of those clients.
		PersonalTokens::Track('hourly clean-up');
		$aCounts = [
			'ended' => $iEnded,
			'codes' => $this->DeleteAll("SELECT AltiooOAuthCode WHERE expiration_date < :before",
				['before' => self::Date($iNow - self::CODE_RETENTION)], (int) $iUnixTimeLimit),
			'grants' => $this->DeleteAll("SELECT AltiooOAuthGrant WHERE status = 'ended' AND end_date < :before",
				['before' => self::Date($iNow - Config::EndedGrantRetentionDays() * 86400)], (int) $iUnixTimeLimit),
			'clients' => $this->DeleteAll("SELECT AltiooOAuthClient WHERE source = 'dcr' AND ISNULL(last_used_date) AND creation_date < :before",
				['before' => self::Date($iNow - self::UNUSED_CLIENT_RETENTION)], (int) $iUnixTimeLimit),
		];
		$sSummary = vsprintf('%d connections ended, %d codes, %d ended connections and %d unused clients deleted', $aCounts);
		// At Ok, the default minimum level, so that the line is there without any configuration.
		IssueLog::Ok($sSummary, Router::LOG_CHANNEL);

		return $sSummary;
	}

	private function EndPastLifetime(int $iNow, int $iUnixTimeLimit): int
	{
		$oSearch = DBObjectSearch::FromOQL("SELECT AltiooOAuthGrant WHERE status = 'active' AND (expiration_date < :now OR last_refresh_date < :idle)");
		$oSearch->AllowAllData();
		$oSet = new DBObjectSet($oSearch, [], ['now' => self::Date($iNow), 'idle' => self::Date($iNow - Config::GrantIdleDays() * 86400)]);
		$iCount = 0;
		while (time() < $iUnixTimeLimit && ($oGrant = $oSet->Fetch()) instanceof AltiooOAuthGrant) {
			Grants::End($oGrant, strtotime((string) $oGrant->Get('expiration_date')) < $iNow ? 'expired' : 'idle');
			$iCount++;
		}

		return $iCount;
	}

	/**
	 * @param array<string, string> $aParams
	 */
	private function DeleteAll(string $sOql, array $aParams, int $iUnixTimeLimit): int
	{
		$oSearch = DBObjectSearch::FromOQL($sOql);
		$oSearch->AllowAllData();
		$oSet = new DBObjectSet($oSearch, [], $aParams);
		$iCount = 0;
		while (time() < $iUnixTimeLimit && ($oObject = $oSet->Fetch()) instanceof DBObject) {
			$oObject->AllowDelete();
			$oObject->DBDelete();
			$iCount++;
		}

		return $iCount;
	}

	private static function Date(int $iTime): string
	{
		return date('Y-m-d H:i:s', $iTime);
	}
}
