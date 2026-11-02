<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Controller;

use Altioo\iTop\Extension\OAuthServer\Config;
use Altioo\iTop\Extension\OAuthServer\Http;

/**
 * Discovery: the authorization server metadata (RFC 8414), served at the module's
 * .well-known/openid-configuration and .well-known/oauth-authorization-server, and one
 * protected resource metadata document (RFC 9728) per resource.
 *
 * @since 0.1.0
 */
final class MetadataController
{
	/**
	 * Deliberately no jwks_uri, subject_types_supported or id_token_*: this is not an OpenID
	 * provider. Clients look for an OAuth server at the OIDC path too, and read only the
	 * OAuth fields there.
	 */
	public static function ServerMetadata(): never
	{
		$aScopes = [];
		foreach (Config::Resources() as $oResource) {
			$aScopes = array_merge($aScopes, $oResource->oScopes->Offered([]), $oResource->oScopes->Forced());
		}

		Http::Json(200, [
			'issuer' => Config::Issuer(),
			'authorization_endpoint' => Config::Endpoint('authorize'),
			'token_endpoint' => Config::Endpoint('token'),
			'registration_endpoint' => Config::Endpoint('register'),
			'response_types_supported' => ['code'],
			'response_modes_supported' => ['query'],
			'grant_types_supported' => ['authorization_code', 'refresh_token'],
			'code_challenge_methods_supported' => ['S256'],
			// none for the clients that register themselves; a secret for the ones an
			// administrator creates.
			'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
			'revocation_endpoint' => Config::Endpoint('revoke'),
			'revocation_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
			'authorization_response_iss_parameter_supported' => true,
			'scopes_supported' => array_values(array_unique($aScopes)),
		], Http::CACHE_DISCOVERY);
	}

	public static function ProtectedResource(string $sKey): never
	{
		$aResources = Config::Resources();
		if (!isset($aResources[$sKey])) {
			Http::OAuthError('invalid_request', 'unknown resource', 404);
		}
		$oResource = $aResources[$sKey];

		Http::Json(200, [
			'resource' => $oResource->sUrl,
			'authorization_servers' => [Config::Issuer()],
			'scopes_supported' => array_values(array_unique(array_merge($oResource->oScopes->Offered([]), $oResource->oScopes->Forced()))),
			'bearer_methods_supported' => $oResource->aBearerMethods,
		], Http::CACHE_DISCOVERY);
	}
}
