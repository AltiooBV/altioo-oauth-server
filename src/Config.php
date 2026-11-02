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
	/**
	 * Also declared in datamodel.altioo-oauth-server.xml, which says why each one is there.
	 * Note https://vscode.dev/redirect forwards the code on to a target VS Code picks, so it is
	 * a redirector rather than a final callback: the datamodel comment and the README say when to
	 * keep it.
	 */
	public const DEFAULT_REDIRECT_URIS = [
		'https://claude.ai/api/mcp/auth_callback',
		'https://claude.com/api/mcp/auth_callback',
		'https://chatgpt.com/connector_platform_oauth_redirect',
		'https://chatgpt.com/connector/oauth/*',
		'https://www.cursor.com/agents/mcp/oauth/callback',
		'https://vscode.dev/redirect',
	];

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
		return self::StringList(self::Get('redirect_uris', self::DEFAULT_REDIRECT_URIS));
	}

	public static function AllowLoopback(): bool
	{
		return (bool) self::Get('allow_loopback', false);
	}

	public static function RegistrationRateLimit(): int
	{
		return max(1, (int) self::Get('registration_rate_limit', 300));
	}

	public static function UnusedRegistrationLimit(): int
	{
		return max(1, (int) self::Get('unused_registration_limit', 1000));
	}

	/**
	 * @return list<string>
	 */
	public static function AllowedOrigins(): array
	{
		return self::StringList(self::Get('allowed_origins', []));
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
				),
				self::StringList($aResource['required_profiles'] ?? []),
				self::Choices($aResource['grades'] ?? []),
				self::Choices($aResource['limits'] ?? []),
				is_string($aResource['label'] ?? null) ? $aResource['label'] : '',
				// Most resources read RFC 6750's Bearer header; one that does not says so.
				array_key_exists('bearer_methods', $aResource) ? self::StringList($aResource['bearer_methods']) : ['header'],
				self::Choices($aResource['portal_grades'] ?? [])
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
				'label' => 'UI:AltiooOAuthServer:Resource:MCP',
				'scope_prefix' => 'MCP',
				// Not the MCP-toolset-* scopes: each one restricts a token to its toolset, which
				// a person deciding what to allow has no reason to know. An administrator who
				// wants them offers them as limits, with labels of their own.
				'offered_scopes' => ['MCP-read', 'MCP-write', 'MCP-delete', 'MCP', 'MCP-advisory'],
				'default_scopes' => ['MCP-read'],
				'required_profiles' => self::StringList(MetaModel::GetModuleSetting('altioo-mcp', 'mcp_allowed_profiles', ['Administrator', 'MCP Services User'])),
				'grades' => [
					['label' => 'UI:AltiooOAuthServer:Grade:Read', 'scopes' => ['MCP-read']],
					['label' => 'UI:AltiooOAuthServer:Grade:Write', 'scopes' => ['MCP-write']],
					['label' => 'UI:AltiooOAuthServer:Grade:Delete', 'scopes' => ['MCP-write', 'MCP-delete']],
					['label' => 'UI:AltiooOAuthServer:Grade:All', 'scopes' => ['MCP']],
				],
				'limits' => [
					['label' => 'UI:AltiooOAuthServer:Limit:Advisory', 'scopes' => ['MCP-advisory']],
				],
				// Portal mode serves what the stricter of the account's profiles and its portal
				// allows, and tells writing from deleting by neither: read, or all of that.
				'portal_grades' => [
					['label' => 'UI:AltiooOAuthServer:Grade:Read', 'scopes' => ['MCP-read']],
					['label' => 'UI:AltiooOAuthServer:Grade:PortalAll', 'scopes' => ['MCP']],
				],
			],
			'rest' => [
				'url' => utils::GetAbsoluteUrlAppRoot().'webservices/rest.php',
				'label' => 'UI:AltiooOAuthServer:Resource:REST',
				'scope_prefix' => 'REST/JSON',
				// authent-token reads Auth-Token or auth_token, never Authorization: Bearer
				// (doc/adr/0003-generic-from-the-first-release.md).
				'bearer_methods' => [],
				// rest.php's own rule (webservices/rest.php, authent-token's AbstractPersonalToken).
				'required_profiles' => MetaModel::GetConfig()->Get('secure_rest_services') && !MetaModel::GetConfig()->Get('allow_rest_services_via_tokens')
					? ['REST Services User']
					: [],
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
	 * @return list<array{label: string, scopes: list<string>}>
	 */
	private static function Choices(mixed $value): array
	{
		$aChoices = [];
		foreach (is_array($value) ? $value : [] as $aChoice) {
			if (is_array($aChoice) && is_string($aChoice['label'] ?? null) && self::StringList($aChoice['scopes'] ?? []) !== []) {
				$aChoices[] = ['label' => $aChoice['label'], 'scopes' => self::StringList($aChoice['scopes'])];
			}
		}

		return $aChoices;
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
