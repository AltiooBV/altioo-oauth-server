<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Controller;

use Altioo\iTop\Extension\OAuthServer\Config;
use Altioo\iTop\Extension\OAuthServer\Exception\PageException;
use Altioo\iTop\Extension\OAuthServer\Grades;
use Altioo\iTop\Extension\OAuthServer\Http;
use Altioo\iTop\Extension\OAuthServer\PendingRequests;
use Altioo\iTop\Extension\OAuthServer\Pkce;
use Altioo\iTop\Extension\OAuthServer\ProtectedResource;
use Altioo\iTop\Extension\OAuthServer\RedirectUriList;
use Altioo\iTop\Extension\OAuthServer\Secret;
use Altioo\iTop\Extension\OAuthServer\Service\PersonalTokens;
use Combodo\iTop\Application\Helper\Session;
use Dict;
use LoginWebPage;
use MetaModel;
use AltiooOAuthCode;
use AltiooOAuthClient;
use PersonalToken;
use User;
use UserRights;
use utils;

/**
 * The browser leg: check the request, sign the person in through iTop's own login, ask for
 * consent, send them back with a code.
 *
 * The request is checked once, before the login, and kept in the session under a random id.
 * Only that id crosses the login round trip, which may go through an identity provider and
 * back, so nothing the client sent can be altered on the way.
 *
 * @since 0.1.0
 */
final class AuthorizationController
{
	private const SESSION_KEY = 'altioo-oauth-server';
	private const REQUEST_LIFETIME = 600;
	/** Requests one session may have pending: a person rarely starts more than one or two. */
	private const MAX_PENDING_REQUESTS = 10;

	public static function Authorize(): never
	{
		$sRequestId = utils::ReadParam('req', '', false, 'raw_data');
		if ($sRequestId === '') {
			Http::Redirect(Config::Endpoint('authorize', ['req' => self::CheckRequest()]));
		}
		if (preg_match('/^[a-f0-9]{32}$/D', $sRequestId) !== 1) {
			throw new PageException('UI:AltiooOAuthServer:Page:RequestExpired');
		}
		$aRequest = Session::Get([self::SESSION_KEY, $sRequestId]);
		if (!is_array($aRequest) || $aRequest['expires'] < time()) {
			throw new PageException('UI:AltiooOAuthServer:Page:RequestExpired');
		}

		// An ordinary console login: every login mode and MFA policy of the instance applies.
		// No portal is requested: DoLogin(false, true) asks for the legacy portal and sends
		// everyone else away to their own. Portal users get through only to be told, below,
		// why they cannot connect.
		LoginWebPage::DoLoginEx(null, false, LoginWebPage::EXIT_PROMPT);
		$oUser = UserRights::GetUserObject();
		if (!$oUser instanceof User) {
			throw new PageException('UI:AltiooOAuthServer:Page:RequestExpired');
		}
		if (UserRights::IsPortalUser()) {
			throw new PageException('UI:AltiooOAuthServer:Page:PortalUser', 403);
		}
		if (!PersonalTokens::IsAllowedFor($oUser)) {
			throw new PageException('UI:AltiooOAuthServer:Page:TokensNotAllowed', 403);
		}

		$oClient = self::FindClient($aRequest['client_id']);
		$oResource = Config::Resources()[$aRequest['resource']] ?? null;
		if ($oClient === null || $oResource === null) {
			throw new PageException('UI:AltiooOAuthServer:Page:RequestExpired');
		}
		// The resource would refuse every call with this account's token: say so now, rather
		// than let the connection succeed and then fail.
		if (!$oResource->AllowsUser($oUser)) {
			throw new PageException('UI:AltiooOAuthServer:Page:ResourceNotAllowed', 403);
		}
		$aOffered = $oResource->oScopes->Offered($aRequest['scope']);

		// Not the request method: iTop's login form posts back to this same URL, so a POST is
		// also what arrives right after a form login.
		if (isset($_POST['decision'])) {
			if (!utils::IsTransactionValid(utils::ReadPostedParam('transaction_id', '', 'transaction_id'))) {
				throw new PageException('UI:AltiooOAuthServer:Page:RequestExpired');
			}
			Session::Unset([self::SESSION_KEY, $sRequestId]);
			self::Answer($aRequest, $oClient, $oResource, $oUser, $aOffered);
		}

		self::ShowConsent($sRequestId, $aRequest, $oClient, $oResource, $oUser, $aOffered);
	}

	/**
	 * Every refusal here is a page, never a redirect: until the client and its redirect URI are
	 * known to match, the redirect URI is the attacker's.
	 *
	 * @return string the id the request is kept under
	 */
	private static function CheckRequest(): string
	{
		$sRedirectUri = utils::ReadParam('redirect_uri', '', false, 'raw_data');
		$oClient = self::FindClient(utils::ReadParam('client_id', '', false, 'raw_data'));
		if ($oClient === null || !in_array($sRedirectUri, self::RedirectUris($oClient), true)) {
			throw new PageException('UI:AltiooOAuthServer:Page:UnknownClient');
		}
		$sChallenge = utils::ReadParam('code_challenge', '', false, 'raw_data');
		$sMethod = utils::ReadParam('code_challenge_method', '', false, 'raw_data');
		$bWithoutPkce = $sChallenge === '' && $sMethod === '' && self::MayOmitPkce($oClient);
		if (utils::ReadParam('response_type', '', false, 'raw_data') !== 'code'
			|| (!$bWithoutPkce && ($sMethod !== 'S256' || !Pkce::IsValidChallenge($sChallenge)))) {
			throw new PageException('UI:AltiooOAuthServer:Page:InvalidRequest');
		}
		$oResource = self::RequestedResource(utils::ReadParam('resource', '', false, 'raw_data'), $oClient);
		if ($oResource === null) {
			throw new PageException('UI:AltiooOAuthServer:Page:UnknownResource');
		}

		$sRequestId = bin2hex(random_bytes(16));
		$sScope = utils::ReadParam('scope', '', false, 'raw_data');
		self::PrunePendingRequests();
		Session::Set([self::SESSION_KEY, $sRequestId], [
			'client_id' => $oClient->Get('client_id'),
			'redirect_uri' => $sRedirectUri,
			'code_challenge' => $sChallenge,
			'state' => mb_substr(utils::ReadParam('state', '', false, 'raw_data'), 0, 1024),
			'resource' => $oResource->sKey,
			'scope' => array_values(array_filter(explode(' ', $sScope))),
			'expires' => time() + self::REQUEST_LIFETIME,
		]);

		return $sRequestId;
	}

	/**
	 * Expired requests go, and the oldest ones beyond the cap: anyone can start requests, and a
	 * session that only ever grew would be loaded and written whole on every request.
	 */
	private static function PrunePendingRequests(): void
	{
		// One fewer than the cap: the request about to be added is the last one.
		Session::Set(self::SESSION_KEY, PendingRequests::Prune(Session::Get(self::SESSION_KEY, []), time(), self::MAX_PENDING_REQUESTS - 1));
	}

	/**
	 * RFC 8707 lets a client leave resource out, and a classic one always does. The client's
	 * default resource answers then; failing that, the only resource configured, if there is
	 * only one.
	 */
	private static function RequestedResource(string $sUrl, AltiooOAuthClient $oClient): ?ProtectedResource
	{
		if ($sUrl !== '') {
			return Config::ResourceByUrl($sUrl);
		}
		$aResources = Config::Resources();
		$sDefault = (string) $oClient->Get('default_resource');
		if ($sDefault !== '') {
			return $aResources[$sDefault] ?? null;
		}

		return count($aResources) === 1 ? reset($aResources) : null;
	}

	/**
	 * Only a confidential client, whose administrator allowed it: the secret it presents at the
	 * token endpoint then binds the code to it, as PKCE would.
	 */
	public static function MayOmitPkce(AltiooOAuthClient $oClient): bool
	{
		return $oClient->Get('auth_method') !== 'none' && $oClient->Get('pkce') === 'optional';
	}

	/**
	 * @param array<string, mixed> $aRequest
	 * @param list<string> $aOffered
	 */
	private static function Answer(array $aRequest, AltiooOAuthClient $oClient, ProtectedResource $oResource, User $oUser, array $aOffered): never
	{
		$aBack = ['state' => $aRequest['state'], 'iss' => Config::Issuer()];
		if ($aRequest['state'] === '') {
			unset($aBack['state']);
		}
		if (utils::ReadPostedParam('decision', '', 'raw_data') !== 'allow') {
			Http::Redirect(self::WithQuery($aRequest['redirect_uri'], ['error' => 'access_denied'] + $aBack));
		}
		$aTicked = array_map('strval', (array) ($_POST['scopes'] ?? []));
		$aGrades = Grades::Usable($oResource->aGrades, $aOffered);
		$sGrade = utils::ReadPostedParam('grade', '', 'raw_data');
		if (isset($aGrades[$sGrade])) {
			$aTicked = array_merge($aTicked, $aGrades[$sGrade]['scopes']);
		}
		$aGranted = $oResource->oScopes->Granted($aOffered, $aTicked);
		if ($aGranted === []) {
			throw new PageException('UI:AltiooOAuthServer:Page:NothingSelected');
		}

		$sSecret = Secret::NewSecret();
		$oCode = MetaModel::NewObject(AltiooOAuthCode::class, [
			'client_id' => $oClient->GetKey(),
			'user_id' => $oUser->GetKey(),
			'redirect_uri' => $aRequest['redirect_uri'],
			'code_challenge' => $aRequest['code_challenge'],
			'resource' => $oResource->sKey,
			'scope' => implode(' ', $aGranted),
			'expiration_date' => date('Y-m-d H:i:s', time() + Config::CodeLifetime()),
		]);
		$oCode->Set('code_secret', $sSecret);
		$oCode->AllowWrite();
		$oCode->DBInsert();

		Http::Redirect(self::WithQuery($aRequest['redirect_uri'], ['code' => Secret::Join((int) $oCode->GetKey(), $sSecret)] + $aBack));
	}

	/**
	 * @param array<string, mixed> $aRequest
	 * @param list<string> $aOffered
	 */
	private static function ShowConsent(string $sRequestId, array $aRequest, AltiooOAuthClient $oClient, ProtectedResource $oResource, User $oUser, array $aOffered): never
	{
		$aPreselected = $oResource->oScopes->Preselected($aOffered);
		$aGrades = Grades::Usable($oResource->aGrades, $aOffered);
		$sScopes = '';
		if ($aGrades !== []) {
			// One radio per grade; the scopes no grade covers (a rehearsal mode, a toolset) stay
			// checkboxes below, unticked.
			$iChosen = Grades::Chosen($aGrades, $aPreselected);
			foreach ($aGrades as $iIndex => $aGrade) {
				$sChecked = $iIndex === $iChosen ? ' checked' : '';
				$sScopes .= '<label><input type="radio" name="grade" value="'.$iIndex.'"'.$sChecked.'> '
					.Http::Escape(Dict::S($aGrade['label'])).'</label>';
			}
			$aPreselected = [];
		}
		$aExtras = Grades::Uncovered($aGrades, $aOffered);
		if ($aGrades !== [] && $aExtras !== []) {
			$sScopes .= '<p class="note">'.Http::Escape(Dict::S('UI:AltiooOAuthServer:Consent:Also')).'</p>';
		}
		foreach ($aExtras as $sScope) {
			$sChecked = in_array($sScope, $aPreselected, true) ? ' checked' : '';
			$sScopes .= '<label><input type="checkbox" name="scopes[]" value="'.Http::Escape($sScope).'"'.$sChecked.'> '
				.Http::Escape(self::ScopeLabel($sScope)).'</label>';
		}
		$aForced = $oResource->oScopes->Forced();
		$sForced = $aForced === [] ? '' : '<p>'.Http::Escape(Dict::Format('UI:AltiooOAuthServer:Consent:Forced',
			implode(', ', array_map([self::class, 'ScopeLabel'], $aForced)))).'</p>';

		$sName = Http::Escape($oClient->Get('client_name'));
		$sLogin = Http::Escape($oUser->Get('login'));
		$sHost = Http::Escape((string) parse_url($aRequest['redirect_uri'], PHP_URL_HOST));
		$sAction = Http::Escape(Config::Endpoint('authorize', ['req' => $sRequestId]));
		$sTransaction = Http::Escape((string) utils::GetNewTransactionId());

		Http::Page(200, Dict::S('UI:AltiooOAuthServer:Consent:Title'),
			'<p>'.Dict::Format('UI:AltiooOAuthServer:Consent:Who', "<strong>$sName</strong>", "<strong>$sLogin</strong>").'</p>'
			.'<p class="note">'.Http::Escape(Dict::S('UI:AltiooOAuthServer:Consent:SelfDeclared')).'</p>'
			.'<p><strong>'.Http::Escape(Dict::S('UI:AltiooOAuthServer:Consent:OnlyYours')).'</strong></p>'
			.'<p>'.Http::Escape(Dict::S('UI:AltiooOAuthServer:Consent:ReturnTo')).'</p>'
			."<p class=\"host\">$sHost</p>"
			.'<p>'.Http::Escape(Dict::Format('UI:AltiooOAuthServer:Consent:Resource', $oResource->sUrl)).'</p>'
			."<form method=\"post\" action=\"$sAction\">"
			."<input type=\"hidden\" name=\"transaction_id\" value=\"$sTransaction\">"
			.'<fieldset><legend>'.Http::Escape(Dict::S('UI:AltiooOAuthServer:Consent:Permissions')).'</legend>'.$sScopes.'</fieldset>'
			.$sForced
			.'<p>'.Http::Escape(Dict::Format('UI:AltiooOAuthServer:Consent:Lifetime', Config::MaxGrantDays(), Config::GrantIdleDays())).'</p>'
			.'<p><button type="submit" name="decision" value="allow">'.Http::Escape(Dict::S('UI:AltiooOAuthServer:Consent:Allow')).'</button>'
			.'<button type="submit" name="decision" value="deny">'.Http::Escape(Dict::S('UI:AltiooOAuthServer:Consent:Deny')).'</button></p>'
			.'</form>'
			.'<p class="note">'.Http::Escape(Dict::S('UI:AltiooOAuthServer:Consent:Disconnect')).'</p>');
	}

	public static function ScopeLabel(string $sScope): string
	{
		$sLabel = Dict::S('Class:PersonalToken/Attribute:scope/Value:'.$sScope, '');

		return $sLabel === '' ? $sScope : $sLabel;
	}

	/**
	 * @param bool $bActiveOnly false at the token endpoint, which ends the grants of a disabled
	 *     client rather than only refusing it
	 */
	public static function FindClient(string $sClientId, bool $bActiveOnly = true): ?AltiooOAuthClient
	{
		if (preg_match('/^[a-f0-9]{32}$/D', $sClientId) !== 1) {
			return null;
		}
		$oClient = MetaModel::GetObjectByColumn(AltiooOAuthClient::class, 'client_id', $sClientId, false, true);
		if (!$oClient instanceof AltiooOAuthClient) {
			return null;
		}

		return !$bActiveOnly || $oClient->Get('status') === 'active' ? $oClient : null;
	}

	/**
	 * @return list<string>
	 */
	public static function RedirectUris(AltiooOAuthClient $oClient): array
	{
		return RedirectUriList::Parse((string) $oClient->Get('redirect_uris'));
	}

	private static function WithQuery(string $sUri, array $aQuery): string
	{
		return $sUri.(str_contains($sUri, '?') ? '&' : '?').http_build_query($aQuery);
	}
}
