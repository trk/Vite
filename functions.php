<?php

declare(strict_types=1);

namespace ProcessWire;

if (!function_exists(__NAMESPACE__ . '\vite')) {
    function vite(array|string|null $entries = null): \Totoglu\Vite\Vite
    {
        $vite = \Totoglu\Vite\Vite::instance();
        return !is_null($entries)
            ? $vite->withEntries((array) $entries)
            : $vite;
    }
}
