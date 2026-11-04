<?php
/**
 * What an upgrade must not lose (doc/ci-upgrade.md). Called with `seed` on the instance
 * install-itop.sh made from the baseline, and with `verify` after each run of
 * upgrade-module.sh.
 *
 * Seeds a confidential client, an active connection with its PersonalToken, an ended
 * connection, an authorization code, and the `resources` setting tuned from its default, the
 * one setting module-smoke.php does not hold to its default in the same job. The client's name
 * has an accent and an apostrophe, and fills its column.
 *
 * Both modes then use the connection as a client does, over HTTP against a server of their
 * own: its access token on rest.php, its refresh token at the token endpoint, its access token
 * again. A refresh that gives the same PersonalToken row a new value is ADR 0004. At `seed` this
 * says the baseline serves the connection, so that a refusal at `verify` is the upgrade's
 * doing; it also leaves a rotated refresh token behind, so that the previous one is on the row.
 *
 * Runs from the working copy against whichever version is installed, so at `seed` it may only
 * use what the oldest baseline has. A refresh rotates the tokens, so `verify` keeps the new ones
 * for the next `verify`.
 *
 * Usage: php tools/ci/upgrade-fixture.php <itop-dir> seed|verify
 *
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

use Altioo\iTop\Extension\OAuthServer\Service\Grants;
use Altioo\iTop\Extension\OAuthServer\Service\PersonalTokens;
use Altioo\iTop\Extension\OAuthServer\Secret;

const FIXTURE_MODULE = 'altioo-oauth-server';
const FIXTURE_SCOPE = 'REST/JSON';
// What each kind of row must still say. Not what a refresh changes: a refresh is expected.
const FIXTURE_WATCHED = [
	'client' => ['client_id', 'client_name', 'redirect_uris', 'source', 'auth_method', 'pkce', 'default_resource', 'status', 'creation_date'],
	'grant' => ['client_id', 'user_id', 'resource', 'scope', 'console_access', 'personal_token_id', 'status', 'end_reason', 'end_date', 'creation_date', 'expiration_date'],
	'code' => ['client_id', 'user_id', 'redirect_uri', 'code_challenge', 'resource', 'scope', 'expiration_date'],
	'token' => ['user_id', 'application'],
	'user' => ['login', 'status'],
];

$sItopDir = rtrim($argv[1] ?? '', '/');
$sMode = $argv[2] ?? '';

if (!is_file($sItopDir.'/approot.inc.php') || !in_array($sMode, ['seed', 'verify'], true)) {
	fwrite(STDERR, "usage: php upgrade-fixture.php <itop-dir> seed|verify\n");
	exit(1);
}

require_once $sItopDir.'/approot.inc.php';
require_once APPROOT.'/application/application.inc.php';
require_once APPROOT.'/application/startup.inc.php';

$aFailures = [];

function check(string $sWhat, bool $bOk): void
{
	global $aFailures;
	echo ($bOk ? '  ok   ' : '  FAIL ').$sWhat."\n";
	if (!$bOk) {
		$aFailures[] = $sWhat;
	}
}

// The workflow keeps this one when a run fails, and a public repository's artifacts can be read
// by anyone signed in: nothing in it opens anything.
function StatePath(): string
{
	return APPROOT.'ci-upgrade-fixture.json';
}

// The tokens and the client's secret, in clear, are a throwaway instance's; they still stay out of
// everything the job archives, as itop-smoke.php keeps its token out.
function SecretsPath(): string
{
	return APPROOT.'data/ci-upgrade-fixture.secrets.json';
}

function ReadJson(string $sPath): array
{
	$aValue = is_file($sPath) ? json_decode((string) file_get_contents($sPath), true) : null;
	if (!is_array($aValue)) {
		throw new RuntimeException("$sPath holds nothing to read: did `seed` run?");
	}

	return $aValue;
}

function WriteJson(string $sPath, array $aValue): void
{
	touch($sPath);
	chmod($sPath, 0600);
	file_put_contents($sPath, json_encode($aValue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
}

/**
 * @return array<string, list<string>> each class's attribute codes, to report a lost one by name
 */
function AttributeCodes(): array
{
	$aCodes = [];
	foreach (['AltiooOAuthClient', 'AltiooOAuthGrant', 'AltiooOAuthCode'] as $sClass) {
		$aCodes[$sClass] = MetaModel::IsValidClass($sClass) ? array_keys(MetaModel::ListAttributeDefs($sClass)) : [];
	}

	return $aCodes;
}

/**
 * @return array<string, string|null>|null null when the row is gone
 */
function Watched(string $sKind, string $sClass, int $iKey): ?array
{
	$oObject = MetaModel::GetObject($sClass, $iKey, false, true);
	if ($oObject === null) {
		return null;
	}
	$aValues = [];
	foreach (FIXTURE_WATCHED[$sKind] as $sAttCode) {
		$aValues[$sAttCode] = MetaModel::IsValidAttCode($sClass, $sAttCode) ? (string) $oObject->Get($sAttCode) : null;
	}

	return $aValues;
}

/**
 * @return array{0: int, 1: string} the status and the body
 */
function Post(string $sUrl, array $aFields, array $aHeaders): array
{
	$aHeaders[] = 'Content-Type: application/x-www-form-urlencoded';
	$hContext = stream_context_create(['http' => [
		'method' => 'POST',
		'header' => implode("\r\n", $aHeaders),
		'content' => http_build_query($aFields),
		'ignore_errors' => true,
		'timeout' => 120,
	]]);
	$hStream = @fopen($sUrl, 'r', false, $hContext);
	if ($hStream === false) {
		return [0, ''];
	}
	$aResponseHeaders = stream_get_meta_data($hStream)['wrapper_data'] ?? [];
	$sBody = (string) stream_get_contents($hStream);
	fclose($hStream);
	preg_match('#^HTTP/\S+ (\d+)#', (string) ($aResponseHeaders[0] ?? ''), $aMatches);

	return [(int) ($aMatches[1] ?? 0), $sBody];
}

/**
 * @return int|null what rest.php answered this token, 0 being a call served, or null when it
 *     did not answer in JSON
 */
function RestCode(string $sBase, string $sToken): ?int
{
	[, $sBody] = Post($sBase.'/webservices/rest.php', [
		'version' => '1.3',
		'json_data' => '{"operation":"core/get","class":"Organization","key":"SELECT Organization","output_fields":"name","limit":"1"}',
	], ['Auth-Token: '.$sToken]);
	$aAnswer = json_decode($sBody, true);

	return is_array($aAnswer) && isset($aAnswer['code']) ? (int) $aAnswer['code'] : null;
}

/**
 * php -S on a port nobody holds, with its log where the workflow keeps it. It logs paths, and the
 * tokens here travel in a header and a body.
 *
 * @return array{0: resource, 1: string} the process and its base URL
 */
function StartServer(): array
{
	$hProbe = stream_socket_server('tcp://127.0.0.1:0');
	$sAddress = (string) stream_socket_get_name($hProbe, false);
	fclose($hProbe);
	$sLog = APPROOT.'log/ci-upgrade-fixture-httpd.log';
	$hProcess = proc_open([PHP_BINARY, '-S', $sAddress, '-t', rtrim(APPROOT, '/')], [
		0 => ['file', '/dev/null', 'r'],
		1 => ['file', $sLog, 'a'],
		2 => ['file', $sLog, 'a'],
	], $aPipes);
	if (!is_resource($hProcess)) {
		throw new RuntimeException('could not start the HTTP server');
	}
	for ($i = 0; $i < 60; $i++) {
		$hConnection = @fsockopen('tcp://'.$sAddress);
		if ($hConnection !== false) {
			fclose($hConnection);

			return [$hProcess, 'http://'.$sAddress];
		}
		usleep(250000);
	}
	proc_terminate($hProcess);
	throw new RuntimeException('the HTTP server did not start');
}

/**
 * The connection as its client uses it. Keeps the rotated tokens as soon as they exist: past this
 * point the seeded ones are spent.
 */
function UseConnection(array $aState, array &$aSecrets, string $sWhen): void
{
	[$hServer, $sBase] = StartServer();
	try {
		$sEndpoint = $sBase.'/env-'.utils::GetCurrentEnvironment().'/'.FIXTURE_MODULE.'/index.php?route=token';
		check("$sWhen, rest.php takes the access token", RestCode($sBase, $aSecrets['access_token']) === 0);

		[$iStatus, $sBody] = Post($sEndpoint, [
			'grant_type' => 'refresh_token',
			'refresh_token' => $aSecrets['refresh_token'],
		], ['Authorization: Basic '.base64_encode($aState['client_id'].':'.$aSecrets['client_secret'])]);
		$aAnswer = json_decode($sBody, true);
		$sAccess = is_array($aAnswer) ? (string) ($aAnswer['access_token'] ?? '') : '';
		$sRefresh = is_array($aAnswer) ? (string) ($aAnswer['refresh_token'] ?? '') : '';
		$bIssued = $iStatus === 200 && $sAccess !== '' && $sRefresh !== '';
		// Only a refusal is shown, and it is an error code and its reason: a success carries the tokens.
		$sRefusal = $bIssued ? '' : " (HTTP $iStatus: ".(is_array($aAnswer) ? ($aAnswer['error'] ?? '').' '.($aAnswer['error_description'] ?? '') : 'not JSON').')';
		check("$sWhen, the connection refreshes$sRefusal", $bIssued);
		if (!$bIssued) {
			return;
		}

		$sOldAccess = $aSecrets['access_token'];
		$aSecrets['previous_refresh_token'] = $aSecrets['refresh_token'];
		$aSecrets['access_token'] = $sAccess;
		$aSecrets['refresh_token'] = $sRefresh;
		WriteJson(SecretsPath(), $aSecrets);

		check('the refresh rotated the refresh token', $sRefresh !== $aSecrets['previous_refresh_token']);
		check('rest.php takes the new access token', RestCode($sBase, $sAccess) === 0);
		check('and refuses the old one, whose row now has a new value', RestCode($sBase, $sOldAccess) === 1);
	} finally {
		proc_terminate($hServer);
		proc_close($hServer);
	}

	// ADR 0004: the same row, renewed in place.
	$oGrant = MetaModel::GetObject('AltiooOAuthGrant', $aState['keys']['grant'], false, true);
	check('the connection still owns the same PersonalToken', $oGrant !== null && (int) $oGrant->Get('personal_token_id') === $aState['keys']['token']);
	check('and that PersonalToken is the account\'s only one', TokenCount($aState['keys']['user']) === 1);
}

function TokenCount(int $iUserId): int
{
	$oSearch = DBObjectSearch::FromOQL('SELECT PersonalToken WHERE user_id = :user');
	$oSearch->AllowAllData();

	return (new DBObjectSet($oSearch, [], ['user' => $iUserId]))->Count();
}

/**
 * The setting an administrator tuned, the way they tune it: in the configuration file. rest.php's
 * own rule and the shipped label, so that the instance answers as it did: what is under test is
 * that the value is still there. The mode is iTop's 0440, which upgrade-module.sh deals with too.
 */
function TuneResources(): array
{
	$aResources = ['rest' => [
		'url' => utils::GetAbsoluteUrlAppRoot().'webservices/rest.php',
		'label' => 'UI:AltiooOAuthServer:Resource:REST',
		'scope_prefix' => FIXTURE_SCOPE,
		'bearer_methods' => [],
		'required_profiles' => ['REST Services User'],
	]];
	$sConfig = utils::GetConfigFilePath();
	chmod($sConfig, 0640);
	$sContents = preg_replace('/\?>\s*\z/', '', (string) file_get_contents($sConfig));
	file_put_contents($sConfig, $sContents."\n\$MyModuleSettings['".FIXTURE_MODULE."']['resources'] = ".var_export($aResources, true).";\n?>\n");
	chmod($sConfig, 0440);

	return $aResources;
}

function Seed(): void
{
	if (is_file(StatePath())) {
		throw new RuntimeException('this instance is already seeded');
	}
	// Creating an account with a profile is an administrator's act: iTop refuses it otherwise.
	UserRights::Login(getenv('ITOP_ADMIN_USER') ?: 'admin');

	// An account of its own: the smoke later in the job changes what the administrator holds.
	$oUser = MetaModel::NewObject('UserLocal', ['login' => 'upgrade-fixture', 'language' => 'EN US', 'expiration' => 'never_expire']);
	$oUser->Set('password', 'Aa1!'.bin2hex(random_bytes(12)));
	$oProfiles = $oUser->Get('profile_list');
	foreach (['Administrator', 'REST Services User'] as $sProfile) {
		$oProfiles->AddItem(MetaModel::NewObject('URP_UserProfile', [
			'profileid' => MetaModel::GetObjectByColumn('URP_Profiles', 'name', $sProfile)->GetKey(),
			'reason' => 'altioo-oauth-server upgrade fixture',
		]));
	}
	$oUser->Set('profile_list', $oProfiles);
	$oUser->AllowWrite();
	$oUser->DBInsert();
	$iUserId = (int) $oUser->GetKey();

	$sName = "Client d'été, à l'essai ";
	$sName .= str_repeat('é', 80 - mb_strlen($sName));
	$sClientSecret = bin2hex(random_bytes(24));
	$oClient = MetaModel::NewObject('AltiooOAuthClient', [
		'client_name' => $sName,
		'redirect_uris' => "https://fixture.example/callback\nhttps://fixture.example/other",
		'auth_method' => 'client_secret_basic',
		'pkce' => 'optional',
		'default_resource' => 'rest',
		'source' => 'manual',
	]);
	$oClient->Set('client_secret', $sClientSecret);
	$oClient->AllowWrite();
	$oClient->DBInsert();

	$fnConnection = static function () use ($oClient, $iUserId, $sName): array {
		[$oToken, $sAccess] = PersonalTokens::Create($iUserId, $sName, [FIXTURE_SCOPE], 3600);
		$sSecret = Secret::NewSecret();
		$oGrant = MetaModel::NewObject('AltiooOAuthGrant', [
			'client_id' => $oClient->GetKey(),
			'user_id' => $iUserId,
			'resource' => 'rest',
			'scope' => FIXTURE_SCOPE,
			'personal_token_id' => $oToken->GetKey(),
			'console_access' => 'yes',
			'status' => 'active',
			'creation_date' => date('Y-m-d H:i:s'),
			'expiration_date' => date('Y-m-d H:i:s', time() + 30 * 86400),
			'last_refresh_date' => date('Y-m-d H:i:s'),
			'last_refresh_ip' => '127.0.0.1',
		]);
		$oGrant->Set('refresh_secret', $sSecret);
		$oGrant->AllowWrite();
		$oGrant->DBInsert();

		return [$oGrant, $oToken, $sAccess, $sSecret];
	};

	[$oEnded] = $fnConnection();
	Grants::End($oEnded, 'revoked');
	[$oGrant, $oToken, $sAccess, $sSecret] = $fnConnection();

	$sCodeSecret = Secret::NewSecret();
	$oCode = MetaModel::NewObject('AltiooOAuthCode', [
		'client_id' => $oClient->GetKey(),
		'user_id' => $iUserId,
		'redirect_uri' => 'https://fixture.example/callback',
		'code_challenge' => str_repeat('a', 43),
		'resource' => 'rest',
		'scope' => FIXTURE_SCOPE,
		'expiration_date' => date('Y-m-d H:i:s', time() + 86400),
	]);
	$oCode->Set('code_secret', $sCodeSecret);
	$oCode->AllowWrite();
	$oCode->DBInsert();

	$aState = [
		'client_id' => (string) $oClient->Get('client_id'),
		'keys' => [
			'client' => (int) $oClient->GetKey(),
			'grant' => (int) $oGrant->GetKey(),
			'ended_grant' => (int) $oEnded->GetKey(),
			'code' => (int) $oCode->GetKey(),
			'token' => (int) $oToken->GetKey(),
			'user' => $iUserId,
		],
		'attributes' => AttributeCodes(),
		'resources' => TuneResources(),
	];
	$aState['rows'] = Rows($aState['keys']);
	WriteJson(StatePath(), $aState);

	$aSecrets = [
		'client_secret' => $sClientSecret,
		'access_token' => $sAccess,
		'refresh_token' => Secret::Join((int) $oGrant->GetKey(), $sSecret),
		'code_secret' => $sCodeSecret,
	];
	WriteJson(SecretsPath(), $aSecrets);

	UseConnection($aState, $aSecrets, 'on the baseline');
	echo "  seeded a client, two connections, a code, a token and a tuned setting\n";
}

/**
 * @return array<string, array<string, string|null>|null>
 */
function Rows(array $aKeys): array
{
	return [
		'client' => Watched('client', 'AltiooOAuthClient', $aKeys['client']),
		'grant' => Watched('grant', 'AltiooOAuthGrant', $aKeys['grant']),
		'ended_grant' => Watched('grant', 'AltiooOAuthGrant', $aKeys['ended_grant']),
		'code' => Watched('code', 'AltiooOAuthCode', $aKeys['code']),
		'token' => Watched('token', 'PersonalToken', $aKeys['token']),
		'user' => Watched('user', 'User', $aKeys['user']),
	];
}

function Verify(): void
{
	$aState = ReadJson(StatePath());
	$aSecrets = ReadJson(SecretsPath());

	$aNow = AttributeCodes();
	foreach ($aState['attributes'] as $sClass => $aCodes) {
		$aLost = array_diff($aCodes, $aNow[$sClass] ?? []);
		check("$sClass keeps its attributes".($aLost === [] ? '' : ': lost '.implode(', ', $aLost)), $aLost === []);
	}

	$aRows = Rows($aState['keys']);
	foreach ($aState['rows'] as $sRow => $aSeeded) {
		$aRow = $aRows[$sRow];
		check("the $sRow row is still there", $aRow !== null);
		if ($aRow === null) {
			continue;
		}
		$aChanged = array_keys(array_diff_assoc($aSeeded, $aRow));
		check("the $sRow row says what it said".($aChanged === [] ? '' : ': changed '.implode(', ', $aChanged)), $aChanged === []);
	}

	$oClient = MetaModel::GetObject('AltiooOAuthClient', $aState['keys']['client'], false, true);
	check('the client\'s secret still matches', $oClient !== null && $oClient->Get('client_secret')->CheckPassword($aSecrets['client_secret']));
	$oCode = MetaModel::GetObject('AltiooOAuthCode', $aState['keys']['code'], false, true);
	check('the code\'s secret still matches', $oCode !== null && $oCode->Get('code_secret')->CheckPassword($aSecrets['code_secret']));
	$oGrant = MetaModel::GetObject('AltiooOAuthGrant', $aState['keys']['grant'], false, true);
	check('the current refresh secret still matches', $oGrant !== null && $oGrant->Get('refresh_secret')->CheckPassword(Secret::Split($aSecrets['refresh_token'])[1]));
	check('the previous refresh secret still matches', $oGrant !== null && $oGrant->Get('previous_refresh_secret')->CheckPassword(Secret::Split($aSecrets['previous_refresh_token'])[1]));

	check('the tuned resources setting is still there', MetaModel::GetModuleSetting(FIXTURE_MODULE, 'resources', []) === $aState['resources']);

	UseConnection($aState, $aSecrets, 'after the upgrade');
}

// Anything unexpected reaches the log by its class and where it was thrown, with no message: an
// exception about a request can carry what the request carried.
try {
	$sMode === 'seed' ? Seed() : Verify();
} catch (Throwable $e) {
	$sReason = $e instanceof RuntimeException ? $e->getMessage() : get_class($e).' at '.basename($e->getFile()).':'.$e->getLine();
	check($sMode.' stopped: '.$sReason, false);
}

if (count($aFailures) > 0) {
	fwrite(STDERR, "\n".count($aFailures)." check(s) failed\n");
	exit(1);
}
