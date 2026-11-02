<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Test\Unit;

use Altioo\iTop\Extension\OAuthServer\Service\Accounts;
use PHPUnit\Framework\TestCase;

final class AccountsTest extends TestCase
{
	public function testAPortalAccountGivenTheConsoleHasChangedKind(): void
	{
		$this->assertTrue(Accounts::KindChanged('no', true));
	}

	public function testAConsoleAccountLeftWithThePortalHasChangedKind(): void
	{
		$this->assertTrue(Accounts::KindChanged('yes', false));
	}

	public function testAnAccountOfTheSameKindHasNot(): void
	{
		$this->assertFalse(Accounts::KindChanged('no', false));
		$this->assertFalse(Accounts::KindChanged('yes', true));
	}

	public function testAConnectionMadeBeforeTheKindWasRecordedNeverChanges(): void
	{
		$this->assertFalse(Accounts::KindChanged('', true));
		$this->assertFalse(Accounts::KindChanged('', false));
	}
}
