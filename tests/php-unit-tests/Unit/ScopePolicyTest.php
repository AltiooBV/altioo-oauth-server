<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Test\Unit;

use Altioo\iTop\Extension\OAuthServer\ScopePolicy;
use PHPUnit\Framework\TestCase;

final class ScopePolicyTest extends TestCase
{
	private const FAMILY = ['MCP', 'MCP-read', 'MCP-write', 'MCP-delete', 'MCP-advisory'];

	public function testEverythingInTheFamilyIsOfferedByDefault(): void
	{
		$this->assertSame(self::FAMILY, (new ScopePolicy(self::FAMILY))->Offered([]));
	}

	public function testTheAdministratorCapsTheOffer(): void
	{
		$oPolicy = new ScopePolicy(self::FAMILY, ['MCP-read', 'MCP-write', 'Export']);
		$this->assertSame(['MCP-read', 'MCP-write'], $oPolicy->Offered(['MCP', 'MCP-read', 'MCP-write', 'Export']));
	}

	public function testAClientMayAskForLessButNotForMore(): void
	{
		$oPolicy = new ScopePolicy(self::FAMILY, ['MCP-read', 'MCP-write']);
		$this->assertSame(['MCP-read'], $oPolicy->Offered(['MCP-read', 'MCP']));
		$this->assertSame(['MCP-read', 'MCP-write'], $oPolicy->Offered(['REST/JSON']));
	}

	public function testWhatThePageDidNotOfferIsNeverGranted(): void
	{
		$oPolicy = new ScopePolicy(self::FAMILY, ['MCP-read']);
		$aOffered = $oPolicy->Offered([]);
		$this->assertSame(['MCP-read'], $oPolicy->Granted($aOffered, ['MCP-read', 'MCP', 'REST/JSON']));
		$this->assertSame([], $oPolicy->Granted($aOffered, ['MCP']));
	}

	public function testForcedScopesAreAddedAndNotOffered(): void
	{
		$oPolicy = new ScopePolicy(self::FAMILY, [], [], ['MCP-advisory']);
		$aOffered = $oPolicy->Offered([]);
		$this->assertNotContains('MCP-advisory', $aOffered);
		$this->assertSame(['MCP-write', 'MCP-advisory'], $oPolicy->Granted($aOffered, ['MCP-write']));
	}

	public function testNothingTickedGrantsNothing(): void
	{
		$oPolicy = new ScopePolicy(self::FAMILY, [], [], ['MCP-advisory']);
		$this->assertSame([], $oPolicy->Granted($oPolicy->Offered([]), []));
	}

	public function testTheDefaultIsPreselectedOrElseTheFirstOffered(): void
	{
		$this->assertSame(['MCP-read'], (new ScopePolicy(self::FAMILY, [], ['MCP-read']))->Preselected(self::FAMILY));
		$this->assertSame(['MCP-write'], (new ScopePolicy(self::FAMILY, [], ['MCP-read']))->Preselected(['MCP-write']));
	}
}
