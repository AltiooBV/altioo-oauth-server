<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Service;

use Altioo\iTop\Extension\OAuthServer\Config;
use Altioo\iTop\Extension\OAuthServer\Secret;
use DBObjectSearch;
use DBObjectSet;
use MetaModel;
use AltiooOAuthClient;
use AltiooOAuthGrant;
use User;

/**
 * Ending a connection, whoever ends it: the token endpoint, the clean-up process, the person
 * under My Account, or an administrator's Revoke in the console.
 *
 * @since 0.1.0
 */
final class Grants
{
	/**
	 * The grant is kept, ended and with its secrets overwritten, as the record of what
	 * happened; its token is deleted, so nothing it issued still works.
	 *
	 * @param bool $bSave false when the caller writes the grant itself, as a lifecycle action does
	 * @param bool $bByThisModule true when this module ends it on its own, and the history names
	 *     the client; false when a person ends it, and the history names them
	 */
	public static function End(AltiooOAuthGrant $oGrant, string $sReason, bool $bSave = true, bool $bByThisModule = true): void
	{
		PersonalTokens::Delete((int) $oGrant->Get('personal_token_id'), $bByThisModule ? self::ClientName($oGrant) : null);
		// Values nobody holds: an empty one would match an empty secret.
		$oGrant->Set('refresh_secret', Secret::NewSecret());
		$oGrant->Set('previous_refresh_secret', Secret::NewSecret());
		$oGrant->Set('status', 'ended');
		$oGrant->Set('end_reason', $sReason);
		$oGrant->Set('end_date', date('Y-m-d H:i:s'));
		$oGrant->Set('personal_token_id', 0);
		if ($bSave) {
			$oGrant->AllowWrite();
			$oGrant->DBUpdate();
		}
	}

	/**
	 * A grant deleted rather than ended, in the console or with its client (DEL_AUTO), takes its
	 * token with it: the token would otherwise work until it expires, with nothing left to renew
	 * it or to say what it was for. The history names whoever deleted it. A grant deleted with
	 * its account is left alone: that account is already gone, the deletion plan removes it
	 * first, and authent-token's own DEL_AUTO deletes its tokens in the same plan, which a
	 * deletion from here would pull from under it. An ended grant has no token left.
	 */
	public static function Deleted(AltiooOAuthGrant $oGrant): void
	{
		if (MetaModel::GetObject(User::class, (int) $oGrant->Get('user_id'), false, true) === null) {
			return;
		}
		PersonalTokens::Delete((int) $oGrant->Get('personal_token_id'), null);
	}

	/**
	 * A person ends one of their own connections, from My Account or from the portal's My
	 * profile. Someone else's connection, or one already ended, is left as it is.
	 *
	 * @return AltiooOAuthGrant|null the connection ended, or null when nothing was
	 */
	public static function Withdraw(int $iGrantId, int $iUserId): ?AltiooOAuthGrant
	{
		$oGrant = MetaModel::GetObject(AltiooOAuthGrant::class, $iGrantId, false, true);
		if (!$oGrant instanceof AltiooOAuthGrant
			|| (int) $oGrant->Get('user_id') !== $iUserId
			|| $oGrant->Get('status') !== 'active') {
			return null;
		}
		self::End($oGrant, 'withdrawn', true, false);

		return $oGrant;
	}

	/**
	 * A grant whose token was deleted is ended here rather than listed: the person who deleted
	 * it would otherwise still see it as connected until the client's next refresh.
	 *
	 * @return list<AltiooOAuthGrant>
	 */
	public static function ActiveFor(int $iUserId): array
	{
		$oSearch = DBObjectSearch::FromOQL("SELECT AltiooOAuthGrant WHERE user_id = :user AND status = 'active'");
		$oSearch->AllowAllData();
		$oSet = new DBObjectSet($oSearch, ['creation_date' => false], ['user' => $iUserId]);
		$aGrants = [];
		while ($oGrant = $oSet->Fetch()) {
			if (PersonalTokens::Find((int) $oGrant->Get('personal_token_id')) === null) {
				self::End($oGrant, 'disconnected');
				continue;
			}
			$aGrants[] = $oGrant;
		}

		return $aGrants;
	}

	/**
	 * The connection's resource in the words the consent page used, or its key when that
	 * resource is no longer configured.
	 */
	public static function ResourceLabel(AltiooOAuthGrant $oGrant): string
	{
		$sKey = (string) $oGrant->Get('resource');
		$oResource = Config::Resources()[$sKey] ?? null;

		return $oResource === null ? $sKey : $oResource->Label();
	}

	public static function ClientName(AltiooOAuthGrant $oGrant): string
	{
		$oClient = MetaModel::GetObject(AltiooOAuthClient::class, $oGrant->Get('client_id'), false, true);

		return $oClient instanceof AltiooOAuthClient ? (string) $oClient->Get('client_name') : '';
	}
}
