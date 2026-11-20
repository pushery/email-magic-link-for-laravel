<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use Composer\InstalledVersions;

/**
 * Detects whether WireKit (pushery/wirekit) is installed, at a release the WireKit screens are proven against.
 *
 * The class name is held as a string and probed via class_exists, so the package never imports a WireKit symbol and
 * stays loadable when WireKit is absent. Tests override the property to simulate WireKit being present.
 *
 * A WireKit below {@see MINIMUM} counts as absent, so the plain screens render: the WireKit screens pass props an older
 * release does not have, and a Blade tag compiles against whatever the installed kit offers.
 */
final class WireKit
{
    /**
     * The oldest `pushery/wirekit` the WireKit screens are proven against: the lower bound of the development
     * constraint, which the lane with the lowest dependencies installs. It moves with that constraint, and a test holds
     * the two together, and the release the `suggest` entry names with them.
     */
    public const string MINIMUM = '2.73.0';

    public static string $providerClass = 'Pushery\\WireKit\\WireKitServiceProvider';

    public static function installed(): bool
    {
        return class_exists(self::$providerClass) && self::satisfies(self::installedVersion());
    }

    /**
     * Whether a version string clears the floor. A named branch, reported as `dev-…`, and no answer at all count as
     * satisfied: somebody developing against a branch has chosen it. A branch named like a version, reported as
     * `2.x-dev` or `2.72.x-dev`, sits above every release of its line, so its `x` compares as the highest number there
     * is: `2.x-dev` clears the floor and `2.72.x-dev` does not.
     */
    public static function satisfies(?string $version): bool
    {
        if (! is_string($version) || str_starts_with($version, 'dev-')) {
            return true;
        }

        $release = (string) preg_replace('/\.x-dev$/D', '.'.PHP_INT_MAX, ltrim($version, 'vV'));

        return version_compare($release, self::MINIMUM, '>=');
    }

    /** What Composer says is installed, or null where it cannot say, as where something else put WireKit on the autoloader. */
    private static function installedVersion(): ?string
    {
        return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('pushery/wirekit')
            ? InstalledVersions::getPrettyVersion('pushery/wirekit')
            : null;
    }
}
