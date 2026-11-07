# ProcessWire Vite Module — API Reference

- **Module Class**: `ProcessWire\Vite` (`Vite.module.php`)
- **Service Class**: `Totoglu\Vite\Vite` (`src/Vite.php`)
- **Global Helper**: `vite(array|string|null $entries = null): \Totoglu\Vite\Vite` (`functions.php`)
- **API Variable**: `$vite` (`wire('vite')` — returns `ProcessWire\Vite` module container)

---

## Global Helper (`functions.php`)

Available in both `namespace ProcessWire` and the global `namespace`:

```php
function vite(array|string|null $entries = null): \Totoglu\Vite\Vite
```

- When `$entries` is omitted (`null`), returns the shared `Totoglu\Vite\Vite::instance()` singleton for fluent configuration or helper calls (`asset()`, `reactRefresh()`, `isRunningHot()`).
- When `$entries` is passed (e.g., `echo vite(['templates/assets/css/app.css', 'templates/assets/js/app.js'])`), populates the entry list via `withEntries()` and renders all preload, stylesheet, and module script tags when cast to `string`.

---

## `Totoglu\Vite\Vite` Public Interface

### Lifecycle & Invocations

| Method | Signature | Description |
|---|---|---|
| `instance` | `public static function instance(): static` | Returns the shared singleton instance. |
| `copy` | `public static function copy(): static` | Returns a cloned instance for isolated per-call configuration. |
| `flush` | `public static function flush(bool $clearManifests = false): void` | Resets the singleton instance and optionally clears the static manifest cache. |
| `__invoke` | `public function __invoke(array\|string $entries, ?string $buildDirectory = null): string` | Renders HTML preload, `<link rel="stylesheet">`, and `<script type="module">` tags for the given entry points. Prefix an entry with `@` to skip it silently when the source file does not exist on disk. |
| `__toString` | `public function __toString(): string` | Invokes `__invoke($this->entries)` and logs any exception to `wire('log')`. |

### Fluent Configuration

| Method | Signature | Description |
|---|---|---|
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

### Asset & Runtime Inspection

| Method | Signature | Description |
|---|---|---|
| `asset` | `public function asset(string $asset, ?string $buildDirectory = null): string` | Resolves a single asset path to its HMR dev-server URL or versioned production URL from `manifest.json`. |
| `reactRefresh` | `public function reactRefresh(): string` | Returns the `<script type="module">` React Fast Refresh runtime preamble when `isRunningHot()` is `true`, or `''` in production. |
| `isRunningHot` | `public function isRunningHot(): bool` | Returns `true` when `hotFile()` resolves to an existing file on disk. |
| `hotFile` | `public function hotFile(): string` | Returns the resolved filesystem path to the HMR `hot` file, or `''` if disabled. |
| `hotAsset` | `public function hotAsset(string $asset): string` | Returns the HMR dev-server URL for `$asset`. Throws `WireException` if the hot file is missing or empty. |
| `nonce` | `public function nonce(): ?string` | Returns the active CSP nonce or `null`. |
| `preloadedAssets` | `public function preloadedAssets(): array` | Returns `[url => '<link ...>']` map of preload tags generated by the instance. |
| `url` | `public function url(?string $path = null): string` | Builds a URL relative to `rootUrl` with normalized `/` separators. |
| `isStylePath` | `public function isStylePath(string $path): bool` | Returns `true` if `$path` ends with `.css`, `.less`, `.sass`, `.scss`, `.styl`, `.stylus`, `.pcss`, or `.postcss`. |
| `getAttributes` | `public function getAttributes(array $attributes): string` | Formats an associative attribute map into an HTML-escaped attribute string (`null`/`false` omitted, `true` rendered as boolean attribute). |

---

## Global `setting()` Overrides

Configure defaults in `site/init.php` or `site/ready.php`:

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
