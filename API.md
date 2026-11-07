# Vite

ProcessWire Vite.js asset bundling, Hot Module Replacement (HMR), Subresource Integrity (SRI), and manifest resolution module (`ProcessWire\Vite` & `Totoglu\Vite\Vite`).

## Value type

- **Module Class**: `ProcessWire\Vite` (`Vite.module.php`) — `ConfigurableModule` container registered at `$this->wire()->vite`.
- **Service Class**: `Totoglu\Vite\Vite` (`src/Vite.php`) — `Wire`-derived runtime service managing HMR detection, manifest parsing, chunk traversal, and HTML tag generation.
- **Global Helper**: `vite(array|string|null $entries = null): \Totoglu\Vite\Vite` (`functions.php`) — available in both `namespace ProcessWire` and the global `namespace`.

## Getting and setting values

Configure defaults through the module admin screen (**Modules → Site → Vite**), via `setting('vite', [...])` in `site/init.php` / `site/ready.php`, or per-call using the fluent `use*()` methods on `Totoglu\Vite\Vite`:

```php
setting('vite', [
	'rootPath' => wire()->config->paths->templates,
	'rootUrl' => wire()->config->urls->templates,
	'buildDirectory' => 'build',
	'hotFile' => 'hot',
	'manifest' => 'manifest.json',
	'integrity' => 'integrity', // or false to disable SRI
	'nonce' => null,
]);
```

| Method | Signature | Description |
|---|---|---|
| `instance` | `public static function instance(): static` | Returns the shared singleton instance. |
| `copy` | `public static function copy(): static` | Returns a cloned instance for isolated per-call configuration. |
| `flush` | `public static function flush(bool $clearManifests = false): void` | Resets the singleton instance and optionally clears the static manifest cache. |
| `normalizeConfigValue` | `public static function normalizeConfigValue(string $key, mixed $value, mixed $fallback = null): mixed` | Normalizes a single configuration option (`rootPath`, `rootUrl`, `buildDirectory`, `hotFile`, `integrity`, `manifest`, `nonce`). |
| `withEntries` | `public function withEntries(array $entries): static` | Sets the entry point paths to render. |
| `withReactRefresh` | `public function withReactRefresh(bool $enabled = true): static` | Prepends the React Fast Refresh preamble script when HMR is active. |
| `useNonce` | `public function useNonce(?string $nonce = null): static` | Sets a custom CSP nonce or generates a 40-character cryptographic hex nonce (`bin2hex(random_bytes(20))`) when `$nonce` is `null`. |
| `useManifest` | `public function useManifest(string $name): static` | Overrides the manifest filename (default: `'manifest.json'`). Checked at `{buildDirectory}/{manifest}` and `{buildDirectory}/.vite/{manifest}`. |
| `useIntegrity` | `public function useIntegrity(string\|false $key): static` | Sets the manifest chunk key used for Subresource Integrity (`'integrity'`) or `false` to disable SRI. |
| `useHotFile` | `public function useHotFile(string $path): static` | Sets the HMR hot file path (relative to `rootPath` or an absolute filesystem path). Pass `''` to disable HMR detection. |
| `useBuildDirectory` | `public function useBuildDirectory(string $path): static` | Sets the production build directory relative to `rootPath` / `rootUrl` (default: `'build'`). |
| `useScriptTagAttributes` | `public function useScriptTagAttributes(array\|callable $attributes): static` | Registers static attributes or a `fn(string $src, string $url, array $chunk, array $manifest): array` resolver for `<script>` tags. |
| `useStyleTagAttributes` | `public function useStyleTagAttributes(array\|callable $attributes): static` | Registers static attributes or a resolver for `<link rel="stylesheet">` tags. |
| `usePreloadTagAttributes` | `public function usePreloadTagAttributes(array\|callable $attributes): static` | Registers static attributes or a resolver for `<link rel="preload">` / `<link rel="modulepreload">` tags. |

## Selectors

This module does not define a database `Fieldtype` and does not participate in `$pages->find()` selectors. Entry points are referenced by their source path relative to `rootPath` (e.g., `'assets/css/app.css'`, `'assets/js/app.js'`). Prefix an entry path with `@` (e.g., `'@assets/js/optional.js'`) to skip it silently if the source file does not exist on disk.

## Output / markup

```php
// Render preload, stylesheet, and module script tags for entry points:
echo vite(['assets/css/app.css', 'assets/js/app.js']);

// Resolve a single versioned or HMR asset URL:
echo vite()->asset('assets/images/logo.png');
```

| Method | Signature | Description |
|---|---|---|
| `__invoke` | `public function __invoke(array\|string $entries, ?string $buildDirectory = null): string` | Renders HTML preload, `<link rel="stylesheet">`, and `<script type="module">` tags for the given entry points. |
| `__toString` | `public function __toString(): string` | Invokes `__invoke($this->entries)`, resets `$this->entries`, and logs any exception to `$this->wire()->log`. |
| `asset` | `public function asset(string $asset, ?string $buildDirectory = null): string` | Resolves a single asset path to its HMR dev-server URL or versioned production URL from `manifest.json`. |
| `reactRefresh` | `public function reactRefresh(): string` | Returns the `<script type="module">` React Fast Refresh runtime preamble when `isRunningHot()` is `true`, or `''` in production. |
| `isRunningHot` | `public function isRunningHot(): bool` | Returns `true` when `hotFile()` resolves to an existing file on disk. |
| `hotFile` | `public function hotFile(): string` | Returns the resolved filesystem path to the HMR `hot` file, or `''` if disabled. |
| `hotAsset` | `public function hotAsset(string $asset): string` | Returns the HMR dev-server URL for `$asset`. Throws `WireException` if the hot file is missing or empty. |
| `nonce` | `public function nonce(): ?string` | Returns the active CSP nonce or `null`. |
| `preloadedAssets` | `public function preloadedAssets(): array` | Returns `[url => '<link ...>']` map of preload tags generated by the instance. |
| `url` | `public function url(?string $path = null): string` | Builds a URL relative to `rootUrl` with normalized `/` separators, preserving absolute `http://` and `https://` URLs. |

## Notes

- Configuration precedence (lowest to highest): `ProcessWire\Vite` module config → `setting('vite', [...])` → fluent `use*()` overrides on the `Totoglu\Vite\Vite` instance.
- Transitive `imports` and `css` chunks in `manifest.json` are traversed recursively with cycle protection (`$visitedImports`) and deduplicated per invocation.
