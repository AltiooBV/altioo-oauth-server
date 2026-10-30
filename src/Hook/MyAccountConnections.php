<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Hook;

use Altioo\iTop\Extension\OAuthServer\Config;
use Altioo\iTop\Extension\OAuthServer\Controller\AuthorizationController;
use Altioo\iTop\Extension\OAuthServer\Service\Grants;
use AttributeDateTime;
use Combodo\iTop\AuthentToken\Service\PersonalTokenService;
use Combodo\iTop\MyAccount\Hook\iMyAccountTabContentExtension;
use Dict;
use UserRights;
use utils;

/**
 * "Connected applications", under My Account, Personal Tokens, above authent-token's own list:
 * each connection the person made, and a button that ends it. Deleting the connection's token
 * in the list below ends it too; this says which token is which, and what it was for.
 *
 * @since 0.1.0
 */
final class MyAccountConnections implements iMyAccountTabContentExtension
{
	public function IsActive(): bool
	{
		return PersonalTokenService::GetInstance()->IsPersonalTokenManagementAllowed(UserRights::GetUserObject());
	}

	public function GetTemplatePath(): string
	{
		return APPROOT.'env-'.utils::GetCurrentEnvironment().'/'.Config::MODULE.'/templates';
	}

	public function GetTabCode(): string
	{
		return 'MyAccount:SubTitle:PersonalTokens';
	}

	public function GetTemplateName(): string
	{
		return 'oauthconnections';
	}

	public function GetSectionCallback(): callable
	{
		return [$this, 'ProvideConnections'];
	}

	/** Above authent-token's token list, which ranks 0. */
	public function GetSectionRank(): float
	{
		return -10;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function ProvideConnections(): array
	{
		return self::Section() + [
			'action' => Config::Endpoint('disconnect'),
			'transaction_id' => utils::GetNewTransactionId(),
		];
	}

	/**
	 * The signed-in person's connections and the labels to list them with, shared with the
	 * portal's My profile (PortalConnections), which posts its Disconnect elsewhere.
	 *
	 * @return array<string, mixed>
	 */
	public static function Section(): array
	{
		$aConnections = [];
		$aResources = Config::Resources();
		foreach (Grants::ActiveFor((int) UserRights::GetUserId()) as $oGrant) {
			$aConnections[] = [
				'id' => $oGrant->GetKey(),
				'client' => Grants::ClientName($oGrant),
				'scopes' => AuthorizationController::DescribeScopes($aResources[$oGrant->Get('resource')] ?? null, array_values(array_filter(explode(' ', (string) $oGrant->Get('scope'))))),
				'since' => AttributeDateTime::GetFormat()->Format($oGrant->Get('creation_date')),
				'last_used' => AttributeDateTime::GetFormat()->Format($oGrant->Get('last_refresh_date')),
			];
		}

		return [
			'title' => Dict::S('UI:AltiooOAuthServer:MyAccount:Title'),
			'intro' => Dict::S('UI:AltiooOAuthServer:MyAccount:Intro'),
			'empty' => Dict::S('UI:AltiooOAuthServer:MyAccount:Empty'),
			'columns' => [
				Dict::S('UI:AltiooOAuthServer:MyAccount:Application'),
				Dict::S('UI:AltiooOAuthServer:MyAccount:Scopes'),
				Dict::S('UI:AltiooOAuthServer:MyAccount:Since'),
				Dict::S('UI:AltiooOAuthServer:MyAccount:LastUsed'),
				'',
			],
			'disconnect_label' => Dict::S('UI:AltiooOAuthServer:MyAccount:Disconnect'),
			'connections' => $aConnections,
		];
	}
}
