<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer;

/**
 * A resource's grades: named sets of scopes the consent page offers as one radio button each,
 * so that a person picks "read only" rather than ticking MCP-read. Its limits have the same
 * shape and are checkboxes: scopes that narrow a connection rather than widen it, such as a
 * rehearsal mode, which an extra checkbox would otherwise present as something more to allow.
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
	 * The offer with every grade narrower than the widest one it allows added back. A client
	 * that asks only for the widest scopes (Claude asks for all it is told of) would otherwise
	 * leave the person nothing narrower to choose, when granting less than asked is always
	 * allowed. Grades are listed narrowest first; only scopes the administrator offers are added.
	 *
	 * @param list<array{label: string, scopes: list<string>}> $aGrades
	 * @param list<string> $aOffered the offer for this request
	 * @param list<string> $aAdminOffer what the resource offers to any request
	 * @return list<string>
	 */
	public static function WithNarrower(array $aGrades, array $aOffered, array $aAdminOffer): array
	{
		$aUsable = self::Usable($aGrades, $aOffered);
		if ($aUsable === []) {
			return $aOffered;
		}
		$iWidest = max(array_keys($aUsable));
		foreach ($aGrades as $iIndex => $aGrade) {
			if ($iIndex < $iWidest && array_diff($aGrade['scopes'], $aAdminOffer) === []) {
				$aOffered = array_merge($aOffered, $aGrade['scopes']);
			}
		}

		return array_values(array_unique($aOffered));
	}

	/**
	 * The offer with every limit the administrator offers added. A limit only ever narrows what
	 * a connection may do, so offering one the client did not ask for still grants less than
	 * asked.
	 *
	 * @param list<array{label: string, scopes: list<string>}> $aLimits
	 * @param list<string> $aOffered the offer for this request
	 * @param list<string> $aAdminOffer what the resource offers to any request
	 * @return list<string>
	 */
	public static function WithLimits(array $aLimits, array $aOffered, array $aAdminOffer): array
	{
		foreach (self::Usable($aLimits, $aAdminOffer) as $aLimit) {
			$aOffered = array_merge($aOffered, $aLimit['scopes']);
		}

		return array_values(array_unique($aOffered));
	}

	/**
	 * How a connection's scopes read to the person who made it: the widest grade they cover,
	 * the limits they carry, and the scopes neither accounts for.
	 *
	 * @param list<array{label: string, scopes: list<string>}> $aGrades
	 * @param list<array{label: string, scopes: list<string>}> $aLimits
	 * @param list<string> $aScopes
	 * @return array{labels: list<string>, rest: list<string>} labels as the settings give them
	 */
	public static function Describe(array $aGrades, array $aLimits, array $aScopes): array
	{
		$aLabels = [];
		$aCovered = [];
		$iWidest = array_key_last(self::Usable($aGrades, $aScopes));
		if ($iWidest !== null) {
			$aLabels[] = $aGrades[$iWidest]['label'];
			$aCovered = $aGrades[$iWidest]['scopes'];
		}
		foreach (self::Usable($aLimits, $aScopes) as $aLimit) {
			$aLabels[] = $aLimit['label'];
			$aCovered = array_merge($aCovered, $aLimit['scopes']);
		}

		return ['labels' => $aLabels, 'rest' => array_values(array_diff($aScopes, $aCovered))];
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
