<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer;

/**
 * A self-declared client name, cleaned before it is stored and shown. Anyone may register a
 * client, so the name is attacker-controlled text that lands on the consent page — the one
 * screen a person reads to decide who they are letting in — and on the token's "application"
 * label under My Account. Escaping (htmlspecialchars) stops markup; it does nothing to
 * characters that reorder or hide what is shown, so those are removed here instead.
 *
 * Removed: bidirectional controls (a right-to-left override can make "accessing" read as
 * "gnissecca", or fold the name into the words around it) and zero-width characters (which
 * hide where one word ends). Replaced with a space, then collapsed: C0/C1 control characters,
 * DEL, and line/paragraph separators.
 *
 * @since 0.1.0
 */
final class ClientName
{
	/** Bidi formatting and zero-width characters: invisible, so dropped outright. */
	private const INVISIBLE = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{206F}\x{FEFF}\x{061C}]+/u';
	/** C0 and C1 controls, DEL, and the Unicode line/paragraph separators: shown as a space. */
	private const CONTROL = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{2028}\x{2029}]+/u';

	public static function Clean(string $sName): string
	{
		$sName = preg_replace(self::INVISIBLE, '', $sName) ?? '';
		$sName = preg_replace(self::CONTROL, ' ', $sName) ?? '';

		return trim(preg_replace('/\s+/u', ' ', $sName) ?? '');
	}
}
