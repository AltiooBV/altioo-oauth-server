<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer;

use MetaModel;
use PersonalToken;
use utils;

/**
 * The module's settings, each with the default it has when nobody set it. The defaults are
 * also declared in datamodel.altioo-oauth-server.xml, where an administrator reads them.
 *
 * @since 0.1.0
 */
final class Config
{
	public const MODULE = 'altioo-oauth-server';

	public static function Issuer(): string
	{
		return rtrim(utils::GetAbsoluteUrlModulesRoot(), '/').'/'.self::MODULE;
	}

	public static function Endpoint(string $sRoute, array $aQuery = []): string
	{
		return self::Issuer().'/index.php?'.http_build_query(['route' => $sRoute] + $aQuery);
	}

	/**
	 * @return list<string>
	 */
	public static function RedirectUris(): array
	{
		return self::StringList(self::Get('redirect_uris', ['https://claude.ai/api/mcp/auth_callback']));
	}

	public static function AllowLoopback(): bool
	{
		return (bool) self::Get('allow_loopback', false);
	}

	public static function RegistrationRateLimit(): int
	{
		return max(1, (int) self::Get('registration_rate_limit', 30));
	}

	public static function CodeLifetime(): int
	{
		return max(10, (int) self::Get('code_lifetime', 60));
	}

	public static function AccessTokenLifetime(): int
	{
		return max(60, (int) self::Get('access_token_lifetime', 3600));
	}

	public static function RefreshGraceSeconds(): int
	{
		return max(0, (int) self::Get('refresh_grace_seconds', 30));
	}

	public static function GrantIdleDays(): int
	{
		return max(1, (int) self::Get('grant_idle_days', 30));
	}

	public static function EndedGrantRetentionDays(): int
	{
		return max(1, (int) self::Get('ended_grant_retention_days', 90));
	}

	public static function MaxGrantDays(): int
	{
		return max(1, (int) self::Get('max_grant_days', 90));
	}

	/**
	 * The resources this server grants tokens for, keyed by a short name. A resource whose
	 * scope family is empty is left out: its module is not installed, so no token could
	 * carry its scopes.
	 *
	 * @return array<string, ProtectedResource>
	 */
	public static function Resources(): array
	{
		$aConfigured = self::Get('resources', []);
		if (!is_array($aConfigured) || $aConfigured === []) {
			$aConfigured = self::DefaultResources();
		}
		$aAllScopes = self::PersonalTokenScopes();
		$aResources = [];
		foreach ($aConfigured as $sKey => $aResource) {
			if (!is_array($aResource) || !isset($aResource['url'], $aResource['scope_prefix'])) {
				continue;
			}
			$sPrefix = (string) $aResource['scope_prefix'];
			$aFamily = array_values(array_filter($aAllScopes, static fn(string $s) => str_starts_with($s, $sPrefix)));
			if ($aFamily === []) {
				continue;
			}
			$aResources[(string) $sKey] = new ProtectedResource(
				(string) $sKey,
				(string) $aResource['url'],
				new ScopePolicy(
					$aFamily,
					self::StringList($aResource['offered_scopes'] ?? []),
					self::StringList($aResource['default_scopes'] ?? []),
					self::StringList($aResource['forced_scopes'] ?? [])
				)
			);
		}

		return $aResources;
	}

	public static function ResourceByUrl(string $sUrl): ?ProtectedResource
	{
		foreach (self::Resources() as $oResource) {
			if ($oResource->sUrl === $sUrl) {
				return $oResource;
			}
		}

		return null;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function DefaultResources(): array
	{
		return [
			'mcp' => [
				'url' => rtrim(utils::GetAbsoluteUrlModulesRoot(), '/').'/altioo-mcp/index.php',
				'scope_prefix' => 'MCP',
				'default_scopes' => ['MCP-read'],
			],
			'rest' => [
				'url' => utils::GetAbsoluteUrlAppRoot().'webservices/rest.php',
				'scope_prefix' => 'REST/JSON',
			],
		];
	}

	/**
	 * @return list<string>
	 */
	private static function PersonalTokenScopes(): array
	{
		// An AttributeEnumSet answers GetPossibleValues() with code => label; GetAllowedValues(),
		// the plain enum's accessor, answers null for it.
		$aValues = MetaModel::GetAttributeDef(PersonalToken::class, 'scope')->GetPossibleValues();

		return is_array($aValues) ? array_values(array_filter(array_map('strval', array_keys($aValues)), 'strlen')) : [];
	}

	/**
	 * @return list<string>
	 */
	private static function StringList(mixed $value): array
	{
		return is_array($value) ? array_values(array_map('strval', array_filter($value, 'is_scalar'))) : [];
	}

	private static function Get(string $sName, mixed $default): mixed
	{
		return MetaModel::GetModuleSetting(self::MODULE, $sName, $default);
	}
}
