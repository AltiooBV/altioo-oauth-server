<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer;

/**
 * Where a protected resource's metadata (RFC 9728) is served, for the resource to advertise in
 * the WWW-Authenticate challenge of its 401: a client that called one resource's URL is then
 * sent to consent for that resource, and not for another sharing its endpoint, as one
 * resource per portal does.
 *
 * A resource that may run without this module installed asks
 * class_exists(ResourceMetadata::class) first.
 *
 * @api
 * @since 0.1.0
 */
final class ResourceMetadata
{
	/**
	 * The metadata URL of the resource configured with exactly this URL, or null when none is.
	 */
	public static function UrlFor(string $sResourceUrl): ?string
	{
		$oResource = Config::ResourceByUrl($sResourceUrl);

		return $oResource === null ? null : Config::Endpoint('protected-resource', ['for' => $oResource->sKey]);
	}
}
