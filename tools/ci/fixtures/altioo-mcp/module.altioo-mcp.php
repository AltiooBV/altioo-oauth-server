<?php
/**
 * A stand-in for altioo-mcp, for CI only (doc/ci-itop-matrix.md, "The MCP stand-in").
 *
 * It has altioo-mcp's module code on purpose: altioo-oauth-server's default `mcp` resource is
 * <modules root>/altioo-mcp/index.php and reads its profiles from this module's setting, so a
 * stand-in under another code would sit at another URL and test a configuration nobody runs.
 * The same reason means it can never be installed beside the real module.
 *
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

SetupWebPage::AddModule(
	__FILE__,
	'altioo-mcp/0.0.1',
	array(
		'label' => 'altioo-mcp stand-in (CI only)',
		'category' => 'Application management',
		'dependencies' => array(
			'itop-structure/3.2.0',
			'authent-token/2.2.1',
		),
		'mandatory' => false,
		'visible' => true,
		'datamodel' => array(),
		'webservice' => array(),
		'data.struct' => array(),
		'data.sample' => array(),
		'doc.manual_setup' => '',
		'doc.more_information' => '',
		'settings' => array(),
	)
);
