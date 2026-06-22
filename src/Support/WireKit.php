<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

/**
 * Detects whether WireKit (pushery/wirekit) is installed.
 *
 * The class name is held as a string and probed via class_exists, so the package
 * never imports a WireKit symbol and stays loadable when WireKit is absent. Tests
 * override the property to simulate WireKit being present.
 */
final class WireKit
{
    public static string $providerClass = 'Pushery\\WireKit\\WireKitServiceProvider';

    public static function installed(): bool
    {
        return class_exists(self::$providerClass);
    }
}
