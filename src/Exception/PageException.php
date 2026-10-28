<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Exception;

use Exception;

/**
 * A refusal shown to the person in the browser, on the authorize endpoint. Never a redirect:
 * the request is refused before its redirect URI could be trusted, or after it was, for a
 * reason the person has to act on.
 *
 * @since 0.1.0
 */
final class PageException extends Exception
{
	/**
	 * @param string $sKey the dictionary key of the page; its title is the key, its text the key with "+"
	 */
	public function __construct(public readonly string $sKey, public readonly int $iStatus = 400)
	{
		parent::__construct($sKey);
	}
}
