<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer;

use Dict;

/**
 * What the endpoints send back. Every response is no-store, since each one is a credential or
 * leads to one, except a discovery document, which a client may keep for a few minutes.
 *
 * @since 0.1.0
 */
final class Http
{
	/**
	 * private, not public: iTop opens a session for every request, so the response may carry a
	 * session cookie, which no shared cache may hand to anyone else.
	 */
	public const CACHE_DISCOVERY = 'private, max-age=300';

	public static function Json(int $iStatus, array $aBody, string $sCacheControl = 'no-store'): never
	{
		http_response_code($iStatus);
		header('Content-Type: application/json');
		header('Cache-Control: '.$sCacheControl);
		if ($sCacheControl === 'no-store') {
			header('Pragma: no-cache');
		} else {
			// The session start already sent PHP's nocache limiter: a past Expires and a Pragma
			// that would contradict the Cache-Control above.
			header_remove('Expires');
			header_remove('Pragma');
		}
		// An empty answer is an empty object, never a list.
		echo json_encode($aBody === [] ? new \stdClass() : $aBody, JSON_UNESCAPED_SLASHES);
		exit;
	}

	public static function OAuthError(string $sError, string $sDescription = '', int $iStatus = 400): never
	{
		$aBody = ['error' => $sError];
		if ($sDescription !== '') {
			$aBody['error_description'] = $sDescription;
		}
		self::Json($iStatus, $aBody);
	}

	public static function Redirect(string $sUrl): never
	{
		http_response_code(302);
		header('Cache-Control: no-store');
		header('Location: '.$sUrl);
		exit;
	}

	/**
	 * A page of the authorize flow. It cannot be framed, so nobody can lay the consent buttons
	 * under a page of their own.
	 *
	 * @param string $sBodyHtml already escaped
	 */
	public static function Page(int $iStatus, string $sTitle, string $sBodyHtml): never
	{
		http_response_code($iStatus);
		header('Content-Type: text/html; charset=utf-8');
		header('Cache-Control: no-store');
		header('X-Frame-Options: DENY');
		header("Content-Security-Policy: frame-ancestors 'none'");
		header('Referrer-Policy: no-referrer');
		$sTitleHtml = self::Escape($sTitle);
		$sLang = self::Escape(strtolower(substr((string) Dict::GetUserLanguage(), 0, 2)));
		echo <<<HTML
<!doctype html>
<html lang="$sLang">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>$sTitleHtml</title>
<style>
body { font-family: system-ui, sans-serif; line-height: 1.5; max-width: 36rem; margin: 3rem auto; padding: 0 1rem; color: #1f2933; }
h1 { font-size: 1.5rem; }
.host { font-size: 1.6rem; font-weight: 600; word-break: break-all; }
.note { color: #52606d; font-size: .9rem; }
fieldset { border: 1px solid #cbd2d9; border-radius: .25rem; margin: 1rem 0; }
label { display: block; margin: .25rem 0; }
button { font-size: 1rem; padding: .5rem 1.25rem; margin-right: .5rem; }
@media (prefers-color-scheme: dark) { body { background: #1f2933; color: #f5f7fa; } .note { color: #cbd2d9; } }
</style>
</head>
<body>
<h1>$sTitleHtml</h1>
$sBodyHtml
</body>
</html>
HTML;
		exit;
	}

	public static function Escape(string $s): string
	{
		return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}
}
