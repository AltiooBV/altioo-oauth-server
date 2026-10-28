<?php
/**
 * Test bootstrap. The unit suite needs only this module's autoloader; the integration suite,
 * which needs a live iTop, boots it in its own base class and skips itself without one.
 *
 * @copyright   Copyright (C) 2026 Altioo
 * @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/vendor/autoload.php';
