<?php
/**
 * This module's own post-setup checks, included by tools/ci/itop-smoke.php once the generic
 * harness has booted iTop and declared check(), $sItopDir, $sAdmin, $sModuleCode and
 * $sDeclaredVersion.
 *
 * What is checked is what would fail later and quietly: a class the controllers write, a
 * setting read with a default nobody chose, a resource left out because its scope family came
 * out empty, the clean-up process cron would never find. No token is minted for the HTTP
 * smoke: the OAuth flow mints its own, which is what it checks. What is passed on instead is
 * a confidential client's credentials and the fixtures at the end of this file, as
 * "<client_id>:<secret>:<old connection's refresh token>:<login>:<password>:<portal-era refresh token>".
 *
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

use Altioo\iTop\Extension\OAuthServer\Config;
use Altioo\iTop\Extension\OAuthServer\Hook\MyAccountConnections;
use Altioo\iTop\Extension\OAuthServer\Hook\PortalConnections;
use Altioo\iTop\Extension\OAuthServer\Service\CleanUpProcess;
use Combodo\iTop\MyAccount\Hook\iMyAccountTabContentExtension;
use Combodo\iTop\Portal\Hook\iUserProfileTabContentExtension;
use Combodo\iTop\Portal\Hook\iUserProfileTabExtension;
use Combodo\iTop\Service\InterfaceDiscovery\InterfaceDiscovery;

check('the module autoloader is registered', class_exists(Config::class));

foreach (['AltiooOAuthClient', 'AltiooOAuthGrant', 'AltiooOAuthCode'] as $sClass) {
	check("$sClass exists", MetaModel::IsValidClass($sClass));
}
check('AltiooOAuthGrant has the Revoke stimulus', in_array('ev_revoke', array_keys(MetaModel::EnumStimuli('AltiooOAuthGrant')), true));

$aDefaults = [
	'redirect_uris' => Config::DEFAULT_REDIRECT_URIS,
	'allow_loopback' => false,
	'registration_rate_limit' => 300,
	'registration_global_limit' => 1000,
	'allowed_origins' => [],
	'code_lifetime' => 60,
	'access_token_lifetime' => 3600,
	'refresh_grace_seconds' => 30,
	'grant_idle_days' => 30,
	'max_grant_days' => 90,
	'ended_grant_retention_days' => 90,
];
foreach ($aDefaults as $sName => $default) {
	check("$sName is declared with its default", MetaModel::GetModuleSetting($sModuleCode, $sName, null) === $default);
}

// REST/JSON is a scope of every iTop with authent-token, so this resource always exists; the
// altioo-mcp one exists only where that module is installed.
$aResources = Config::Resources();
check('the REST/JSON resource is served', isset($aResources['rest']));
check('the REST/JSON resource is webservices/rest.php', isset($aResources['rest']) && str_ends_with($aResources['rest']->sUrl, '/webservices/rest.php'));
if (MetaModel::IsValidClass('AltiooEventMCPService')) {
	check('with altioo-mcp installed, its resource is served', isset($aResources['mcp']));
}

$aProcesses = InterfaceDiscovery::GetInstance()->FindItopClasses(iProcess::class);
check('cron finds the clean-up process', in_array(CleanUpProcess::class, $aProcesses, true));
$aSections = InterfaceDiscovery::GetInstance()->FindItopClasses(iMyAccountTabContentExtension::class);
check('My Account finds the Connected applications section', in_array(MyAccountConnections::class, $aSections, true));
// The section hangs on authent-token's tab: a rename there would hide it without a word.
check('the section is on authent-token\'s Personal Tokens tab', class_exists('Combodo\\iTop\\AuthentToken\\Hook\\MyAccountTabExtension')
	&& (new Combodo\iTop\AuthentToken\Hook\MyAccountTabExtension())->GetTabCode() === (new MyAccountConnections())->GetTabCode());
check('the My Account template is in the compiled module', is_file((new MyAccountConnections())->GetTemplatePath().'/oauthconnections.html.twig'));
// The portal's My profile renders an extension only on a tab of its own, so both are needed.
check('My profile finds the Connected applications tab', in_array(PortalConnections::class, InterfaceDiscovery::GetInstance()->FindItopClasses(iUserProfileTabExtension::class), true));
check('My profile finds the tab\'s content', in_array(PortalConnections::class, InterfaceDiscovery::GetInstance()->FindItopClasses(iUserProfileTabContentExtension::class), true));
check('the portal template is in the compiled module', is_file(APPROOT.'env-'.utils::GetCurrentEnvironment().'/altioo-oauth-server/templates/oauthconnections.portal.html.twig'));

// A confidential client, created the way an administrator creates one in the console, for the
// HTTP smoke's classic-OAuth half: no PKCE, no resource parameter, a secret in a Basic header.
// Handed over as "<client_id>:<secret>" through the token variable the harness passes on.
$sSmokeSecret = bin2hex(random_bytes(24));
$oConfidential = MetaModel::NewObject('AltiooOAuthClient', [
	'client_name' => 'Smoke confidential client',
	'redirect_uris' => "https://smoke.example/callback\nhttps://smoke.example/other",
	'auth_method' => 'client_secret_basic',
	'pkce' => 'optional',
	'default_resource' => 'rest',
	'source' => 'manual',
]);
$oConfidential->Set('client_secret', $sSmokeSecret);
$oConfidential->AllowWrite();
$oConfidential->DBInsert();
check('a client created by hand gets a generated client ID', preg_match('/^[a-f0-9]{32}$/D', (string) $oConfidential->Get('client_id')) === 1);

// rest.php takes a token only from a holder of "REST Services User" (or with
// allow_rest_services_via_tokens on): the HTTP smoke checks the minted tokens there, as the
// throwaway instance's administrator. Granted here, on that instance only.
$oAdmin = MetaModel::GetObjectByColumn('UserLocal', 'login', $sAdmin, false, true);
$oRestProfile = MetaModel::GetObjectByColumn('URP_Profiles', 'name', 'REST Services User', false, true);
if ($oAdmin !== null && $oRestProfile !== null) {
	$oLinks = $oAdmin->Get('profile_list');
	$bHasIt = false;
	while ($oLink = $oLinks->Fetch()) {
		$bHasIt = $bHasIt || (int) $oLink->Get('profileid') === (int) $oRestProfile->GetKey();
	}
	if (!$bHasIt) {
		$oLink = MetaModel::NewObject('URP_UserProfile', ['profileid' => $oRestProfile->GetKey(), 'reason' => 'altioo-oauth-server HTTP smoke']);
		$oLinks->AddItem($oLink);
		$oAdmin->Set('profile_list', $oLinks);
		$oAdmin->AllowWrite();
		$oAdmin->DBUpdate();
	}
}
check('the smoke administrator may call rest.php', $oAdmin !== null && $oRestProfile !== null);

// Revoke in the console, and the hourly clean-up, on connections made directly rather than
// through the flow: what is checked is what they do to the connection and its token.
$oAdminUser = MetaModel::GetObjectByColumn('User', 'login', $sAdmin, false, true);
$fnConnection = static function (array $aValues) use ($oConfidential, $oAdminUser): array {
	[$oToken] = Altioo\iTop\Extension\OAuthServer\Service\PersonalTokens::Create((int) $oAdminUser->GetKey(), 'Smoke', ['REST/JSON'], 3600);
	$oGrant = MetaModel::NewObject('AltiooOAuthGrant', $aValues + [
		'client_id' => $oConfidential->GetKey(),
		'user_id' => $oAdminUser->GetKey(),
		'resource' => 'rest',
		'scope' => 'REST/JSON',
		'personal_token_id' => $oToken->GetKey(),
		'status' => 'active',
		'creation_date' => date('Y-m-d H:i:s'),
		'expiration_date' => date('Y-m-d H:i:s', time() + 86400),
		'last_refresh_date' => date('Y-m-d H:i:s'),
	]);
	$oGrant->Set('refresh_secret', Altioo\iTop\Extension\OAuthServer\Secret::NewSecret());
	$oGrant->AllowWrite();
	$oGrant->DBInsert();

	return [$oGrant, (int) $oToken->GetKey()];
};

// Who the history names for a token's deletion: the change that recorded it.
$fnDeletedBy = static function (int $iToken): ?string {
	$oSearch = DBObjectSearch::FromOQL("SELECT CMDBChangeOpDelete WHERE objclass = 'PersonalToken' AND objkey = :key");
	$oSearch->AllowAllData();
	$oOp = (new DBObjectSet($oSearch, [], ['key' => $iToken]))->Fetch();
	$oChange = $oOp === null ? null : MetaModel::GetObject('CMDBChange', $oOp->Get('change'), false, true);

	return $oChange === null ? null : (string) $oChange->Get('userinfo');
};

// Revoke is an administrator's action in the console: signed in, as they would be. The
// connection's token was created just before, as this module, in the same process, which is
// what used to leave the module's name on the administrator's deletion.
UserRights::Login($sAdmin);
[$oGrant, $iToken] = $fnConnection([]);
$oGrant->ApplyStimulus('ev_revoke');
$oGrant->AllowWrite();
$oGrant->DBUpdate();
$oGrant->Reload();
check('Revoke ends the connection', $oGrant->Get('status') === 'ended' && $oGrant->Get('end_reason') === 'revoked');
check('Revoke deletes its personal token', MetaModel::GetObject('PersonalToken', $iToken, false, true) === null);
$sRevokedBy = $fnDeletedBy($iToken);
check('the history names the administrator for a Revoke, not the client', $sRevokedBy !== null && $sRevokedBy !== '' && !str_starts_with($sRevokedBy, 'OAuth:'));

[$oExpired, $iExpiredToken] = $fnConnection(['expiration_date' => date('Y-m-d H:i:s', time() - 60)]);
[$oIdle, $iIdleToken] = $fnConnection(['last_refresh_date' => date('Y-m-d H:i:s', time() - 400 * 86400)]);
[$oLive, $iLiveToken] = $fnConnection([]);
(new CleanUpProcess())->Process(time() + 30);
foreach ([[$oExpired, $iExpiredToken, 'expired'], [$oIdle, $iIdleToken, 'idle']] as [$oEnded, $iEndedToken, $sReason]) {
	$oEnded->Reload();
	check("the clean-up ends a connection past its lifetime ($sReason)", $oEnded->Get('status') === 'ended' && $oEnded->Get('end_reason') === $sReason);
	check("and deletes its token ($sReason)", MetaModel::GetObject('PersonalToken', $iEndedToken, false, true) === null);
}
check('the history names the client for what the clean-up ended', $fnDeletedBy($iExpiredToken) === 'OAuth: Smoke confidential client');
$oLive->Reload();
check('the clean-up leaves a live connection alone', $oLive->Get('status') === 'active' && MetaModel::GetObject('PersonalToken', $iLiveToken, false, true) !== null);

// The person deleted the connection's token under My Account: Connected applications must not
// still list the connection as active.
[$oOrphan, $iOrphanToken] = $fnConnection([]);
$oOrphanToken = MetaModel::GetObject('PersonalToken', $iOrphanToken);
$oOrphanToken->AllowWrite();
$oOrphanToken->DBDelete();
$aListed = array_map(static fn($oListed) => (int) $oListed->GetKey(), Altioo\iTop\Extension\OAuthServer\Service\Grants::ActiveFor((int) $oAdminUser->GetKey()));
$oOrphan->Reload();
check('Connected applications leaves out a connection whose token was deleted', !in_array((int) $oOrphan->GetKey(), $aListed, true));
check('and ends it', $oOrphan->Get('status') === 'ended' && $oOrphan->Get('end_reason') === 'disconnected');

// For the HTTP smoke, beside the confidential client:
// - a connection made 200 days ago whose stored end is still ahead: max_grant_days (90) must
//   end it at its next refresh all the same;
// - an account that may own personal tokens but lacks the profile rest.php requires: authorize
//   must refuse it before consent.
$sOldSecret = Altioo\iTop\Extension\OAuthServer\Secret::NewSecret();
[$oOld] = $fnConnection([
	'creation_date' => date('Y-m-d H:i:s', time() - 200 * 86400),
	'expiration_date' => date('Y-m-d H:i:s', time() + 86400),
]);
$oOld->Set('refresh_secret', $sOldSecret);
$oOld->AllowWrite();
$oOld->DBUpdate();

$sNoRestLogin = 'smoke-norest-'.bin2hex(random_bytes(4));
$sNoRestPassword = 'Aa1!'.bin2hex(random_bytes(12));
$oNoRest = MetaModel::NewObject('UserLocal', ['login' => $sNoRestLogin, 'language' => 'EN US', 'expiration' => 'never_expire']);
$oNoRest->Set('password', $sNoRestPassword);
$oProfiles = $oNoRest->Get('profile_list');
$oProfiles->AddItem(MetaModel::NewObject('URP_UserProfile', [
	'profileid' => MetaModel::GetObjectByColumn('URP_Profiles', 'name', 'Administrator')->GetKey(),
	'reason' => 'altioo-oauth-server HTTP smoke',
]));
$oNoRest->Set('profile_list', $oProfiles);
$oNoRest->AllowWrite();
$oNoRest->DBInsert();
check('the account without REST Services User is refused by the REST/JSON resource', !Altioo\iTop\Extension\OAuthServer\Config::Resources()['rest']->AllowsUser($oNoRest));

// A connection allowed while the account was portal-only, whose account now has the console
// (the smoke administrator does): its next refresh must end it, for consent again.
$sPortalSecret = Altioo\iTop\Extension\OAuthServer\Secret::NewSecret();
[$oPortalGrant] = $fnConnection(['console_access' => 'no']);
$oPortalGrant->Set('refresh_secret', $sPortalSecret);
$oPortalGrant->AllowWrite();
$oPortalGrant->DBUpdate();
check('the smoke administrator has the console', Altioo\iTop\Extension\OAuthServer\Service\Accounts::HasConsole($oAdminUser));

$sHttpSmokeToken = implode(':', [
	$oConfidential->Get('client_id'),
	$sSmokeSecret,
	Altioo\iTop\Extension\OAuthServer\Secret::Join((int) $oOld->GetKey(), $sOldSecret),
	$sNoRestLogin,
	$sNoRestPassword,
	Altioo\iTop\Extension\OAuthServer\Secret::Join((int) $oPortalGrant->GetKey(), $sPortalSecret),
]);
