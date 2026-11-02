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
use Altioo\iTop\Extension\OAuthServer\Service\Accounts;
use Altioo\iTop\Extension\OAuthServer\Service\Grants;
use Altioo\iTop\Extension\OAuthServer\Service\PersonalTokens;
use CMDBSource;
use Combodo\iTop\AuthentToken\Service\AuthentTokenService;
use DBObjectSearch;
use DBObjectSet;
use MetaModel;
use AltiooOAuthCode;
use AltiooOAuthClient;
use AltiooOAuthGrant;
use PersonalToken;
use Throwable;
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
	 * Revocation (RFC 7009), for a client signing out or being removed: presenting either of a
	 * connection's tokens ends the connection, which deletes the access token and voids the
	 * refresh token. The two cannot be mistaken for each other, so the hint is not needed to
	 * find which was sent: a refresh token is "<grant key>.<secret>", an access token is
	 * authent-token's encrypted value, which holds no dot.
	 *
	 * A token that is unknown, already ended, or another client's is answered as a revoked one
	 * and left as it is: the RFC asks no error for an invalid token, since the client could do
	 * nothing with it, and the answer then tells nobody whether a connection exists.
	 */
	public static function Revoke(): never
	{
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
			throw new OAuthException('invalid_request', 'POST only', 405);
		}
		$oClient = self::AuthenticatedClient();
		$sToken = self::Posted('token');
		if ($sToken === '') {
			throw new OAuthException('invalid_request', 'token is missing');
		}
		$aParts = Secret::Split($sToken);
		$oGrant = $aParts === null ? self::GrantOfAccessToken($oClient, $sToken) : self::GrantOfRefreshToken($oClient, $aParts);
		if ($oGrant !== null) {
			Grants::End($oGrant, 'client_revoked');
		}

		Http::Json(200, []);
	}

	/**
	 * Ownership before the secret, as for a code: CheckPassword is a bcrypt comparison.
	 *
	 * @param array{0: int, 1: string} $aParts
	 */
	private static function GrantOfRefreshToken(AltiooOAuthClient $oClient, array $aParts): ?AltiooOAuthGrant
	{
		$oGrant = MetaModel::GetObject(AltiooOAuthGrant::class, $aParts[0], false, true);
		if (!$oGrant instanceof AltiooOAuthGrant || $oGrant->Get('status') !== 'active'
			|| (int) $oGrant->Get('client_id') !== (int) $oClient->GetKey()) {
			return null;
		}

		return $oGrant->Get('refresh_secret')->CheckPassword($aParts[1]) || $oGrant->Get('previous_refresh_secret')->CheckPassword($aParts[1]) ? $oGrant : null;
	}

	/**
	 * The value names its PersonalToken once decrypted, which costs no hash; the grant that owns
	 * that token is then looked up for this client, and only then is the value checked against
	 * the token's stored hash, so that a value it no longer has ends nothing.
	 */
	private static function GrantOfAccessToken(AltiooOAuthClient $oClient, string $sToken): ?AltiooOAuthGrant
	{
		$oToken = (new AuthentTokenService())->DecryptToken($sToken);
		if (!$oToken instanceof PersonalToken) {
			return null;
		}
		$oSearch = DBObjectSearch::FromOQL("SELECT AltiooOAuthGrant WHERE personal_token_id = :token AND client_id = :client AND status = 'active'");
		$oSearch->AllowAllData();
		$oGrant = (new DBObjectSet($oSearch, [], ['token' => (int) $oToken->GetKey(), 'client' => (int) $oClient->GetKey()]))->Fetch();
		if (!$oGrant instanceof AltiooOAuthGrant) {
			return null;
		}

		return $oToken->Get('auth_token')->CheckPassword($sToken) ? $oGrant : null;
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
		if (!$oCode instanceof AltiooOAuthCode) {
			throw new OAuthException('invalid_grant', 'unknown code');
		}
		// Refuse a code that is not this client's before verifying its secret: CheckPassword is a
		// bcrypt comparison, and an unauthenticated caller must not be able to spend one on a code
		// it does not own. A non-owning caller gets the same answer as for a code that never
		// existed, so this reveals nothing either.
		if ((int) $oCode->Get('client_id') !== (int) $oClient->GetKey()) {
			throw new OAuthException('invalid_grant', 'unknown code');
		}
		if (!$oCode->Get('code_secret')->CheckPassword($aParts[1])) {
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
			'console_access' => $oCode->Get('console_access'),
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
		// Allowed as a portal account and since given the console, or the other way round: the
		// person consented to one kind of connection, was offered that kind's grades, and what the
		// resource now serves under the same grade is the other's. Consent again. Other profile
		// changes need none: the resource reads the account's profiles and portal on every call.
		$sAccess = (string) $oGrant->Get('console_access');
		$bConsole = Accounts::HasConsole($oUser);
		if (Accounts::KindChanged($sAccess, $bConsole)) {
			Grants::End($oGrant, 'access_changed');
			throw new OAuthException('invalid_grant', $bConsole ? 'the account was given the console; connect again' : 'the account no longer has the console; connect again');
		}
		$oResource = $oResource->ForAccount($sAccess !== 'no');
		// The scopes as today's policy allows them: an administrator who narrows a resource's
		// offer, or forces a scope, means it for connections already made. A connection left
		// with nothing but limits ends, as consent refuses one.
		$aScopes = $oResource->oScopes->Current(explode(' ', (string) $oGrant->Get('scope')), $oResource->LimitScopes());
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
		// One transaction from the claim to the last write. Should anything in between fail, the
		// claim is undone with the rest, rather than leaving an active connection whose stored
		// hash no refresh token matches, which nothing could renew and nothing would explain.
		CMDBSource::Query('START TRANSACTION');
		try {
			self::ClaimRotation($oGrant);

			$sAccessToken = PersonalTokens::Renew($oToken, (string) $oClient->Get('client_name'), Config::AccessTokenLifetime(), $aScopes);
			$sNewSecret = Secret::NewSecret();
			// Always the current one, also when the previous one was presented in the grace
			// period. Keeping the older one there instead would let whoever presented it take the
			// grant over: the legitimate client's token would then be neither, and its next
			// refresh an ordinary refusal rather than a detected reuse.
			$oGrant->Set('previous_refresh_secret', $oGrant->Get('refresh_secret'));
			$oGrant->Set('refresh_secret', $sNewSecret);
			$oGrant->Set('scope', implode(' ', $aScopes));
			$oGrant->Set('last_refresh_date', date('Y-m-d H:i:s', $iNow));
			$oGrant->Set('last_refresh_ip', self::ClientIp());
			$oGrant->AllowWrite();
			$oGrant->DBUpdate();
			self::Save($oClient, ['last_used_date' => date('Y-m-d H:i:s', $iNow)]);
			CMDBSource::Query('COMMIT');
		} catch (Throwable $e) {
			// A write that failed may have rolled back already, and a ROLLBACK outside any
			// transaction throws, which would hide why.
			if (CMDBSource::IsInsideTransaction()) {
				CMDBSource::Query('ROLLBACK');
			}
			throw $e;
		}

		self::Issued($sAccessToken, Secret::Join((int) $oGrant->GetKey(), $sNewSecret), $aScopes);
	}

	/**
	 * Claimed with a conditional update, as a code is: of two refreshes that got past the checks
	 * with one refresh token at the same moment, only one rotates it. Without this both would,
	 * the last write would win, and the other side's new refresh token would match nothing: an
	 * ordinary refusal later rather than a detected reuse, and a stolen copy that could keep
	 * the grant. The loser is refused now, and may retry with the same token within the grace
	 * period, where it is the previous one.
	 *
	 * The stored hash is what is compared, and a value no password matches what is written: the
	 * update always changes the row, so the affected-row count answers, even within the second
	 * of the previous refresh. The rotation's own write replaces it straight after, in the same
	 * transaction. Inside it, a concurrent claim waits on the row and then finds the hash
	 * changed.
	 *
	 * The column is AttributeOneWayPassword's "<code>_hash", which is iTop's storage naming
	 * rather than an API. The HTTP smoke refreshes several times, so a branch that renames it
	 * fails there.
	 */
	private static function ClaimRotation(AltiooOAuthGrant $oGrant): void
	{
		$sTable = MetaModel::DBGetTable(AltiooOAuthGrant::class);
		$sKey = MetaModel::DBGetKey(AltiooOAuthGrant::class);
		$sHash = CMDBSource::Quote((string) $oGrant->Get('refresh_secret')->GetHash());
		CMDBSource::Query("UPDATE `$sTable` SET `refresh_secret_hash` = CONCAT(`refresh_secret_hash`, '~rotating')"
			." WHERE `$sKey` = ".(int) $oGrant->GetKey()." AND `refresh_secret_hash` = $sHash AND `status` = 'active'");
		if (CMDBSource::AffectedRows() !== 1) {
			throw new OAuthException('invalid_grant', 'refreshed concurrently; retry with the same refresh token');
		}
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
