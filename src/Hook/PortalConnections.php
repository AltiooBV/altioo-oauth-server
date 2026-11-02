<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Hook;

use Altioo\iTop\Extension\OAuthServer\Service\Grants;
use Combodo\iTop\AuthentToken\Service\PersonalTokenService;
use Combodo\iTop\Portal\Hook\iUserProfileTabContentExtension;
use Combodo\iTop\Portal\Hook\iUserProfileTabExtension;
use Combodo\iTop\Portal\Twig\PortalBlockExtension;
use Combodo\iTop\Portal\Twig\PortalTwigContext;
use Dict;
use UserRights;
use utils;

/**
 * "Connected applications", a tab of the portal's My profile: the same list as My Account's
 * (MyAccountConnections), for the accounts that have only the portal. The profile page renders
 * an extension's content only on a tab of its own, so this is both the tab and its content.
 *
 * Disconnect posts back to the profile page. The page has already checked the transaction id
 * by then, without spending it; HandlePortalForm spends it, so a replayed post ends nothing.
 * The form carries the id the page hands its own forms. The template sees only the data given
 * here, not the page's, so HandlePortalForm, which the page calls first on this same object,
 * keeps it for GetPortalTabContentTwigs.
 *
 * @since 0.1.0
 */
final class PortalConnections implements iUserProfileTabExtension, iUserProfileTabContentExtension
{
	private const TAB = 'altioo-oauth-server-connections';
	private const OPERATION = 'altioo_oauth_server_disconnect';

	private string $sTransactionId = '';

	public function IsTabPresent(): bool
	{
		return $this->IsActive();
	}

	public function GetTabRank(): float
	{
		return 10;
	}

	public function GetTabCode(): string
	{
		return self::TAB;
	}

	/** The profile page prints the label unescaped. */
	public function GetTabLabel(): string
	{
		return utils::EscapeHtml(Dict::S('UI:AltiooOAuthServer:MyAccount:Title'));
	}

	public function IsActive(): bool
	{
		$oUser = UserRights::GetUserObject();

		return $oUser !== null && PersonalTokenService::GetInstance()->IsPersonalTokenManagementAllowed($oUser);
	}

	/**
	 * @param array<string, mixed> $aData
	 */
	public function HandlePortalForm(array &$aData): void
	{
		$this->sTransactionId = is_string($aData['sTransactionId'] ?? null) ? $aData['sTransactionId'] : '';
		if (utils::ReadPostedParam('operation', '', utils::ENUM_SANITIZATION_FILTER_RAW_DATA) !== self::OPERATION) {
			return;
		}
		if (!utils::IsTransactionValid(utils::ReadPostedParam('transaction_id', '', utils::ENUM_SANITIZATION_FILTER_TRANSACTION_ID), true)) {
			$aData['sError'] = Dict::S('UI:AltiooOAuthServer:Portal:Expired');

			return;
		}
		$oGrant = Grants::Withdraw((int) utils::ReadPostedParam('grant_id', 0, utils::ENUM_SANITIZATION_FILTER_INTEGER), (int) UserRights::GetUserId());
		if ($oGrant !== null) {
			$aData['sMessage'] = Dict::Format('UI:AltiooOAuthServer:Portal:Disconnected', Grants::ClientName($oGrant));
		}
	}

	public function GetPortalTabContentTwigs(): PortalTwigContext
	{
		$oContext = new PortalTwigContext();
		$oContext->AddBlockExtension('html', new PortalBlockExtension(
			'altioo-oauth-server/templates/oauthconnections.portal.html.twig',
			MyAccountConnections::Section() + [
				'tab' => self::TAB,
				'operation' => self::OPERATION,
				'transaction_id' => $this->sTransactionId !== '' ? $this->sTransactionId : utils::GetNewTransactionId(),
			]
		));

		return $oContext;
	}

	public function GetSectionRank(): float
	{
		return 0;
	}
}
