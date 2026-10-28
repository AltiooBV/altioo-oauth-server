<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Test\Unit;

use Altioo\iTop\Extension\OAuthServer\Secret;
use PHPUnit\Framework\TestCase;

final class SecretTest extends TestCase
{
	public function testAJoinedValueSplitsBack(): void
	{
		$sSecret = Secret::NewSecret();
		$this->assertSame([42, $sSecret], Secret::Split(Secret::Join(42, $sSecret)));
	}

	public function testSecretsAre256BitsAndDiffer(): void
	{
		$sSecret = Secret::NewSecret();
		$this->assertSame(32, strlen(base64_decode(strtr($sSecret, '-_', '+/'))));
		$this->assertNotSame($sSecret, Secret::NewSecret());
	}

	/**
	 * @dataProvider malformed
	 */
	public function testAMalformedValueDoesNotSplit(string $sValue): void
	{
		$this->assertNull(Secret::Split($sValue));
	}

	public static function malformed(): array
	{
		$sSecret = str_repeat('a', 43);

		return [
			'empty' => [''],
			'no key' => ['.'.$sSecret],
			'key 0' => ['0.'.$sSecret],
			'leading zero' => ['01.'.$sSecret],
			'negative key' => ['-1.'.$sSecret],
			'empty secret' => ['1.'],
			'short secret' => ['1.'.substr($sSecret, 1)],
			'secret with a dot' => ['1.'.substr($sSecret, 1).'.'],
			'trailing newline' => ['1.'.$sSecret."\n"],
		];
	}
}
