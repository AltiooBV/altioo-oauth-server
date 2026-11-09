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
 * "<client_id>:<secret>:<old connection's refresh token>:<login>:<password>:<portal-era refresh token>:<withdrawn client_id>".
 *
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

use Altioo\iTop\Extension\OAuthServer\Config;
use Altioo\iTop\Extension\OAuthServer\Hook\MyAccountConnections;
use Altioo\iTop\Extension\OAuthServer\Hook\PortalConnections;
use Altioo\iTop\Extension\OAuthServer\Service\CleanUpProcess;
use Altioo\iTop\Extension\OAuthServer\ResourceMetadata;
use Altioo\iTop\Extension\OAuthServer\TokenAudience;
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
	'unused_registration_limit' => 1000,
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
// The MCP resource exists only where something registers the MCP scopes: altioo-mcp, or the
// stand-in tools/ci/fixtures/altioo-mcp that CI installs in its place (doc/ci-itop-matrix.md).
// On an instance with neither, its absence is right; on a CI instance it must not be silent.
$bMcpScopes = in_array('MCP-read', array_keys((array) MetaModel::GetAttributeDef('PersonalToken', 'scope')->GetPossibleValues()), true);
check('the MCP resource is served exactly when the MCP scopes are registered', isset($aResources['mcp']) === $bMcpScopes);
if (isset($aResources['mcp'])) {
	$oMcp = $aResources['mcp'];
	check('the MCP resource is the altioo-mcp endpoint', str_ends_with($oMcp->sUrl, '/env-'.utils::GetCurrentEnvironment().'/altioo-mcp/index.php'));
	check('its grades are read, write, delete and everything, each wider than the last',
		array_column($oMcp->aGrades, 'scopes') === [['MCP-read'], ['MCP-write'], ['MCP-write', 'MCP-delete'], ['MCP']]);
	check('its one limit is advisory', array_column($oMcp->aLimits, 'scopes') === [['MCP-advisory']]);
	check('an account without the console is offered read, or everything in its portal, and the limit',
		array_column($oMcp->ForAccount(false)->aGrades, 'scopes') === [['MCP-read'], ['MCP']]
		&& array_column($oMcp->ForAccount(false)->aLimits, 'scopes') === [['MCP-advisory']]);
	check('it reads a token from the Bearer header', $oMcp->aBearerMethods === ['header']);
	check('it requires Administrator or MCP Services User', $oMcp->aRequiredProfiles === ['Administrator', 'MCP Services User']);
	check('the MCP Services User profile exists', MetaModel::GetObjectByColumn('URP_Profiles', 'name', 'MCP Services User', false) !== null);
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
	'redirect_uris' => "https://smoke.example/callback\nhttps://smoke.example/other\nhttp://127.0.0.1:8765/callback",
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
$fnDeletedBy = static function (int $iKey, string $sClass = 'PersonalToken'): ?string {
	$oSearch = DBObjectSearch::FromOQL('SELECT CMDBChangeOpDelete WHERE objclass = :class AND objkey = :key');
	$oSearch->AllowAllData();
	$oOp = (new DBObjectSet($oSearch, [], ['class' => $sClass, 'key' => $iKey]))->Fetch();
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
// A registration nobody used for two days, which the same run deletes after ending the two above.
$oStale = MetaModel::NewObject('AltiooOAuthClient', [
	'client_name' => 'Smoke stale registration',
	'redirect_uris' => json_encode(['https://claude.ai/api/mcp/auth_callback'], JSON_UNESCAPED_SLASHES),
	'source' => 'dcr',
	'auth_method' => 'none',
	'creation_date' => date('Y-m-d H:i:s', time() - 2 * 86400),
]);
$oStale->AllowWrite();
$oStale->DBInsert();
$iStale = (int) $oStale->GetKey();
(new CleanUpProcess())->Process(time() + 30);
foreach ([[$oExpired, $iExpiredToken, 'expired'], [$oIdle, $iIdleToken, 'idle']] as [$oEnded, $iEndedToken, $sReason]) {
	$oEnded->Reload();
	check("the clean-up ends a connection past its lifetime ($sReason)", $oEnded->Get('status') === 'ended' && $oEnded->Get('end_reason') === $sReason);
	check("and deletes its token ($sReason)", MetaModel::GetObject('PersonalToken', $iEndedToken, false, true) === null);
}
check('the history names the client for what the clean-up ended', $fnDeletedBy($iExpiredToken) === 'OAuth: Smoke confidential client');
check('the clean-up deletes a registration never used', MetaModel::GetObject('AltiooOAuthClient', $iStale, false, true) === null);
check('and the history names the clean-up for it, not the last client it ended a connection of', $fnDeletedBy($iStale, 'AltiooOAuthClient') === 'OAuth: hourly clean-up');
$oLive->Reload();
check('the clean-up leaves a live connection alone', $oLive->Get('status') === 'active' && MetaModel::GetObject('PersonalToken', $iLiveToken, false, true) !== null);

// What a protected resource asks before serving a call: which resource the token was granted
// for (TokenAudience), held to the URL exactly.
$sRestUrl = $aResources['rest']->sUrl;
$oAudience = TokenAudience::Of($iLiveToken);
check('a connection\'s token has its resource as audience', $oAudience !== null && $oAudience->sResourceKey === 'rest' && $oAudience->IsFor($sRestUrl));
check('and it is not for another URL of the same endpoint', $oAudience !== null && !$oAudience->IsFor($sRestUrl.'?portal_id=1'));
check('a revoked connection\'s token has none', TokenAudience::Of($iToken) === null);
[$oLoose] = Altioo\iTop\Extension\OAuthServer\Service\PersonalTokens::Create((int) $oAdminUser->GetKey(), 'Smoke', ['REST/JSON'], 3600);
check('a token no connection owns has none', TokenAudience::Of((int) $oLoose->GetKey()) === null);
$oLoose->AllowDelete();
$oLoose->DBDelete();
[$oRemoved, $iRemovedToken] = $fnConnection(['resource' => 'smoke-removed']);
$oAudience = TokenAudience::Of($iRemovedToken);
check('a resource\'s URL leads to its metadata', ResourceMetadata::UrlFor($sRestUrl) === Config::Endpoint('protected-resource', ['for' => 'rest']));
check('another URL of the same endpoint leads nowhere', ResourceMetadata::UrlFor($sRestUrl.'?portal_id=1') === null);
check('a token for a resource no longer configured is for no URL', $oAudience !== null && $oAudience->sResourceKey === 'smoke-removed' && $oAudience->sResourceUrl === '' && !$oAudience->IsFor($sRestUrl));

// The console names a connection's resource as the consent page did, with its key.
check('the console shows a connection\'s resource by its label', $oLive->GetAsHTML('resource') === utils::EscapeHtml(Dict::S('UI:AltiooOAuthServer:Resource:REST').' (rest)'));
check('and by its key when it is no longer configured', $oRemoved->GetAsHTML('resource') === 'smoke-removed');
Altioo\iTop\Extension\OAuthServer\Service\Grants::End($oRemoved, 'revoked');

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

// Two accounts for the MCP resource's checks over HTTP, when it is there: one it refuses for want
// of a profile (a console account holding neither Administrator nor MCP Services User), and one
// with the portal and no console, holding the gate profile, which it offers portal grades.
$fnAccount = static function (string $sPrefix, array $aProfileNames): array {
	$sLogin = $sPrefix.bin2hex(random_bytes(4));
	$sPassword = 'Aa1!'.bin2hex(random_bytes(12));
	$oUser = MetaModel::NewObject('UserLocal', ['login' => $sLogin, 'language' => 'EN US', 'expiration' => 'never_expire']);
	$oUser->Set('password', $sPassword);
	$oLinks = $oUser->Get('profile_list');
	foreach ($aProfileNames as $sProfile) {
		$oLinks->AddItem(MetaModel::NewObject('URP_UserProfile', [
			'profileid' => MetaModel::GetObjectByColumn('URP_Profiles', 'name', $sProfile)->GetKey(),
			'reason' => 'altioo-oauth-server HTTP smoke',
		]));
	}
	$oUser->Set('profile_list', $oLinks);
	$oUser->AllowWrite();
	$oUser->DBInsert();

	return [$sLogin, $sPassword, $oUser];
};
$sNoMcpLogin = $sNoMcpPassword = $sPortalLogin = $sPortalPassword = '-';
if (isset($aResources['mcp'])) {
	[$sNoMcpLogin, $sNoMcpPassword, $oNoMcp] = $fnAccount('smoke-nomcp-', ['Configuration Manager']);
	check('a console account without a profile the MCP resource requires is refused by it', !$aResources['mcp']->AllowsUser($oNoMcp)
		&& Altioo\iTop\Extension\OAuthServer\Service\Accounts::HasConsole($oNoMcp));
	[$sPortalLogin, $sPortalPassword, $oPortalOnly] = $fnAccount('smoke-portal-', ['Portal user', 'MCP Services User']);
	check('an account with the portal and the gate profile has no console', !Altioo\iTop\Extension\OAuthServer\Service\Accounts::HasConsole($oPortalOnly));
	check('and the MCP resource accepts it', $aResources['mcp']->AllowsUser($oPortalOnly));
}

// A connection allowed while the account was portal-only, whose account now has the console
// (the smoke administrator does): its next refresh must end it, for consent again.
$sPortalSecret = Altioo\iTop\Extension\OAuthServer\Secret::NewSecret();
[$oPortalGrant] = $fnConnection(['console_access' => 'no']);
$oPortalGrant->Set('refresh_secret', $sPortalSecret);
$oPortalGrant->AllowWrite();
$oPortalGrant->DBUpdate();
check('the smoke administrator has the console', Altioo\iTop\Extension\OAuthServer\Service\Accounts::HasConsole($oAdminUser));

// A connection deleted rather than revoked - directly, or with its client - must take its
// token with it; and deleting an account that has a connection must still work, its tokens going
// with it through authent-token rather than twice.
[$oDeleted, $iDeletedToken] = $fnConnection([]);
$oDeleted->AllowDelete();
$oDeleted->DBDelete();
check('deleting a connection deletes its token', MetaModel::GetObject('PersonalToken', $iDeletedToken, false, true) === null);
$oDoomed = MetaModel::NewObject('AltiooOAuthClient', ['client_name' => 'Smoke deleted client', 'redirect_uris' => 'https://smoke.example/callback', 'source' => 'manual', 'auth_method' => 'none']);
$oDoomed->AllowWrite();
$oDoomed->DBInsert();
[, $iDoomedToken] = $fnConnection(['client_id' => $oDoomed->GetKey()]);
$oDoomed->AllowDelete();
$oDoomed->DBDelete();
check('deleting a client deletes its connections\' tokens', MetaModel::GetObject('PersonalToken', $iDoomedToken, false, true) === null);
$oGone = MetaModel::NewObject('UserLocal', ['login' => 'smoke-gone-'.bin2hex(random_bytes(4)), 'language' => 'EN US', 'expiration' => 'never_expire']);
$oGone->Set('password', 'Aa1!'.bin2hex(random_bytes(12)));
$oGoneProfiles = $oGone->Get('profile_list');
$oGoneProfiles->AddItem(MetaModel::NewObject('URP_UserProfile', ['profileid' => MetaModel::GetObjectByColumn('URP_Profiles', 'name', 'Administrator')->GetKey(), 'reason' => 'altioo-oauth-server HTTP smoke']));
$oGone->Set('profile_list', $oGoneProfiles);
$oGone->AllowWrite();
$oGone->DBInsert();
[$oGoneToken] = Altioo\iTop\Extension\OAuthServer\Service\PersonalTokens::Create((int) $oGone->GetKey(), 'Smoke', ['REST/JSON'], 3600);
$iGoneToken = (int) $oGoneToken->GetKey();
[$oGoneGrant] = $fnConnection(['user_id' => $oGone->GetKey(), 'personal_token_id' => $iGoneToken]);
$iGoneGrant = (int) $oGoneGrant->GetKey();
try {
	$oGone->AllowDelete();
	$oGone->DBDelete();
	$bGoneDeleted = true;
} catch (Exception $e) {
	$bGoneDeleted = false;
}
check('an account with a connection can still be deleted', $bGoneDeleted);
check('and its connection and token go with it', MetaModel::GetObject('AltiooOAuthGrant', $iGoneGrant, false, true) === null
	&& MetaModel::GetObject('PersonalToken', $iGoneToken, false, true) === null);

// Past the unused registration limit, a new registration makes room rather than being refused:
// the oldest unused ones go, except one a person has already consented to (it holds a code).
$fnUnused = static function (int $iAgeHours): AltiooOAuthClient {
	$oClient = MetaModel::NewObject('AltiooOAuthClient', [
		'client_name' => 'Smoke unused registration',
		'redirect_uris' => json_encode(['https://claude.ai/api/mcp/auth_callback'], JSON_UNESCAPED_SLASHES),
		'source' => 'dcr',
		'auth_method' => 'none',
		'pkce' => 'required',
		'status' => 'active',
		'creation_date' => date('Y-m-d H:i:s', time() - $iAgeHours * 3600),
	]);
	$oClient->AllowWrite();
	$oClient->DBInsert();

	return $oClient;
};
$oUnusedSearch = DBObjectSearch::FromOQL("SELECT AltiooOAuthClient WHERE source = 'dcr' AND ISNULL(last_used_date)");
$oUnusedSearch->AllowAllData();
$iUnusedBefore = (new DBObjectSet($oUnusedSearch))->Count();
$oConsented = $fnUnused(23);
$oCode = MetaModel::NewObject('AltiooOAuthCode', [
	'client_id' => $oConsented->GetKey(),
	'user_id' => $oAdminUser->GetKey(),
	'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
	'code_challenge' => str_repeat('a', 43),
	'resource' => 'rest',
	'scope' => 'REST/JSON',
	'expiration_date' => date('Y-m-d H:i:s', time() + 60),
]);
$oCode->Set('code_secret', Altioo\iTop\Extension\OAuthServer\Secret::NewSecret());
$oCode->AllowWrite();
$oCode->DBInsert();
$aUnused = [$fnUnused(22), $fnUnused(21), $fnUnused(20)];
// Room for one more with two fewer: the two oldest go, but not the older one with a code.
$iMadeRoom = Altioo\iTop\Extension\OAuthServer\Service\Registrations::MakeRoom($iUnusedBefore + 4 - 1, '127.0.0.1');
$fnExists = static fn(AltiooOAuthClient $oClient): bool => MetaModel::GetObject('AltiooOAuthClient', $oClient->GetKey(), false, true) !== null;
check('past the unused registration limit, the oldest unused registrations make room', $iMadeRoom === 2 && !$fnExists($aUnused[0]) && !$fnExists($aUnused[1]));
check('a registration a person consented to is kept', $fnExists($oConsented));
check('and the newer ones too', $fnExists($aUnused[2]));
check('below the limit nothing goes', Altioo\iTop\Extension\OAuthServer\Service\Registrations::MakeRoom($iUnusedBefore + 100, '127.0.0.1') === 0);

// A client that registered itself with a callback the allowlist has since dropped: authorize
// must refuse it, whatever its own list says. Stored as registration stores it.
$oWithdrawn = MetaModel::NewObject('AltiooOAuthClient', [
	'client_name' => 'Smoke withdrawn callback',
	'redirect_uris' => json_encode(['https://withdrawn.example/callback'], JSON_UNESCAPED_SLASHES),
	'source' => 'dcr',
	'auth_method' => 'none',
	'pkce' => 'required',
	'status' => 'active',
]);
$oWithdrawn->AllowWrite();
$oWithdrawn->DBInsert();
check('the withdrawn callback is off the allowlist', !in_array('https://withdrawn.example/callback', Config::RedirectUris(), true));

$sHttpSmokeToken = implode(':', [
	$oConfidential->Get('client_id'),
	$sSmokeSecret,
	Altioo\iTop\Extension\OAuthServer\Secret::Join((int) $oOld->GetKey(), $sOldSecret),
	$sNoRestLogin,
	$sNoRestPassword,
	Altioo\iTop\Extension\OAuthServer\Secret::Join((int) $oPortalGrant->GetKey(), $sPortalSecret),
	$oWithdrawn->Get('client_id'),
	$sNoMcpLogin,
	$sNoMcpPassword,
	$sPortalLogin,
	$sPortalPassword,
]);
