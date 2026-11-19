<?php

declare(strict_types=1);

namespace Valolink\Woo;

/**
 * For the rare module that must hook in before init, where modules normally boot. Called on
 * plugins_loaded for enabled modules only, so it stays zero-footprint when the module is off.
 * Polylang, for one, caches its list of translatable post types once the theme is set up.
 */
interface EarlyHooks
{
    public static function early(Settings $settings): void;
}
