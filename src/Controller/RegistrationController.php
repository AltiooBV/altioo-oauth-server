<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Controller;

use Altioo\iTop\Extension\OAuthServer\Config;
use Altioo\iTop\Extension\OAuthServer\Exception\OAuthException;
use Altioo\iTop\Extension\OAuthServer\Http;
use Altioo\iTop\Extension\OAuthServer\RedirectUriPolicy;
use Altioo\iTop\Extension\OAuthServer\Secret;
use DBObjectSearch;
use DBObjectSet;
use MetaModel;
use AltiooOAuthClient;

/**
 * Dynamic client registration (RFC 7591), for public clients only. Anyone may call it; what
 * keeps it from being useful to an attacker is that a client can only ever send people back
 * to an address on the administrator's allowlist.
 *
 * @since 0.1.0
 */
final class RegistrationController
{
	public static function Register(): never
	{
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
			throw new OAuthException('invalid_request', 'POST only', 405);
		}
		$aBody = json_decode((string) file_get_contents('php://input', false, null, 0, 65536), true);
		if (!is_array($aBody)) {
			throw new OAuthException('invalid_client_metadata', 'the body must be a JSON object');
		}

		$aUris = $aBody['redirect_uris'] ?? null;
		if (!is_array($aUris) || $aUris === [] || count($aUris) > 10) {
			throw new OAuthException('invalid_redirect_uri', 'redirect_uris must list one to ten URIs');
		}
		$oPolicy = new RedirectUriPolicy(Config::RedirectUris(), Config::AllowLoopback());
		foreach ($aUris as $sUri) {
			if (!is_string($sUri) || !$oPolicy->IsAllowed($sUri)) {
				throw new OAuthException('invalid_redirect_uri', 'this server does not allow that redirect URI');
			}
		}
		if (($aBody['token_endpoint_auth_method'] ?? 'none') !== 'none') {
			throw new OAuthException('invalid_client_metadata', 'only public clients are registered: token_endpoint_auth_method must be none');
		}
		foreach ((array) ($aBody['grant_types'] ?? []) as $sGrantType) {
			if (!in_array($sGrantType, ['authorization_code', 'refresh_token'], true)) {
				throw new OAuthException('invalid_client_metadata', 'unsupported grant type');
			}
		}

		$sIp = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '';
		self::CheckRateLimit($sIp);

		$sName = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($aBody['client_name'] ?? '')) ?? '');
		$sName = mb_substr($sName === '' ? 'Unnamed application' : $sName, 0, 80);
		$sClientId = Secret::NewClientId();
		$iNow = time();

		$oClient = MetaModel::NewObject(AltiooOAuthClient::class, [
			'client_id' => $sClientId,
			'client_name' => $sName,
			'redirect_uris' => json_encode(array_values($aUris), JSON_UNESCAPED_SLASHES),
			'source' => 'dcr',
			'auth_method' => 'none',
			'pkce' => 'required',
			'status' => 'active',
			'registered_from_ip' => mb_substr($sIp, 0, 45),
			'creation_date' => date('Y-m-d H:i:s', $iNow),
		]);
		$oClient->AllowWrite();
		$oClient->DBInsert();

		Http::Json(201, [
			'client_id' => $sClientId,
			'client_id_issued_at' => $iNow,
			'client_name' => $sName,
			'redirect_uris' => array_values($aUris),
			'token_endpoint_auth_method' => 'none',
			'grant_types' => ['authorization_code', 'refresh_token'],
			'response_types' => ['code'],
		]);
	}

	private static function CheckRateLimit(string $sIp): void
	{
		$oSearch = DBObjectSearch::FromOQL('SELECT AltiooOAuthClient WHERE registered_from_ip = :ip AND creation_date > :since');
		$oSearch->AllowAllData();
		$oSet = new DBObjectSet($oSearch, [], ['ip' => $sIp, 'since' => date('Y-m-d H:i:s', time() - 3600)]);
		if ($oSet->Count() >= Config::RegistrationRateLimit()) {
			throw new OAuthException('invalid_request', 'too many registrations from this address; try again later', 429);
		}
	}
}
