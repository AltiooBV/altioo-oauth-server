<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer;

/**
 * How a client says who it is at the token endpoint (RFC 6749 §2.3.1): a public client sends
 * only client_id; a confidential one adds its secret, in an HTTP Basic header
 * (client_secret_basic) or in the form (client_secret_post). Only one of the two may be used
 * in a request.
 *
 * @since 0.1.0
 */
final class ClientCredentials
{
	private function __construct(public readonly string $sClientId, public readonly ?string $sSecret)
	{
	}

	/**
	 * @param array<array-key, mixed> $aServer $_SERVER
	 * @param array<array-key, mixed> $aPost $_POST
	 * @return self|null null when the request mixes both methods or names no client
	 */
	public static function FromRequest(array $aServer, array $aPost): ?self
	{
		$aBasic = self::ParseBasic(self::AuthorizationHeader($aServer));
		$sPostedId = is_string($aPost['client_id'] ?? null) ? $aPost['client_id'] : '';
		$sPostedSecret = is_string($aPost['client_secret'] ?? null) ? $aPost['client_secret'] : null;
		if ($aBasic !== null) {
			if ($sPostedSecret !== null || ($sPostedId !== '' && $sPostedId !== $aBasic[0])) {
				return null;
			}

			return new self($aBasic[0], $aBasic[1]);
		}

		return $sPostedId === '' ? null : new self($sPostedId, $sPostedSecret);
	}

	/**
	 * Both halves are form-encoded before they are joined (RFC 6749 §2.3.1), so they are
	 * decoded after splitting, not before.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	public static function ParseBasic(string $sHeader): ?array
	{
		if (preg_match('/^Basic\s+([A-Za-z0-9+\/=]+)$/iD', trim($sHeader), $aMatches) !== 1) {
			return null;
		}
		$sDecoded = base64_decode($aMatches[1], true);
		if ($sDecoded === false || !str_contains($sDecoded, ':')) {
			return null;
		}
		[$sId, $sSecret] = explode(':', $sDecoded, 2);
		$sId = urldecode($sId);

		return $sId === '' ? null : [$sId, urldecode($sSecret)];
	}

	/**
	 * Apache hands PHP the Authorization header under different names depending on how PHP
	 * runs, and drops it altogether under CGI unless told otherwise.
	 *
	 * @param array<array-key, mixed> $aServer
	 */
	private static function AuthorizationHeader(array $aServer): string
	{
		foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $sKey) {
			if (is_string($aServer[$sKey] ?? null) && $aServer[$sKey] !== '') {
				return $aServer[$sKey];
			}
		}
		if (is_string($aServer['PHP_AUTH_USER'] ?? null)) {
			return 'Basic '.base64_encode($aServer['PHP_AUTH_USER'].':'.($aServer['PHP_AUTH_PW'] ?? ''));
		}

		return '';
	}
}
