<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Test\Unit;

use Altioo\iTop\Extension\OAuthServer\Controller\Router;
use Altioo\iTop\Extension\OAuthServer\Service\PersonalTokens;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * A person who may connect an application may end the connection.
 *
 * iTop asks the signed-in person's own right to delete the token, which only
 * administrators hold, so a portal user's Disconnect was refused ("You are not
 * allowed to delete this object") and the grant stayed active. The decision
 * needs iTop; these pin the wiring, and the live run the behaviour.
 */
final class EndingAConnectionTest extends TestCase
{
	public function testTheTokenIsDeletedWithoutAskingThePersonsOwnRight(): void
	{
		$sBody = $this->bodyOf(PersonalTokens::class, 'Delete');

		$this->assertStringContainsString('AllowDelete()', $sBody);
		$this->assertLessThan(strpos($sBody, 'DBDelete()'), strpos($sBody, 'AllowDelete()'), 'allowed after the delete');
	}

	public function testADisconnectThatFailsAnswersWithAPage(): void
	{
		$sSource = file_get_contents((new ReflectionClass(Router::class))->getFileName());

		$this->assertMatchesRegularExpression("/'authorize'\s*\|\|\s*\\\$sRoute\s*===\s*'disconnect'/", $sSource);
	}

	private function bodyOf(string $sClass, string $sMethod): string
	{
		$oMethod = new ReflectionMethod($sClass, $sMethod);
		$aLines = file($oMethod->getFileName());

		return implode('', array_slice($aLines, $oMethod->getStartLine() - 1, $oMethod->getEndLine() - $oMethod->getStartLine() + 1));
	}
}
