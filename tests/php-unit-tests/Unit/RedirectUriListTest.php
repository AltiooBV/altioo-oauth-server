<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Test\Unit;

use Altioo\iTop\Extension\OAuthServer\RedirectUriList;
use PHPUnit\Framework\TestCase;

final class RedirectUriListTest extends TestCase
{
	public function testAsRegisteredByTheClient(): void
	{
		$this->assertSame(['https://a.example/cb', 'https://b.example/cb'], RedirectUriList::Parse('["https://a.example/cb","https://b.example/cb"]'));
	}

	public function testAsTypedByAnAdministrator(): void
	{
		$this->assertSame(['https://a.example/cb', 'https://b.example/cb'], RedirectUriList::Parse(" https://a.example/cb\r\n\r\nhttps://b.example/cb \n"));
	}

	public function testNothing(): void
	{
		$this->assertSame([], RedirectUriList::Parse(''));
	}
}
