<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Controller;

use Altioo\iTop\Extension\OAuthServer\Config;
use Altioo\iTop\Extension\OAuthServer\CorsPolicy;
use Altioo\iTop\Extension\OAuthServer\Exception\OAuthException;
use Altioo\iTop\Extension\OAuthServer\Exception\PageException;
use Altioo\iTop\Extension\OAuthServer\Http;
use Dict;
use IssueLog;
use Throwable;
use utils;

/**
 * Every endpoint is index.php?route=<name>. The two discovery paths reach it through the
 * module's .htaccess or web.config.
 *
 * @since 0.1.0
 */
final class Router
{
	public const LOG_CHANNEL = 'altioo-oauth-server';
	/** Readable from any origin (CorsPolicy says why). */
	private const DISCOVERY_ROUTES = ['openid-configuration', 'oauth-authorization-server', 'protected-resource'];
	/** The other routes a program calls; authorize and disconnect are pages, and need no CORS. */
	private const CORS_ROUTES = ['register', 'token', 'revoke'];

	public static function HandleRequest(): never
	{
		$sRoute = utils::ReadParam('route', '', false, 'raw_data');
		if (in_array($sRoute, self::DISCOVERY_ROUTES, true)) {
			self::SendCors(CorsPolicy::PublicHeaders(self::Method()));
		} elseif (in_array($sRoute, self::CORS_ROUTES, true)) {
			$sOrigin = is_string($_SERVER['HTTP_ORIGIN'] ?? null) ? $_SERVER['HTTP_ORIGIN'] : '';
			self::SendCors(CorsPolicy::Headers($sOrigin, Config::AllowedOrigins(), self::Method()));
		}
		try {
			match ($sRoute) {
				'openid-configuration', 'oauth-authorization-server' => MetadataController::ServerMetadata(),
				'protected-resource' => MetadataController::ProtectedResource(utils::ReadParam('for', '', false, 'raw_data')),
				'register' => RegistrationController::Register(),
				'authorize' => AuthorizationController::Authorize(),
				'token' => TokenController::Token(),
				'revoke' => TokenController::Revoke(),
				'disconnect' => ConnectionsController::Disconnect(),
				default => Http::OAuthError('invalid_request', 'unknown endpoint', 404),
			};
		} catch (OAuthException $e) {
			// Every refusal leaves a line, without the values that were refused: a stream of
			// failed client authentications or a reuse is otherwise invisible.
			IssueLog::Warning("Refused on route $sRoute: {$e->sError}".($e->sDescription === '' ? '' : " ({$e->sDescription})"), self::LOG_CHANNEL, [
				'status' => $e->iStatus,
				'ip' => is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '',
			]);
			if ($e->iStatus === 401) {
				header('WWW-Authenticate: Basic realm="'.Config::MODULE.'"');
			}
			Http::OAuthError($e->sError, $e->sDescription, $e->iStatus);
		} catch (PageException $e) {
			IssueLog::Warning("Refused on route $sRoute: {$e->sKey}", self::LOG_CHANNEL, ['status' => $e->iStatus]);
			Http::Page($e->iStatus, Dict::S($e->sKey), '<p>'.Http::Escape(Dict::S($e->sKey.'+')).'</p>');
		} catch (Throwable $e) {
			// The detail goes to the log only; the message may name a class or a query.
			IssueLog::Error('Unexpected error on route '.$sRoute.': '.$e->getMessage(), self::LOG_CHANNEL, ['exception' => get_class($e)]);
			// The routes a person reaches in a browser answer with a page, not a JSON error.
			if ($sRoute === 'authorize' || $sRoute === 'disconnect') {
				Http::Page(500, Dict::S('UI:AltiooOAuthServer:Page:Error'), '<p>'.Http::Escape(Dict::S('UI:AltiooOAuthServer:Page:Error+')).'</p>');
			}
			Http::OAuthError('server_error', '', 500);
		}
	}

	/**
	 * @param list<string> $aHeaders
	 */
	private static function SendCors(array $aHeaders): void
	{
		foreach ($aHeaders as $sHeader) {
			header($sHeader);
		}
		if ($aHeaders !== [] && CorsPolicy::IsPreflight(self::Method())) {
			http_response_code(204);
			exit;
		}
	}

	private static function Method(): string
	{
		return is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : '';
	}
}
