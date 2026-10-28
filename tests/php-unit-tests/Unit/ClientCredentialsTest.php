<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Test\Unit;

use Altioo\iTop\Extension\OAuthServer\ClientCredentials;
use PHPUnit\Framework\TestCase;

final class ClientCredentialsTest extends TestCase
{
	private const ID = '0123456789abcdef0123456789abcdef';

	public function testAPublicClientNamesItselfInTheForm(): void
	{
		$oCredentials = ClientCredentials::FromRequest([], ['client_id' => self::ID]);
		$this->assertSame(self::ID, $oCredentials?->sClientId);
		$this->assertNull($oCredentials->sSecret);
	}

	public function testClientSecretPost(): void
	{
		$oCredentials = ClientCredentials::FromRequest([], ['client_id' => self::ID, 'client_secret' => 's3cret']);
		$this->assertSame('s3cret', $oCredentials?->sSecret);
	}

	/** RFC 6749 §2.3.1: each half is form-encoded before the two are joined and base64-encoded. */
	public function testClientSecretBasicIsFormDecodedAfterSplitting(): void
	{
		$sHeader = 'Basic '.base64_encode(self::ID.':'.urlencode('a:b%c+d'));
		$oCredentials = ClientCredentials::FromRequest(['HTTP_AUTHORIZATION' => $sHeader], []);
		$this->assertSame(self::ID, $oCredentials?->sClientId);
		$this->assertSame('a:b%c+d', $oCredentials->sSecret);
	}

	public function testTheHeaderPhpAlreadyDecodedIsRead(): void
	{
		$oCredentials = ClientCredentials::FromRequest(['PHP_AUTH_USER' => self::ID, 'PHP_AUTH_PW' => 'pw'], []);
		$this->assertSame('pw', $oCredentials?->sSecret);
	}

	/**
	 * @dataProvider ambiguous
	 */
	public function testARequestUsingTwoMethodsOrNoneIsRefused(array $aServer, array $aPost): void
	{
		$this->assertNull(ClientCredentials::FromRequest($aServer, $aPost));
	}

	public static function ambiguous(): array
	{
		$aBasic = ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode(self::ID.':pw')];

		return [
			'nothing' => [[], []],
			'Basic and a posted secret' => [$aBasic, ['client_secret' => 'pw']],
			'Basic for one client, the form for another' => [$aBasic, ['client_id' => 'ffffffffffffffffffffffffffffffff']],
			'Bearer instead of Basic, and no client_id' => [['HTTP_AUTHORIZATION' => 'Bearer x'], []],
			'Basic without a colon' => [['HTTP_AUTHORIZATION' => 'Basic '.base64_encode(self::ID)], []],
			'Basic with an empty id' => [['HTTP_AUTHORIZATION' => 'Basic '.base64_encode(':pw')], []],
		];
	}

	public function testBasicWithTheSameClientIdPostedIsAccepted(): void
	{
		$aServer = ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode(self::ID.':pw')];
		$this->assertSame('pw', ClientCredentials::FromRequest($aServer, ['client_id' => self::ID])?->sSecret);
	}
}
