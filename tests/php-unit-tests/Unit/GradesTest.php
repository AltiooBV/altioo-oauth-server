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
}
