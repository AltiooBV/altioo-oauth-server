<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer;

/**
 * A resource's grades: named sets of scopes the consent page offers as one radio button each,
 * so that a person picks "read only" rather than ticking MCP-read.
 *
 * @since 0.1.0
 */
final class Grades
{
	/**
	 * The grades whose every scope the page may offer, keyed by their position in the setting,
	 * which is what the form posts back.
	 *
	 * @param list<array{label: string, scopes: list<string>}> $aGrades
	 * @param list<string> $aOffered
	 * @return array<int, array{label: string, scopes: list<string>}>
	 */
	public static function Usable(array $aGrades, array $aOffered): array
	{
		return array_filter($aGrades, static fn(array $aGrade) => array_diff($aGrade['scopes'], $aOffered) === []);
	}

	/**
	 * The first grade whose scopes are all preselected, or else the first grade.
	 *
	 * @param array<int, array{label: string, scopes: list<string>}> $aUsable not empty
	 * @param list<string> $aPreselected
	 */
	public static function Chosen(array $aUsable, array $aPreselected): int
	{
		foreach ($aUsable as $iIndex => $aGrade) {
			if (array_diff($aGrade['scopes'], $aPreselected) === []) {
				return $iIndex;
			}
		}

		return (int) array_key_first($aUsable);
	}

	/**
	 * The offered scopes no usable grade covers, which the page offers as checkboxes.
	 *
	 * @param array<int, array{label: string, scopes: list<string>}> $aUsable
	 * @param list<string> $aOffered
	 * @return list<string>
	 */
	public static function Uncovered(array $aUsable, array $aOffered): array
	{
		$aCovered = [];
		foreach ($aUsable as $aGrade) {
			$aCovered = array_merge($aCovered, $aGrade['scopes']);
		}

		return array_values(array_diff($aOffered, $aCovered));
	}
}
