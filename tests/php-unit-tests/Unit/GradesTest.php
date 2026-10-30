<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Test\Unit;

use Altioo\iTop\Extension\OAuthServer\Grades;
use PHPUnit\Framework\TestCase;

final class GradesTest extends TestCase
{
	private const GRADES = [
		['label' => 'Read', 'scopes' => ['MCP-read']],
		['label' => 'Write', 'scopes' => ['MCP-write']],
		['label' => 'Delete', 'scopes' => ['MCP-write', 'MCP-delete']],
		['label' => 'All', 'scopes' => ['MCP']],
	];
	private const LIMITS = [
		['label' => 'Rehearse', 'scopes' => ['MCP-advisory']],
	];

	public function testAGradeIsOfferedOnlyIfEveryOneOfItsScopesIs(): void
	{
		$aUsable = Grades::Usable(self::GRADES, ['MCP-read', 'MCP-write', 'MCP-advisory']);
		$this->assertSame([0, 1], array_keys($aUsable));
	}

	public function testThePositionInTheSettingIsKept(): void
	{
		$this->assertSame([3], array_keys(Grades::Usable(self::GRADES, ['MCP'])));
	}

	public function testTheDefaultScopesChooseTheGrade(): void
	{
		$aUsable = Grades::Usable(self::GRADES, ['MCP', 'MCP-read', 'MCP-write', 'MCP-delete']);
		$this->assertSame(0, Grades::Chosen($aUsable, ['MCP-read']));
		$this->assertSame(1, Grades::Chosen($aUsable, ['MCP-write']));
		$this->assertSame(0, Grades::Chosen($aUsable, []));
	}

	public function testScopesNoGradeCoversStayCheckboxes(): void
	{
		$aOffered = ['MCP-read', 'MCP-write', 'MCP-advisory', 'MCP-toolset-objects'];
		$this->assertSame(['MCP-advisory', 'MCP-toolset-objects'], Grades::Uncovered(Grades::Usable(self::GRADES, $aOffered), $aOffered));
	}

	public function testAClientAskingForTheWidestStillLeavesTheNarrowerGrades(): void
	{
		$aAdminOffer = ['MCP', 'MCP-read', 'MCP-write', 'MCP-delete', 'MCP-advisory'];
		$aOffered = Grades::WithNarrower(self::GRADES, ['MCP'], $aAdminOffer);
		$this->assertSame([0, 1, 2, 3], array_keys(Grades::Usable(self::GRADES, $aOffered)));
	}

	public function testAClientAskingForLessIsNotOfferedMore(): void
	{
		$this->assertSame(['MCP-read'], Grades::WithNarrower(self::GRADES, ['MCP-read'], ['MCP', 'MCP-read', 'MCP-write']));
	}

	public function testAGradeTheAdministratorDoesNotOfferIsNotAddedBack(): void
	{
		$aOffered = Grades::WithNarrower(self::GRADES, ['MCP'], ['MCP', 'MCP-read']);
		$this->assertSame([0, 3], array_keys(Grades::Usable(self::GRADES, $aOffered)));
	}

	public function testALimitIsOfferedEvenWhenTheClientDidNotAskForIt(): void
	{
		$aOffered = Grades::WithLimits(self::LIMITS, ['MCP-read'], ['MCP-read', 'MCP-write', 'MCP-advisory']);
		$this->assertSame(['MCP-read', 'MCP-advisory'], $aOffered);
	}

	public function testALimitTheAdministratorDoesNotOfferIsNotAdded(): void
	{
		$this->assertSame(['MCP-read'], Grades::WithLimits(self::LIMITS, ['MCP-read'], ['MCP-read', 'MCP-write']));
	}

	public function testALimitIsNotAlsoOfferedAsAnExtraCheckbox(): void
	{
		$aOffered = ['MCP-read', 'MCP-write', 'MCP-advisory'];
		$aUsable = array_merge(Grades::Usable(self::GRADES, $aOffered), Grades::Usable(self::LIMITS, $aOffered));
		$this->assertSame([], Grades::Uncovered($aUsable, $aOffered));
	}

	public function testAConnectionIsDescribedByItsWidestGradeAndItsLimits(): void
	{
		$this->assertSame(
			['labels' => ['Delete', 'Rehearse'], 'rest' => []],
			Grades::Describe(self::GRADES, self::LIMITS, ['MCP-write', 'MCP-delete', 'MCP-advisory'])
		);
	}

	public function testScopesNoGradeOrLimitCoversAreDescribedOnTheirOwn(): void
	{
		$this->assertSame(
			['labels' => ['Read'], 'rest' => ['MCP-toolset-objects']],
			Grades::Describe(self::GRADES, self::LIMITS, ['MCP-read', 'MCP-toolset-objects'])
		);
		$this->assertSame(['labels' => [], 'rest' => ['REST/JSON']], Grades::Describe([], [], ['REST/JSON']));
	}
}
