<?php
/**
 * The stand-in's one endpoint: what altioo-mcp asks of altioo-oauth-server before it serves a
 * call, and nothing of MCP. A token arrives as a Bearer header, is held to the URL it was
 * granted for (TokenAudience), and is answered with who and what it is. A 401 carries the
 * resource's metadata URL (ResourceMetadata), as altioo-mcp's does.
 *
 * Both classes are asked as altioo-mcp asks them, by name and only when they exist.
 *
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

use Combodo\iTop\AuthentToken\Service\AuthentTokenService;

require_once dirname(__DIR__, 2).'/approot.inc.php';
require_once APPROOT.'/application/application.inc.php';
require_once APPROOT.'/application/startup.inc.php';

const STAND_IN_AUDIENCE = '\\Altioo\\iTop\\Extension\\OAuthServer\\TokenAudience';
const STAND_IN_METADATA = '\\Altioo\\iTop\\Extension\\OAuthServer\\ResourceMetadata';

/**
 * The URL called, as the resource's configured URL is written: the endpoint, and the portal
 * when one is asked for.
 */
function StandInCalledUrl(): string
{
	$sUrl = rtrim(utils::GetAbsoluteUrlModulesRoot(), '/').'/altioo-mcp/index.php';
	$sPortal = (string) ($_GET['portal_id'] ?? '');

	return $sPortal === '' ? $sUrl : $sUrl.'?portal_id='.rawurlencode($sPortal);
}

function StandInRefuse(string $sError, string $sMessage): never
{
	$sChallenge = 'Bearer error="'.$sError.'"';
	if (class_exists(STAND_IN_METADATA)) {
		$sMetadata = call_user_func([STAND_IN_METADATA, 'UrlFor'], StandInCalledUrl());
		if (is_string($sMetadata)) {
			$sChallenge .= ', resource_metadata="'.$sMetadata.'"';
		}
	}
	http_response_code(401);
	header('WWW-Authenticate: '.$sChallenge);
	header('Content-Type: application/json');
	echo json_encode(['error' => $sError, 'message' => $sMessage]);
	exit;
}

$sHeader = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (preg_match('/^Bearer ([^\s]+)$/', $sHeader, $aMatches) !== 1) {
	StandInRefuse('invalid_request', 'A Bearer token is needed.');
}
$sToken = $aMatches[1];

$oToken = (new AuthentTokenService())->DecryptToken($sToken);
if (!$oToken instanceof PersonalToken || !$oToken->Get('auth_token')->CheckPassword($sToken)) {
	StandInRefuse('invalid_token', 'This token is not valid.');
}
$sExpiration = (string) $oToken->Get('expiration_date');
if ($sExpiration !== '' && strtotime($sExpiration) < time()) {
	StandInRefuse('invalid_token', 'This token has expired.');
}

if (class_exists(STAND_IN_AUDIENCE)) {
	$oAudience = call_user_func([STAND_IN_AUDIENCE, 'Of'], (int) $oToken->GetKey());
	if (is_object($oAudience) && $oAudience->IsFor(StandInCalledUrl()) !== true) {
		StandInRefuse('invalid_token', 'This token was granted for another URL of this endpoint.');
	}
}

$oUser = MetaModel::GetObject('User', (int) $oToken->Get('user_id'), false, true);
header('Content-Type: application/json');
echo json_encode([
	'ok' => true,
	'login' => $oUser === null ? '' : (string) $oUser->Get('login'),
	'scopes' => array_values($oToken->Get('scope')->GetValues()),
	'url' => StandInCalledUrl(),
]);
