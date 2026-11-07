<?php

declare(strict_types=1);

namespace ProcessWire {

	if (!function_exists(__NAMESPACE__ . '\vite')) {
		/**
		 * @param array<int, string>|string|null $entries
		 */
		function vite(array|string|null $entries = null): \Totoglu\Vite\Vite
		{
			$vite = \Totoglu\Vite\Vite::instance();

			return $entries !== null
				? $vite->withEntries((array) $entries)
				: $vite;
		}
	}
}

namespace {

	if (!function_exists('vite')) {
		/**
		 * @param array<int, string>|string|null $entries
		 */
		function vite(array|string|null $entries = null): \Totoglu\Vite\Vite
		{
			return \ProcessWire\vite($entries);
		}
	}
}
