<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Test\Unit;

use Altioo\iTop\Extension\OAuthServer\Pkce;
use PHPUnit\Framework\TestCase;

final class PkceTest extends TestCase
{
	/** The example of RFC 7636, appendix B. */
	private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

	public function testTheRfcExampleVerifies(): void
	{
		$this->assertSame(self::CHALLENGE, Pkce::Challenge(self::VERIFIER));
		$this->assertTrue(Pkce::Verify(self::CHALLENGE, self::VERIFIER));
	}

	public function testAnotherVerifierIsRefused(): void
	{
		$this->assertFalse(Pkce::Verify(self::CHALLENGE, str_repeat('a', 43)));
	}

	/** Presenting the challenge itself as the verifier is what the plain method would accept. */
	public function testThePlainMethodIsNotAccepted(): void
	{
		$this->assertFalse(Pkce::Verify(self::CHALLENGE, self::CHALLENGE));
	}

	/**
	 * @dataProvider badShapes
	 */
	public function testAVerifierOrChallengeOfTheWrongShapeIsRefused(string $sValue): void
	{
		$this->assertFalse(Pkce::IsValidChallenge($sValue));
		$this->assertFalse(Pkce::Verify(Pkce::Challenge($sValue), $sValue));
	}

	public static function badShapes(): array
	{
		return [
			'empty' => [''],
			'42 characters' => [str_repeat('a', 42)],
			'129 characters' => [str_repeat('a', 129)],
			'a character outside the unreserved set' => [str_repeat('a', 42).'+'],
		];
	}
}
