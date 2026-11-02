<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer;

/**
 * A protected resource: an endpoint that accepts a PersonalToken, the URL clients name in the
 * resource parameter (RFC 8707), and the scopes a person may grant for it.
 *
 * @since 0.1.0
 */
final class ProtectedResource
{
	/**
	 * @param list<string> $aRequiredProfiles the resource refuses anyone holding none of these;
	 *     empty when it refuses nobody on profile grounds
	 * @param list<array{label: string, scopes: list<string>}> $aGrades choices the consent page
	 *     offers as one radio button each, instead of a checkbox per scope
	 * @param list<array{label: string, scopes: list<string>}> $aLimits choices that narrow what
	 *     the chosen grade allows, offered as a checkbox each under their own heading
	 */
	public function __construct(
		public readonly string $sKey,
		public readonly string $sUrl,
		public readonly ScopePolicy $oScopes,
		public readonly array $aRequiredProfiles = [],
		public readonly array $aGrades = [],
		public readonly array $aLimits = []
	)
	{
	}

	/**
	 * Whether the resource would accept a token of this account at all, checked before consent
	 * so that a connection that could only fail is refused with a reason instead.
	 *
	 * @param list<string> $aProfiles the account's profile names
	 */
	public function AcceptsProfiles(array $aProfiles): bool
	{
		return $this->aRequiredProfiles === [] || array_intersect($this->aRequiredProfiles, $aProfiles) !== [];
	}

	/**
	 * Every scope one of the resource's limits names: scopes that narrow a connection, and are
	 * never a grant on their own.
	 *
	 * @return list<string>
	 */
	public function LimitScopes(): array
	{
		return array_values(array_unique(array_merge([], ...array_column($this->aLimits, 'scopes'))));
	}

	public function AllowsUser(\User $oUser): bool
	{
		$aProfiles = [];
		foreach ((array) \UserRights::ListProfiles($oUser) as $sProfile) {
			$aProfiles[] = (string) $sProfile;
		}

		return $this->AcceptsProfiles($aProfiles);
	}
}
