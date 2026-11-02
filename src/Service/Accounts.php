<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Service;

use PortalDispatcher;
use User;

/**
 * @since 0.1.0
 */
final class Accounts
{
	/**
	 * Whether the account may use the console: what iTop's own login decides with the
	 * backoffice dispatcher (PortalDispatcher, its user argument since 3.2.0). Not
	 * UserRights::IsPortalUser(), which only asks whether the account holds "Portal user".
	 */
	public static function HasConsole(User $oUser): bool
	{
		return (new PortalDispatcher('backoffice'))->IsUserAllowed($oUser);
	}

	/**
	 * Whether the account is no longer the kind it was when consent was given, a portal
	 * account given the console or a console account left with the portal: what the person
	 * consented to is then not what the resource would serve. Empty is a connection made
	 * before this was recorded, which never changes kind.
	 *
	 * @param string $sRecorded the connection's console_access: "yes", "no" or empty
	 */
	public static function KindChanged(string $sRecorded, bool $bConsoleNow): bool
	{
		return ($sRecorded === 'no' && $bConsoleNow) || ($sRecorded === 'yes' && !$bConsoleNow);
	}
}
