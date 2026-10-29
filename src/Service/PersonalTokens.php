<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Service;

use Combodo\iTop\AuthentToken\Service\AuthentTokenService;
use Combodo\iTop\AuthentToken\Service\PersonalTokenService;
use Combodo\iTop\Core\CMDBChange\CMDBChangeOrigin;
use CMDBObject;
use MetaModel;
use ormSet;
use PersonalToken;
use User;

/**
 * The PersonalToken a grant owns: created at the code exchange, given a new value on every
 * refresh, deleted when the grant ends (doc/adr/0004-one-personal-token-per-grant.md). Each
 * change is recorded in the token's history as made by this module for the named client.
 *
 * @since 0.1.0
 */
final class PersonalTokens
{
	/**
	 * Created the way authent-token's own My Account controller creates one, without needing a
	 * session: the token endpoint has none.
	 *
	 * @param list<string> $aScopes
	 * @return array{0: PersonalToken, 1: string} the token and its value in clear, which exists
	 *     only in this response
	 */
	public static function Create(int $iUserId, string $sClientName, array $aScopes, int $iLifetime): array
	{
		self::Track($sClientName);
		$oToken = new PersonalToken();
		$oToken->Set('user_id', $iUserId);
		$oToken->Set('application', mb_substr('OAuth: '.$sClientName, 0, 255));
		$oToken->Set('scope', self::ScopeSet($aScopes));
		$oToken->Set('expiration_date', self::DateIn($iLifetime));
		$oToken->AllowWrite();
		$oToken->DBInsert();

		return [$oToken, $oToken->GetToken()];
	}

	/**
	 * A new value in place, as the console's rebuild button does it: the previous value stops
	 * working now.
	 *
	 * @return string the new value in clear
	 */
	/**
	 * @param list<string> $aScopes what the grant carries now, which today's policy may have narrowed
	 */
	public static function Renew(PersonalToken $oToken, string $sClientName, int $iLifetime, array $aScopes): string
	{
		self::Track($sClientName);
		$oService = new AuthentTokenService();
		$sValue = $oService->CreateNewToken($oToken);
		$oToken->Set('auth_token', $oService->CreatePassword($sValue));
		$oToken->Set('scope', self::ScopeSet($aScopes));
		$oToken->Set('expiration_date', self::DateIn($iLifetime));
		$oToken->AllowWrite();
		$oToken->DBUpdate();

		return $sValue;
	}

	public static function Find(int $iKey): ?PersonalToken
	{
		if ($iKey <= 0) {
			return null;
		}
		$oToken = MetaModel::GetObject(PersonalToken::class, $iKey, false, true);

		return $oToken instanceof PersonalToken ? $oToken : null;
	}

	/**
	 * @param string|null $sClientName the client, when this module ends the connection on its
	 *     own (the token endpoint, the clean-up); null when a person does, from the console or My
	 *     Account, so that the history names them
	 */
	public static function Delete(int $iKey, ?string $sClientName): void
	{
		$oToken = self::Find($iKey);
		if ($oToken === null) {
			return;
		}
		if ($sClientName !== null) {
			self::Track($sClientName);
		}
		$oToken->AllowWrite();
		$oToken->DBDelete();
	}

	/**
	 * Whether a token for this account would be accepted at all: an enabled account whose
	 * profiles authent-token lets own personal tokens.
	 */
	public static function IsAllowedFor(?User $oUser): bool
	{
		return $oUser !== null
			&& $oUser->Get('status') === 'enabled'
			&& PersonalTokenService::GetInstance()->IsPersonalTokenManagementAllowed($oUser);
	}

	/**
	 * AttributeSet takes a string or an ormSet; an array is dropped without a word.
	 *
	 * @param list<string> $aScopes
	 */
	private static function ScopeSet(array $aScopes): ormSet
	{
		$oScope = new ormSet(MetaModel::GetAttributeOrigin(PersonalToken::class, 'scope'), 'scope');
		$oScope->SetValues($aScopes);

		return $oScope;
	}

	private static function Track(string $sClientName): void
	{
		CMDBObject::SetTrackInfo(mb_substr('OAuth: '.$sClientName, 0, 255));
		CMDBObject::SetTrackOrigin(CMDBChangeOrigin::CUSTOM_EXTENSION);
	}

	private static function DateIn(int $iSeconds): string
	{
		return date('Y-m-d H:i:s', time() + $iSeconds);
	}
}
