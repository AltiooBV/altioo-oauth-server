<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Controller;

use Altioo\iTop\Extension\OAuthServer\Exception\PageException;
use Altioo\iTop\Extension\OAuthServer\Http;
use Altioo\iTop\Extension\OAuthServer\Service\Grants;
use LoginWebPage;
use MetaModel;
use AltiooOAuthGrant;
use UserRights;
use utils;

/**
 * A person withdraws their consent from My Account, Personal Tokens, Connected applications
 * (src/Hook/MyAccountConnections.php). Only the person who made the connection can end it
 * here; an administrator uses Revoke on the connection in the console.
 *
 * @since 0.1.0
 */
final class ConnectionsController
{
	public static function Disconnect(): never
	{
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
			throw new PageException('UI:AltiooOAuthServer:Page:RequestExpired', 405);
		}
		LoginWebPage::DoLogin(false, false, LoginWebPage::EXIT_PROMPT);
		if (!utils::IsTransactionValid(utils::ReadPostedParam('transaction_id', '', 'transaction_id'))) {
			throw new PageException('UI:AltiooOAuthServer:Page:RequestExpired');
		}
		$oGrant = MetaModel::GetObject(AltiooOAuthGrant::class, (int) utils::ReadPostedParam('grant_id', 0, 'integer'), false, true);
		if ($oGrant instanceof AltiooOAuthGrant
			&& (int) $oGrant->Get('user_id') === (int) UserRights::GetUserId()
			&& $oGrant->Get('status') === 'active') {
			Grants::End($oGrant, 'withdrawn', true, false);
		}

		Http::Redirect(utils::GetAbsoluteUrlModulePage('combodo-my-account', 'index.php'));
	}
}
