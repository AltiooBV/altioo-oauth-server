<?php
/**
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Altioo\iTop\Extension\OAuthServer;

/**
 * What one resource lets a person grant. Every scope is a PersonalToken scope, so what a token
 * can do is decided by the resource that checks it, never by this server.
 *
 * @since 0.1.0
 */
final class ScopePolicy
{
	/**
	 * @param list<string> $aFamily every PersonalToken scope this resource understands
	 * @param list<string> $aOffered the subset the administrator lets people grant; empty means all
	 * @param list<string> $aDefault pre-selected on the consent page
	 * @param list<string> $aForced always added, whatever the person ticks
	 * @param list<string>|null $aWithin when set, nothing outside it is offered, even if that
	 *     leaves nothing; null leaves the offer as it is
	 */
	public function __construct(
		private readonly array $aFamily,
		private readonly array $aOffered = [],
		private readonly array $aDefault = [],
		private readonly array $aForced = [],
		private readonly ?array $aWithin = null
	)
	{
	}

	/**
	 * The same policy, offering only what is also in $aScopes. An empty offer stays empty here,
	 * where an empty $aOffered would mean the whole family.
	 *
	 * @param list<string> $aScopes
	 */
	public function Within(array $aScopes): self
	{
		return new self($this->aFamily, $this->aOffered, $this->aDefault, $this->aForced,
			$this->aWithin === null ? $aScopes : array_values(array_intersect($this->aWithin, $aScopes)));
	}

	/**
	 * What the consent page offers for a request. A client may ask for less than the resource
	 * offers, never for more; a client asking for nothing it may have gets the full offer.
	 *
	 * @param list<string> $aRequested
	 * @return list<string>
	 */
	public function Offered(array $aRequested): array
	{
		$aOffered = $this->aOffered === [] ? $this->aFamily : array_values(array_intersect($this->aFamily, $this->aOffered));
		if ($this->aWithin !== null) {
			$aOffered = array_values(array_intersect($aOffered, $this->aWithin));
		}
		$aOffered = array_values(array_diff($aOffered, $this->aForced));
		$aNarrowed = array_values(array_intersect($aOffered, $aRequested));

		return $aNarrowed === [] ? $aOffered : $aNarrowed;
	}

	/**
	 * @param list<string> $aOffered
	 * @return list<string>
	 */
	public function Preselected(array $aOffered): array
	{
		$aDefault = array_values(array_intersect($aOffered, $this->aDefault));

		return $aDefault === [] && $aOffered !== [] ? [$aOffered[0]] : $aDefault;
	}

	/**
	 * What is granted from what the consent form posted. Anything the page did not offer is
	 * dropped, whatever the browser sent.
	 *
	 * @param list<string> $aOffered
	 * @param list<string> $aTicked
	 * @return list<string> empty when nothing offered was ticked
	 */
	public function Granted(array $aOffered, array $aTicked): array
	{
		$aGranted = array_values(array_intersect($aOffered, $aTicked));
		if ($aGranted === []) {
			return [];
		}

		return array_values(array_unique(array_merge($aGranted, array_intersect($this->aFamily, $this->aForced))));
	}

	/**
	 * What a grant made earlier may still carry under today's policy: what it was granted that
	 * is still offered, plus what is forced now. A limit left on its own is no grant, as at
	 * consent: a resource reads a token holding only scopes that narrow as one that names no
	 * grade, which may be more than the grade the person chose.
	 *
	 * @param list<string> $aGranted
	 * @param list<string> $aLimitScopes the scopes of the resource's limits
	 * @return list<string> empty when nothing it was granted, limits aside, is still offered
	 */
	public function Current(array $aGranted, array $aLimitScopes = []): array
	{
		$aOffered = $this->Offered([]);
		if ($this->Granted($aOffered, array_values(array_diff($aGranted, $aLimitScopes))) === []) {
			return [];
		}

		return $this->Granted($aOffered, $aGranted);
	}

	/**
	 * @return list<string>
	 */
	public function Forced(): array
	{
		return array_values(array_intersect($this->aFamily, $this->aForced));
	}
}
