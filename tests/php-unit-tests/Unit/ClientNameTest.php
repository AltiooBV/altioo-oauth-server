<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer\Test\Unit;

use Altioo\iTop\Extension\OAuthServer\ClientName;
use PHPUnit\Framework\TestCase;

final class ClientNameTest extends TestCase
{
	public function testAnOrdinaryNameIsKept(): void
	{
		$this->assertSame('Claude', ClientName::Clean('Claude'));
		$this->assertSame('ACME Ticket Sync 2.0', ClientName::Clean('ACME Ticket Sync 2.0'));
		// Ordinary punctuation and an em dash are left alone; only reordering and hiding go.
		$this->assertSame('Éditeur — outil', ClientName::Clean('Éditeur — outil'));
	}

	public function testAccentedTextSurvives(): void
	{
		$this->assertSame('Café支持', ClientName::Clean('Café支持'));
	}

	/**
	 * The right-to-left override is the one that makes the consent sentence lie: without it the
	 * name here reads "Claude ..gnissecca", with it the tail is displayed reversed.
	 */
	public function testBidiControlsAreRemoved(): void
	{
		$sClean = ClientName::Clean("Claude \u{202E}gnissecca");
		$this->assertStringNotContainsString("\u{202E}", $sClean);
		$this->assertSame('Claude gnissecca', $sClean);
		// Isolates and marks too (U+2066..U+2069, U+200E/F, U+061C).
		$this->assertSame('ab', ClientName::Clean("a\u{2066}\u{2069}\u{200E}\u{200F}\u{061C}b"));
	}

	public function testZeroWidthCharactersAreRemoved(): void
	{
		// A zero-width space hides where one word ends and the next begins.
		$this->assertSame('admintool', ClientName::Clean("admin\u{200B}tool"));
		$this->assertSame('x', ClientName::Clean("\u{FEFF}x\u{2060}"));
	}

	public function testControlCharactersBecomeSpaceAndCollapse(): void
	{
		// C0, DEL and C1 are not shown; they must not survive as a way to pad or split a name.
		$this->assertSame('a b', ClientName::Clean("a\t\n\x7f\u{0085}b"));
		$this->assertSame('a b', ClientName::Clean("a   b"));
	}

	public function testItTrims(): void
	{
		$this->assertSame('Claude', ClientName::Clean("  Claude \u{200B} "));
	}

	public function testAnEmptyOrWhollyStrippedNameBecomesEmpty(): void
	{
		// The caller substitutes "Unnamed application" for an empty result.
		$this->assertSame('', ClientName::Clean(''));
		$this->assertSame('', ClientName::Clean("\u{202E}\u{200B}\x00"));
	}

	public function testInvalidUtf8DoesNotCrash(): void
	{
		// preg_replace with /u returns null on malformed input; Clean must still return a string.
		$this->assertSame('', ClientName::Clean("\xff\xfe"));
	}
}
