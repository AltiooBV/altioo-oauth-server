<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Test\Unit;

use Altioo\iTop\Extension\OAuthServer\PendingRequests;
use PHPUnit\Framework\TestCase;

final class PendingRequestsTest extends TestCase
{
	public function testExpiredRequestsAreDropped(): void
	{
		$aPruned = PendingRequests::Prune(['old' => ['expires' => 99], 'live' => ['expires' => 100]], 100, 10);
		$this->assertSame(['live'], array_keys($aPruned));
	}

	public function testOnlyTheNewestAreKept(): void
	{
		$aPending = [];
		for ($i = 1; $i <= 50; $i++) {
			$aPending["r$i"] = ['expires' => 1000 + $i];
		}
		$this->assertSame(['r48', 'r49', 'r50'], array_keys(PendingRequests::Prune($aPending, 0, 3)));
	}

	public function testWhateverTheSessionHeldIsSurvived(): void
	{
		$this->assertSame([], PendingRequests::Prune('not an array', 0, 3));
		$this->assertSame([], PendingRequests::Prune(['x' => 'not a request'], 0, 3));
	}
}
