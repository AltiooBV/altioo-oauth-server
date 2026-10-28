<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Controller;

use Altioo\iTop\Extension\OAuthServer\Config;
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

	public static function HandleRequest(): never
	{
		$sRoute = utils::ReadParam('route', '', false, 'raw_data');
		try {
			match ($sRoute) {
				'openid-configuration', 'oauth-authorization-server' => MetadataController::ServerMetadata(),
				'protected-resource' => MetadataController::ProtectedResource(utils::ReadParam('for', '', false, 'raw_data')),
				'register' => RegistrationController::Register(),
				'authorize' => AuthorizationController::Authorize(),
				'token' => TokenController::Token(),
				'disconnect' => ConnectionsController::Disconnect(),
				default => Http::OAuthError('invalid_request', 'unknown endpoint', 404),
			};
		} catch (OAuthException $e) {
			if ($e->iStatus === 401) {
				header('WWW-Authenticate: Basic realm="'.Config::MODULE.'"');
			}
			Http::OAuthError($e->sError, $e->sDescription, $e->iStatus);
		} catch (PageException $e) {
			Http::Page($e->iStatus, Dict::S($e->sKey), '<p>'.Http::Escape(Dict::S($e->sKey.'+')).'</p>');
		} catch (Throwable $e) {
			// The detail goes to the log only; the message may name a class or a query.
			IssueLog::Error('Unexpected error on route '.$sRoute.': '.$e->getMessage(), self::LOG_CHANNEL, ['exception' => get_class($e)]);
			if ($sRoute === 'authorize') {
				Http::Page(500, Dict::S('UI:AltiooOAuthServer:Page:Error'), '<p>'.Http::Escape(Dict::S('UI:AltiooOAuthServer:Page:Error+')).'</p>');
			}
			Http::OAuthError('server_error', '', 500);
		}
	}
}
