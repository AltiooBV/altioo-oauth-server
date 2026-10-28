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
	public function __construct(
		public readonly string $sKey,
		public readonly string $sUrl,
		public readonly ScopePolicy $oScopes
	)
	{
	}
}
