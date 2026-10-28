<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Exception;

use Exception;

/**
 * An error answered as OAuth JSON, on the endpoints a program calls (token, register).
 *
 * @since 0.1.0
 */
final class OAuthException extends Exception
{
	public function __construct(public readonly string $sError, public readonly string $sDescription = '', public readonly int $iStatus = 400)
	{
		parent::__construct($sError.($sDescription === '' ? '' : ': '.$sDescription));
	}
}
