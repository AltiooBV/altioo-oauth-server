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

	public function testAGrantMadeEarlierIsNarrowedToTodaysOffer(): void
	{
		$oPolicy = new ScopePolicy(self::FAMILY, ['MCP-read'], [], ['MCP-advisory']);
		$this->assertSame(['MCP-read', 'MCP-advisory'], $oPolicy->Current(['MCP-read', 'MCP-write']));
	}

	public function testAGrantWithNothingStillOfferedKeepsNothing(): void
	{
		$this->assertSame([], (new ScopePolicy(self::FAMILY, ['MCP-read'], [], ['MCP-advisory']))->Current(['MCP-write']));
	}

	/**
	 * altioo-mcp reads a token holding only a limit as one that names no grade, and serves it
	 * every grade the instance allows: kept alone, the limit would let a "never delete"
	 * connection delete.
	 */
	public function testAGrantLeftWithOnlyALimitKeepsNothing(): void
	{
		$oPolicy = new ScopePolicy(self::FAMILY, ['MCP-read', 'MCP-advisory']);
		$this->assertSame([], $oPolicy->Current(['MCP-write', 'MCP-advisory'], ['MCP-advisory']));
		$this->assertSame(['MCP-read', 'MCP-advisory'], $oPolicy->Current(['MCP-read', 'MCP-advisory'], ['MCP-advisory']));
	}

	public function testWithinNarrowsTheOfferAndWhatAGrantKeeps(): void
	{
		$oPolicy = (new ScopePolicy(self::FAMILY, ['MCP-read', 'MCP-write', 'MCP-delete', 'MCP']))->Within(['MCP-read', 'MCP']);
		$this->assertSame(['MCP', 'MCP-read'], $oPolicy->Offered([]));
		$this->assertSame([], $oPolicy->Current(['MCP-write', 'MCP-delete']));
		$this->assertSame(['MCP'], $oPolicy->Current(['MCP']));
	}

	/** An empty $aOffered means the whole family; an empty Within means nothing at all. */
	public function testWithinNothingOffersNothing(): void
	{
		$this->assertSame([], (new ScopePolicy(self::FAMILY))->Within([])->Offered([]));
		$this->assertSame([], (new ScopePolicy(self::FAMILY, ['MCP-read']))->Within(['MCP'])->Offered([]));
	}
}
