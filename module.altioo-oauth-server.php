<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */
//
// iTop module definition file
//

// The version below and the one in extension.xml are the same string.
SetupWebPage::AddModule(
	__FILE__, // Path to the current file, all other file names are relative to the directory containing this file
	'altioo-oauth-server/0.1.0',
	array(
		// Identification
		//
		'label' => 'Altioo OAuth Server',
		'category' => 'authentication',

		// Setup
		//
		'dependencies' => array(
			// extension.xml cannot state an iTop floor; a mandatory core module carrying the
			// branch can. No operator means ">=". The floor is 3.2.1, where the portal's My
			// profile hooks (src/Hook/PortalConnections.php) appear: on 3.2.0, iTop's class
			// discovery would load a class naming a missing interface, a fatal error on every
			// page that discovers hooks.
			'itop-structure/3.2.1',
			// Those hooks, and the autoloader that declares them, are this module's.
			'itop-portal-base/3.2.1',
			'authent-token/2.2.1',
		),
		'mandatory' => false,
		'visible' => true,

		// Components
		//
		'datamodel' => array(
			'vendor/autoload.php',
		),
		'webservice' => array(
		),
		'data.struct' => array(
		),
		'data.sample' => array(
		),

		// Documentation
		//
		'doc.manual_setup' => 'https://github.com/AltiooBV/altioo-oauth-server/blob/main/README.md',
		'doc.more_information' => 'https://github.com/AltiooBV/altioo-oauth-server/blob/main/README.md',

		// Default settings
		//
		// Declared in datamodel.altioo-oauth-server.xml, <module_parameters>.
		'settings' => array(
		),
	)
);
