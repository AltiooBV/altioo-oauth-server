<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Controller;

use Altioo\iTop\Extension\OAuthServer\ClientCredentials;
use Altioo\iTop\Extension\OAuthServer\Config;
use Altioo\iTop\Extension\OAuthServer\Exception\OAuthException;
use Altioo\iTop\Extension\OAuthServer\Http;
use Altioo\iTop\Extension\OAuthServer\Pkce;
use Altioo\iTop\Extension\OAuthServer\Secret;
use Altioo\iTop\Extension\OAuthServer\Service\Grants;
use Altioo\iTop\Extension\OAuthServer\Service\PersonalTokens;
use CMDBSource;
use MetaModel;
use AltiooOAuthCode;
use AltiooOAuthClient;
use AltiooOAuthGrant;
use User;

/**
 * The token endpoint: the authorization_code and refresh_token grants, for public clients
 * (client_id, bound by PKCE) and confidential ones (a secret, in a Basic header or the form).
 * Every refusal of a code or a refresh token is invalid_grant, which is what tells a client
 * to send the person through authorize again; never invalid_request, which does not.
 *
 * @since 0.1.0
 */
final class TokenController
{
	public static function Token(): never
	{
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
			throw new OAuthException('invalid_request', 'POST only', 405);
		}
		$oClient = self::AuthenticatedClient();
		$sGrantType = self::Posted('grant_type');
		if ($sGrantType === 'authorization_code') {
			self::FromCode($oClient);
		}
		if ($sGrantType === 'refresh_token') {
			self::FromRefreshToken($oClient);
		}
		throw new OAuthException('unsupported_grant_type');
	}

	/**
	 * A public client names itself; a confidential one proves it. A confidential client's
	 * secret is never optional, and a public client sending one is refused rather than
	 * believed, so that nobody comes to rely on a check that is not made.
	 */
	private static function AuthenticatedClient(): AltiooOAuthClient
	{
		$oCredentials = ClientCredentials::FromRequest($_SERVER, $_POST);
		$oClient = $oCredentials === null ? null : AuthorizationController::FindClient($oCredentials->sClientId, false);
		if ($oClient === null) {
			throw new OAuthException('invalid_client', 'unknown client', 401);
		}
		if ($oClient->Get('auth_method') === 'none') {
			if ($oCredentials->sSecret !== null) {
				throw new OAuthException('invalid_client', 'this client is public and has no secret', 401);
			}
		} elseif ($oCredentials->sSecret === null || $oCredentials->sSecret === '' || !$oClient->Get('client_secret')->CheckPassword($oCredentials->sSecret)) {
			throw new OAuthException('invalid_client', 'client authentication failed', 401);
		}

		return $oClient;
	}

	private static function FromCode(AltiooOAuthClient $oClient): never
	{
		$aParts = Secret::Split(self::Posted('code'));
		$oCode = $aParts === null ? null : MetaModel::GetObject(AltiooOAuthCode::class, $aParts[0], false, true);
		if (!$oCode instanceof AltiooOAuthCode || !$oCode->Get('code_secret')->CheckPassword($aParts[1])) {
			throw new OAuthException('invalid_grant', 'unknown code');
		}
		// A code presented twice was intercepted, or replayed: end what its first use produced.
		if ((int) $oCode->Get('grant_id') !== 0) {
			$oGrant = MetaModel::GetObject(AltiooOAuthGrant::class, (int) $oCode->Get('grant_id'), false, true);
			if ($oGrant instanceof AltiooOAuthGrant) {
				Grants::End($oGrant, 'reuse');
			}
			throw new OAuthException('invalid_grant', 'code already used');
		}
		// Claimed with a conditional update, so that two concurrent exchanges of one code
		// cannot both get past this line.
		$sTable = MetaModel::DBGetTable(AltiooOAuthCode::class);
		$sKey = MetaModel::DBGetKey(AltiooOAuthCode::class);
		CMDBSource::Query("UPDATE `$sTable` SET `grant_id` = -1 WHERE `$sKey` = ".(int) $oCode->GetKey().' AND `grant_id` = 0');
		if (CMDBSource::AffectedRows() !== 1) {
			throw new OAuthException('invalid_grant', 'code already used');
		}

		$oResource = Config::Resources()[$oCode->Get('resource')] ?? null;
		$sChallenge = (string) $oCode->Get('code_challenge');
		if ((int) $oCode->Get('client_id') !== (int) $oClient->GetKey()
			|| $oClient->Get('status') !== 'active'
			|| strtotime((string) $oCode->Get('expiration_date')) < time()
			|| self::Posted('redirect_uri') !== $oCode->Get('redirect_uri')
			|| ($sChallenge === '' ? !AuthorizationController::MayOmitPkce($oClient) : !Pkce::Verify($sChallenge, self::Posted('code_verifier')))
			|| $oResource === null
			|| (self::Posted('resource') !== '' && self::Posted('resource') !== $oResource->sUrl)) {
			throw new OAuthException('invalid_grant', 'the code has expired or does not match this request');
		}

		$oUser = MetaModel::GetObject(User::class, $oCode->Get('user_id'), false, true);
		if (!$oUser instanceof User || !PersonalTokens::IsAllowedFor($oUser) || !$oResource->AllowsUser($oUser)) {
			throw new OAuthException('invalid_grant', 'this account can no longer be connected');
		}

		$aScopes = explode(' ', (string) $oCode->Get('scope'));
		[$oToken, $sAccessToken] = PersonalTokens::Create((int) $oUser->GetKey(), (string) $oClient->Get('client_name'), $aScopes, Config::AccessTokenLifetime());

		$sSecret = Secret::NewSecret();
		$iNow = time();
		$oGrant = MetaModel::NewObject(AltiooOAuthGrant::class, [
			'client_id' => $oClient->GetKey(),
			'user_id' => $oUser->GetKey(),
			'resource' => $oResource->sKey,
			'scope' => $oCode->Get('scope'),
			'personal_token_id' => $oToken->GetKey(),
			'status' => 'active',
			'creation_date' => date('Y-m-d H:i:s', $iNow),
			'expiration_date' => date('Y-m-d H:i:s', $iNow + Config::MaxGrantDays() * 86400),
			'last_refresh_date' => date('Y-m-d H:i:s', $iNow),
			'last_refresh_ip' => self::ClientIp(),
		]);
		$oGrant->Set('refresh_secret', $sSecret);
		$oGrant->AllowWrite();
		$oGrant->DBInsert();
		self::Save($oCode, ['grant_id' => $oGrant->GetKey()]);
		self::Save($oClient, ['last_used_date' => date('Y-m-d H:i:s', $iNow)]);

		self::Issued($sAccessToken, Secret::Join((int) $oGrant->GetKey(), $sSecret), $aScopes);
	}

	private static function FromRefreshToken(AltiooOAuthClient $oClient): never
	{
		$aParts = Secret::Split(self::Posted('refresh_token'));
		$oGrant = $aParts === null ? null : MetaModel::GetObject(AltiooOAuthGrant::class, $aParts[0], false, true);
		if (!$oGrant instanceof AltiooOAuthGrant || $oGrant->Get('status') !== 'active') {
			throw new OAuthException('invalid_grant');
		}
		if ((int) $oGrant->Get('client_id') !== (int) $oClient->GetKey()) {
			throw new OAuthException('invalid_grant');
		}
		if ($oClient->Get('status') !== 'active') {
			Grants::End($oGrant, 'revoked');
			throw new OAuthException('invalid_grant', 'this application has been disabled');
		}

		$sSecret = $aParts[1];
		$iNow = time();
		$iLastRefresh = (int) strtotime((string) $oGrant->Get('last_refresh_date'));
		$bCurrent = $oGrant->Get('refresh_secret')->CheckPassword($sSecret);
		if (!$bCurrent) {
			if (!$oGrant->Get('previous_refresh_secret')->CheckPassword($sSecret)) {
				throw new OAuthException('invalid_grant');
			}
			// A rotated refresh token, presented again. Within the grace period this is the
			// client retrying after a lost response; after it, a copy is in other hands.
			if ($iNow - $iLastRefresh > Config::RefreshGraceSeconds()) {
				Grants::End($oGrant, 'reuse');
				throw new OAuthException('invalid_grant', 'refresh token reused');
			}
		}

		// Both the end fixed when the grant was made and the one today's setting gives: an
		// administrator who lowers max_grant_days means it for connections already made.
		$iCreated = (int) strtotime((string) $oGrant->Get('creation_date'));
		if (strtotime((string) $oGrant->Get('expiration_date')) < $iNow || $iCreated + Config::MaxGrantDays() * 86400 < $iNow) {
			Grants::End($oGrant, 'expired');
			throw new OAuthException('invalid_grant', 'the connection has expired');
		}
		if ($iLastRefresh + Config::GrantIdleDays() * 86400 < $iNow) {
			Grants::End($oGrant, 'idle');
			throw new OAuthException('invalid_grant', 'the connection was unused for too long');
		}
		$oResource = Config::Resources()[$oGrant->Get('resource')] ?? null;
		$oUser = MetaModel::GetObject(User::class, $oGrant->Get('user_id'), false, true);
		if (!$oUser instanceof User || !PersonalTokens::IsAllowedFor($oUser) || $oResource === null || !$oResource->AllowsUser($oUser)) {
			Grants::End($oGrant, 'account');
			throw new OAuthException('invalid_grant', 'this account can no longer be connected');
		}
		// The scopes as today's policy allows them: an administrator who narrows a resource's
		// offer, or forces a scope, means it for connections already made.
		$aScopes = $oResource->oScopes->Current(explode(' ', (string) $oGrant->Get('scope')));
		if ($aScopes === []) {
			Grants::End($oGrant, 'revoked');
			throw new OAuthException('invalid_grant', 'nothing granted is offered any more');
		}
		// Deleted under My Account: the person disconnected.
		$oToken = PersonalTokens::Find((int) $oGrant->Get('personal_token_id'));
		if ($oToken === null) {
			Grants::End($oGrant, 'disconnected');
			throw new OAuthException('invalid_grant', 'the connection was ended');
		}

		$sAccessToken = PersonalTokens::Renew($oToken, (string) $oClient->Get('client_name'), Config::AccessTokenLifetime(), $aScopes);
		$sNewSecret = Secret::NewSecret();
		// Always the current one, also when the previous one was presented in the grace period.
		// Keeping the older one there instead would let whoever presented it take the grant
		// over: the legitimate client's token would then be neither, and its next refresh an
		// ordinary refusal rather than a detected reuse.
		$oGrant->Set('previous_refresh_secret', $oGrant->Get('refresh_secret'));
		$oGrant->Set('refresh_secret', $sNewSecret);
		$oGrant->Set('scope', implode(' ', $aScopes));
		$oGrant->Set('last_refresh_date', date('Y-m-d H:i:s', $iNow));
		$oGrant->Set('last_refresh_ip', self::ClientIp());
		$oGrant->AllowWrite();
		$oGrant->DBUpdate();
		self::Save($oClient, ['last_used_date' => date('Y-m-d H:i:s', $iNow)]);

		self::Issued($sAccessToken, Secret::Join((int) $oGrant->GetKey(), $sNewSecret), $aScopes);
	}

	/**
	 * @param list<string> $aScopes
	 */
	private static function Issued(string $sAccessToken, string $sRefreshToken, array $aScopes): never
	{
		Http::Json(200, [
			'access_token' => $sAccessToken,
			'token_type' => 'Bearer',
			'expires_in' => Config::AccessTokenLifetime(),
			'refresh_token' => $sRefreshToken,
			'scope' => implode(' ', $aScopes),
		]);
	}

	/**
	 * @param object $oObject a DBObject. Typed object only: Psalm runs without iTop, and cannot
	 *     see that the classes compiled from the datamodel are DBObjects
	 * @param array<string, mixed> $aValues
	 */
	private static function Save(object $oObject, array $aValues): void
	{
		foreach ($aValues as $sAttCode => $value) {
			$oObject->Set($sAttCode, $value);
		}
		$oObject->AllowWrite();
		$oObject->DBUpdate();
	}

	private static function Posted(string $sName): string
	{
		$value = $_POST[$sName] ?? '';

		return is_string($value) ? $value : '';
	}

	private static function ClientIp(): string
	{
		return mb_substr(is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '', 0, 45);
	}
}
