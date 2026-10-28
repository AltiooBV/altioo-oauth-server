<?php
/**
 * The one entry point of the authorization server: discovery, registration, authorize and
 * token are all routes on this file (src/Controller/Router.php).
 *
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

use Altioo\iTop\Extension\OAuthServer\Controller\Router;

require_once dirname(__DIR__, 2).'/approot.inc.php';

require_once(APPROOT.'/application/application.inc.php');
require_once(APPROOT.'/application/loginwebpage.class.inc.php');

// Loads this module's autoloader too: it is the first entry of the module's 'datamodel'
// array. Requiring vendor/autoload.php here as well would declare its class twice when the
// file is served from extensions/ rather than the compiled env-<env>/ (see altioo-mcp's
// index.php, which found out).
require_once(APPROOT.'/application/startup.inc.php');

Router::HandleRequest();
