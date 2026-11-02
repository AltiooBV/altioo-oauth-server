<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer;

use AltiooOAuthGrant;
use MetaModel;

/**
 * Which resource a PersonalToken was granted for, for a protected resource to ask before it
 * serves a call: a token a person granted for one resource URL must not be accepted at
 * another, as when two resources are one endpoint scoped by a query (RFC 8707).
 *
 * A token no connection owns, such as one an administrator minted by hand, has no audience
 * here; whether the resource accepts it is the resource's own rule, not this module's.
 *
 * A resource that may run without this module installed asks
 * class_exists(TokenAudience::class) first.
 *
 * @api
 * @since 0.1.0
 */
final class TokenAudience
{
	/**
	 * @param string $sResourceKey the resource's key in the resources setting
	 * @param string $sResourceUrl the URL it was granted for; empty when that resource is no
	 *     longer configured, which IsFor() then never matches
	 */
	public function __construct(
		public readonly string $sResourceKey,
		public readonly string $sResourceUrl
	)
	{
	}

	/**
	 * The audience of the token with this key, or null when no connection owns it.
	 */
	public static function Of(int $iPersonalTokenId): ?self
	{
		// An ended connection keeps 0 here, so it owns no token.
		if ($iPersonalTokenId <= 0) {
			return null;
		}
		// The resource asks as the token's owner, who has no right on this class.
		$oGrant = MetaModel::GetObjectByColumn(AltiooOAuthGrant::class, 'personal_token_id', $iPersonalTokenId, false, true);
		if (!$oGrant instanceof AltiooOAuthGrant) {
			return null;
		}

		return self::ForResource((string) $oGrant->Get('resource'), Config::Resources());
	}

	/**
	 * @param array<string, ProtectedResource> $aResources the resources configured now
	 */
	public static function ForResource(string $sKey, array $aResources): self
	{
		return new self($sKey, isset($aResources[$sKey]) ? $aResources[$sKey]->sUrl : '');
	}

	/**
	 * Whether the token was granted for this URL, compared exactly, as the authorization
	 * request's resource parameter was.
	 */
	public function IsFor(string $sUrl): bool
	{
		return $this->sResourceUrl !== '' && $this->sResourceUrl === $sUrl;
	}
}
