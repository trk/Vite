<?php

declare(strict_types=1);

namespace Totoglu\Vite;

use Stringable;
use ProcessWire\Wire;
use ProcessWire\WireException;

use function ProcessWire\setting;

/**
 * A Vite adapter for ProcessWire
 *
 * @package   Totoglu Vite
 * @author    Iskender TOTOGLU <iskender@totoglu.com>
 * @link      https://totoglu.com
 * @copyright 2026 Iskender TOTOGLU
 * @license   https://opensource.org/licenses/MIT
 */
class Vite extends Wire implements Stringable
{
	/**
	 * The Vite Module instance.
	 */
	protected ?\ProcessWire\Vite $module = null;

	/**
	 * The shared Vite instance.
	 */
	protected static ?Vite $instance = null;

	protected string $rootPath;

	protected string $rootUrl;

	/**
	 * The Content Security Policy nonce to apply to all generated tags.
	 */
	protected ?string $nonce = null;

	/**
	 * The key to check for integrity hashes within the manifest.
	 */
	protected string|false $integrity = 'integrity';

	/**
	 * The configured entry points.
	 *
	 * @var array<int, string>
	 */
	protected array $entries = [];

	/**
	 * Whether to prepend the React Fast Refresh runtime script before entries.
	 */
	protected bool $includeReactRefresh = false;

	/**
	 * The path to the "hot" file.
	 */
	protected ?string $hotFile = 'hot';

	/**
	 * The path to the build directory.
	 */
	protected string $buildDirectory = 'build';

	/**
	 * The name of the manifest file.
	 */
	protected string $manifest = 'manifest.json';

	/**
	 * Properties explicitly overridden via fluent use*() methods on this instance.
	 *
	 * @var array<string, bool>
	 */
	protected array $overriddenKeys = [];

	/**
	 * The preloaded assets registered on this instance.
	 *
	 * @var array<string, string>
	 */
	protected array $preloadedAssets = [];

	/**
	 * The cached decoded manifest files keyed by absolute path.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	protected static array $manifests = [];

	/**
	 * @var array<int, callable>
	 */
	protected array $scriptTagAttributesResolvers = [];

	/**
	 * @var array<int, callable>
	 */
	protected array $styleTagAttributesResolvers = [];

	/**
	 * @var array<int, callable>
	 */
	protected array $preloadTagAttributesResolvers = [];

	/**
	 * Create a new Vite instance.
	 */
	public function __construct(?\ProcessWire\Vite $module = null)
	{
		parent::__construct();

		$wiredModule = $module ?? $this->wire()->vite;
		$this->module = $wiredModule instanceof \ProcessWire\Vite ? $wiredModule : null;

		$config = $this->wire()->config;
		$this->rootPath = is_object($config) && isset($config->paths->templates)
			? (string) $config->paths->templates
			: '';
		$this->rootUrl = is_object($config) && isset($config->urls->templates)
			? (string) $config->urls->templates
			: '/';

		$this->applyGlobalSettings();
	}

	/**
	 * Normalize a single configuration option value by key.
	 */
	public static function normalizeConfigValue(string $key, mixed $value, mixed $fallback = null): mixed
	{
		if (is_callable($value)) {
			$value = $value();
		}

		$trimmed = is_string($value) ? trim($value) : '';

		return match ($key) {
			'integrity' => $value === false ? false : ($trimmed !== '' ? $trimmed : $fallback),
			'hotFile', 'nonce' => $trimmed !== '' ? $trimmed : null,
			default => $trimmed !== '' ? $trimmed : $fallback,
		};
	}

	/**
	 * Normalize and apply an associative map of configuration options onto this instance.
	 *
	 * @param array<string, mixed> $options
	 */
	protected function applyConfigMap(array $options): void
	{
		foreach (['rootPath', 'rootUrl', 'buildDirectory', 'hotFile', 'integrity', 'manifest', 'nonce'] as $settingKey) {
			if (array_key_exists($settingKey, $options) && !isset($this->overriddenKeys[$settingKey])) {
				$this->{$settingKey} = self::normalizeConfigValue($settingKey, $options[$settingKey], $this->{$settingKey});
			}
		}
	}

	/**
	 * Synchronize module configuration and apply overrides declared via setting('vite', [...]).
	 * Each option accepts a scalar value or a callable that is evaluated lazily.
	 */
	protected function applyGlobalSettings(): void
	{
		if ($this->module !== null) {
			$this->applyConfigMap($this->module->getArray());
		}

		$global = setting('vite');

		if ($global === null) {
			return;
		}

		if (is_callable($global)) {
			$global = $global();
		}

		if (!is_array($global)) {
			return;
		}

		$this->applyConfigMap($global);
	}

	/**
	 * Wrap a static attribute map or callable into a normalized attribute resolver closure.
	 *
	 * @param array<string, mixed>|callable $attributes
	 */
	protected function normalizeAttributeResolver(array|callable $attributes): callable
	{
		if (is_callable($attributes)) {
			return $attributes;
		}

		$staticAttributes = $attributes;

		return static fn(string $src, string $url, array $chunk = [], array $manifest = []): array => $staticAttributes;
	}

	/**
	 * Resolve a global setting into a list of executable attribute resolver callables.
	 *
	 * @return array<int, callable>
	 */
	protected function resolveUserSettings(string $name): array
	{
		$resolvers = [];
		$globalSetting = setting($name);

		if ($globalSetting === null || $globalSetting === false || $globalSetting === []) {
			return $resolvers;
		}

		if (is_callable($globalSetting)) {
			$resolvers[] = $globalSetting;
			return $resolvers;
		}

		if (is_array($globalSetting)) {
			$staticAttributes = [];
			foreach ($globalSetting as $key => $value) {
				if (is_int($key) && is_callable($value)) {
					$resolvers[] = $value;
				} elseif (is_string($key)) {
					$staticAttributes[$key] = $value;
				}
			}

			if ($staticAttributes !== []) {
				$resolvers[] = $this->normalizeAttributeResolver($staticAttributes);
			}
		}

		return $resolvers;
	}

	/**
	 * Generate Vite tags for one or more entrypoints.
	 *
	 * @param string|array<int, string> $entries
	 * @throws WireException
	 */
	public function __invoke(array|string $entries, ?string $buildDirectory = null): string
	{
		$this->applyGlobalSettings();

		$entries = array_values((array) $entries);
		$exists = [];
		$prefix = $this->includeReactRefresh ? $this->reactRefresh() : '';
		$this->includeReactRefresh = false;

		foreach ($entries as $key => $value) {
			if (str_starts_with($value, '@')) {
				$value = substr($value, 1);
				if ($this->exists($value)) {
					$entries[$key] = $value;
					$exists[] = $value;
				} else {
					unset($entries[$key]);
				}
			}
		}

		if ($entries === []) {
			return $prefix;
		}

		if ($this->isRunningHot()) {
			array_unshift($entries, '@vite/client');

			return $prefix . implode('', array_map(
				fn(string $value): string => $this->makeTag($value, $this->hotAsset($value)),
				$entries
			));
		}

		$manifest = $this->manifest($buildDirectory ??= $this->buildDirectory);

		$preloads = [];
		$assets = [];
		$visitedImports = [];

		foreach ($entries as $key) {
			$chunk = null;

			try {
				$chunk = $this->chunk($manifest, $key);
			} catch (WireException $e) {
				if (!in_array($key, $exists, true)) {
					throw $e;
				}
			}

			if ($chunk === null) {
				continue;
			}

			$file = $chunk['file'];
			$url = $this->url($buildDirectory . '/' . $file);
			$args = [
				$key,
				$url,
				$chunk,
				$manifest,
			];

			if (!isset($preloads[$file])) {
				$preloads[$file] = $this->makePreloadTag(...$args);
			}

			if (!isset($assets[$file])) {
				$assets[$file] = $this->makeTag(...$args);
			}

			$this->resolveImports($chunk, $buildDirectory, $manifest, $assets, $preloads, $visitedImports);
			$this->resolveCss($chunk, $buildDirectory, $manifest, $assets, $preloads);
		}

		$sortByStyle = fn(string $a, string $b): int => $this->isStylePath($b) <=> $this->isStylePath($a);
		uksort($preloads, $sortByStyle);
		uksort($assets, $sortByStyle);

		return $prefix . implode('', $preloads) . implode('', $assets);
	}

	/**
	 * Create or return the shared Vite singleton instance.
	 */
	public static function instance(): static
	{
		if (static::$instance === null || !static::$instance instanceof static) {
			static::$instance = new static();
		}

		return static::$instance;
	}

	/**
	 * Reset the shared singleton and optionally clear cached manifests.
	 */
	public static function flush(bool $clearManifests = false): void
	{
		static::$instance = null;

		if ($clearManifests) {
			static::$manifests = [];
		}
	}

	/**
	 * Return a cloned copy of the current Vite instance.
	 */
	public static function copy(): static
	{
		return clone static::instance();
	}

	/**
	 * Get the chunk for the given entry point / asset.
	 *
	 * @param array<string, mixed> $manifest
	 * @return array<string, mixed>
	 * @throws WireException
	 */
	protected function chunk(array $manifest, string $file): array
	{
		if (!isset($manifest[$file])) {
			throw new WireException("Unable to locate file in Vite manifest: {$file}");
		}

		$chunk = $manifest[$file];

		if (!is_array($chunk) || !isset($chunk['file']) || !is_string($chunk['file'])) {
			throw new WireException(sprintf(
				'Malformed chunk in Vite manifest for entry "%s": missing or invalid "file" key.',
				$file
			));
		}

		return $chunk;
	}

	/**
	 * Check if a source file exists under rootPath.
	 */
	protected function exists(string $file): bool
	{
		return is_file($this->path($file));
	}

	/**
	 * Get the decoded manifest array for the given build directory.
	 *
	 * @return array<string, mixed>
	 * @throws WireException
	 */
	protected function manifest(string $buildDirectory): array
	{
		$path = $this->manifestPath($buildDirectory);

		if (!isset(static::$manifests[$path])) {
			if (!is_file($path)) {
				throw new WireException("Vite manifest not found at: {$path}");
			}

			$contents = file_get_contents($path);
			if ($contents === false) {
				throw new WireException("Vite manifest is not readable at: {$path}");
			}

			$manifest = json_decode($contents, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				throw new WireException(sprintf(
					'Vite manifest at %s contains invalid JSON: %s',
					$path,
					json_last_error_msg()
				));
			}

			if (!is_array($manifest)) {
				throw new WireException("Vite manifest at {$path} did not decode to an array.");
			}

			static::$manifests[$path] = $manifest;
		}

		return static::$manifests[$path];
	}

	/**
	 * Get the path to the manifest file for the given build directory.
	 */
	protected function manifestPath(string $buildDirectory): string
	{
		$path = $this->path($buildDirectory . '/' . $this->manifest);

		if (!is_file($path)) {
			$fallbackPath = $this->path($buildDirectory . '/.vite/' . $this->manifest);
			if (is_file($fallbackPath)) {
				return $fallbackPath;
			}
		}

		return $path;
	}

	/**
	 * Make a script or stylesheet tag for the given chunk.
	 *
	 * @param array<string, mixed> $chunk
	 * @param array<string, mixed> $manifest
	 */
	protected function makeTag(string $src, string $url, array $chunk = [], array $manifest = []): string
	{
		if ($this->isStylePath($url)) {
			return $this->makeStyleTag(
				$url,
				$this->resolveTagAttributes('styleTagAttributes', $src, $url, $chunk, $manifest)
			);
		}

		return $this->makeScriptTag(
			$url,
			$this->resolveTagAttributes('scriptTagAttributes', $src, $url, $chunk, $manifest)
		);
	}

	/**
	 * Generate a script tag with attributes for the given URL.
	 *
	 * @param array<string, mixed> $attributes
	 */
	protected function makeScriptTag(string $url, array $attributes = []): string
	{
		$attributes = array_merge([
			'type' => 'module',
			'src' => $url,
			'nonce' => $this->nonce(),
		], $attributes);

		return "<script {$this->getAttributes($attributes)}></script>";
	}

	/**
	 * Generate a stylesheet link tag with attributes for the given URL.
	 *
	 * @param array<string, mixed> $attributes
	 */
	protected function makeStyleTag(string $url, array $attributes = []): string
	{
		$attributes = array_merge([
			'rel' => 'stylesheet',
			'href' => $url,
			'nonce' => $this->nonce(),
		], $attributes);

		return "<link {$this->getAttributes($attributes)}>";
	}

	/**
	 * Make a preload tag for the given chunk and record it in preloadedAssets.
	 *
	 * @param array<string, mixed> $chunk
	 * @param array<string, mixed> $manifest
	 */
	protected function makePreloadTag(string $src, string $url, array $chunk = [], array $manifest = []): string
	{
		$attributes = $this->resolvePreloadTagAttributes($src, $url, $chunk, $manifest);
		$tag = "<link {$this->getAttributes($attributes)}>";
		$this->preloadedAssets[$url] = $tag;

		return $tag;
	}

	/**
	 * Extract the SRI integrity attribute for a manifest chunk when enabled.
	 *
	 * @param array<string, mixed> $chunk
	 * @return array<string, string|false>
	 */
	protected function resolveIntegrityAttribute(array $chunk): array
	{
		if ($this->integrity === false) {
			return [];
		}

		$hash = $chunk[$this->integrity] ?? false;

		return ['integrity' => is_string($hash) && $hash !== '' ? $hash : false];
	}

	/**
	 * Resolve integrity and custom attributes for a given tag resolver group.
	 *
	 * @param array<string, mixed> $chunk
	 * @param array<string, mixed> $manifest
	 * @return array<string, mixed>
	 */
	protected function resolveTagAttributes(
		string $resolverGroup,
		string $src,
		string $url,
		array $chunk = [],
		array $manifest = []
	): array {
		return array_merge(
			$this->resolveIntegrityAttribute($chunk),
			$this->attributesResolver($resolverGroup, $src, $url, $chunk, $manifest)
		);
	}

	/**
	 * Resolve the attributes for the chunk's generated preload tag.
	 *
	 * @param array<string, mixed> $chunk
	 * @param array<string, mixed> $manifest
	 * @return array<string, mixed>
	 */
	protected function resolvePreloadTagAttributes(string $src, string $url, array $chunk = [], array $manifest = []): array
	{
		$attributes = $this->isStylePath($url) ? [
			'rel' => 'preload',
			'as' => 'style',
			'href' => $url,
			'nonce' => $this->nonce(),
			'crossorigin' => $this->resolveTagAttributes('styleTagAttributes', $src, $url, $chunk, $manifest)['crossorigin'] ?? false,
		] : [
			'rel' => 'modulepreload',
			'href' => $url,
			'nonce' => $this->nonce(),
			'crossorigin' => $this->resolveTagAttributes('scriptTagAttributes', $src, $url, $chunk, $manifest)['crossorigin'] ?? false,
		];

		return array_merge(
			$attributes,
			$this->resolveTagAttributes('preloadTagAttributes', $src, $url, $chunk, $manifest)
		);
	}

	/**
	 * Execute registered and global setting attribute resolvers for the given tag type.
	 *
	 * @param array<string, mixed> $chunk
	 * @param array<string, mixed> $manifest
	 * @return array<string, mixed>
	 */
	protected function attributesResolver(string $name, string $src, string $url, array $chunk = [], array $manifest = []): array
	{
		$attributes = [];

		$instanceResolvers = match ($name) {
			'scriptTagAttributes' => $this->scriptTagAttributesResolvers,
			'styleTagAttributes' => $this->styleTagAttributesResolvers,
			'preloadTagAttributes' => $this->preloadTagAttributesResolvers,
			default => [],
		};

		$resolvers = array_merge($this->resolveUserSettings("vite.{$name}"), $instanceResolvers);

		foreach ($resolvers as $resolver) {
			$resolved = $resolver($src, $url, $chunk, $manifest);
			if (is_array($resolved)) {
				$attributes = array_merge($attributes, $resolved);
			}
		}

		return $attributes;
	}

	/**
	 * Get the preloaded assets map.
	 *
	 * @return array<string, string>
	 */
	public function preloadedAssets(): array
	{
		return $this->preloadedAssets;
	}

	/**
	 * Get the URL for a versioned or HMR asset.
	 */
	public function asset(string $asset, ?string $buildDirectory = null): string
	{
		$this->applyGlobalSettings();

		$buildDirectory ??= $this->buildDirectory;

		if ($this->isRunningHot()) {
			return $this->hotAsset($asset);
		}

		$chunk = $this->chunk($this->manifest($buildDirectory), $asset);

		return $this->url($buildDirectory . '/' . $chunk['file']);
	}

	/**
	 * Generate React Fast Refresh runtime script when running in HMR mode.
	 */
	public function reactRefresh(): string
	{
		if (!$this->isRunningHot()) {
			return '';
		}

		return sprintf(
			<<<'HTML'
			<script %s>
				import RefreshRuntime from '%s'
				RefreshRuntime.injectIntoGlobalHook(window)
				window.$RefreshReg$ = () => {}
				window.$RefreshSig$ = () => (type) => type
				window.__vite_plugin_react_preamble_installed__ = true
			</script>
			HTML,
			$this->getAttributes(['type' => 'module', 'nonce' => $this->nonce()]),
			$this->hotAsset('@react-refresh')
		);
	}

	/**
	 * Enable or disable prepending the React Fast Refresh runtime script in fluent chains.
	 */
	public function withReactRefresh(bool $enabled = true): static
	{
		$this->includeReactRefresh = $enabled;

		return $this;
	}

	/**
	 * Determine whether the given path is a stylesheet file.
	 */
	protected function isStylePath(string $path): bool
	{
		return preg_match('/\.(css|less|sass|scss|styl|stylus|pcss|postcss)$/', $path) === 1;
	}

	/**
	 * Get the resolved Vite "hot" file path.
	 *
	 * Returns an empty string when no hot file path is configured. Supports both
	 * paths relative to rootPath and absolute filesystem paths.
	 */
	public function hotFile(): string
	{
		$this->applyGlobalSettings();

		if (!is_string($this->hotFile) || trim($this->hotFile) === '') {
			return '';
		}

		$hotFile = trim($this->hotFile);

		if (str_starts_with($hotFile, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $hotFile) === 1) {
			return $hotFile;
		}

		return $this->path($hotFile);
	}

	/**
	 * Determine if the HMR dev server is running.
	 */
	public function isRunningHot(): bool
	{
		$path = $this->hotFile();

		return $path !== '' && is_file($path);
	}

	/**
	 * Get the dev-server URL to a given asset when running in HMR mode.
	 *
	 * @throws WireException
	 */
	public function hotAsset(string $asset): string
	{
		$hotPath = $this->hotFile();

		if ($hotPath === '' || !is_file($hotPath) || !is_readable($hotPath)) {
			throw new WireException("Vite hot file is missing or unreadable at: {$hotPath}");
		}

		$hotUrl = file_get_contents($hotPath);
		if ($hotUrl === false || trim($hotUrl) === '') {
			throw new WireException("Vite hot file at {$hotPath} is empty or could not be read.");
		}

		return rtrim(trim($hotUrl), '/') . '/' . ltrim($asset, '/');
	}

	/**
	 * Get the Content Security Policy nonce applied to all generated tags.
	 */
	public function nonce(): ?string
	{
		$this->applyGlobalSettings();

		return $this->nonce;
	}

	/**
	 * Resolve CSS files associated with a manifest chunk.
	 *
	 * @param array<string, mixed> $chunk
	 * @param array<string, mixed> $manifest
	 * @param array<string, string> $assets
	 * @param array<string, string> $preloads
	 */
	protected function resolveCss(array $chunk, string $buildDirectory, array $manifest, array &$assets, array &$preloads): void
	{
		foreach ($chunk['css'] ?? [] as $cssFile) {
			if (!is_string($cssFile)) {
				continue;
			}

			$matchingChunks = array_filter(
				$manifest,
				static fn(mixed $value): bool => is_array($value) && isset($value['file']) && is_string($value['file']) && $value['file'] === $cssFile
			);

			if ($matchingChunks === []) {
				$matchingChunks[$cssFile] = ['file' => $cssFile];
			}

			$cssKey = (string) array_key_first($matchingChunks);
			/** @var array<string, mixed> $cssChunk */
			$cssChunk = current($matchingChunks);
			$file = (string) $cssChunk['file'];

			$args = [
				$cssKey,
				$this->url($buildDirectory . '/' . $file),
				$cssChunk,
				$manifest,
			];

			if (!isset($assets[$file])) {
				$assets[$file] = $this->makeTag(...$args);
			}

			if (!isset($preloads[$file])) {
				$preloads[$file] = $this->makePreloadTag(...$args);
			}
		}
	}

	/**
	 * Recursively resolve imported chunks and their CSS files.
	 *
	 * @param array<string, mixed> $chunk
	 * @param array<string, mixed> $manifest
	 * @param array<string, string> $assets
	 * @param array<string, string> $preloads
	 * @param array<string, bool> $visited
	 */
	protected function resolveImports(
		array $chunk,
		string $buildDirectory,
		array $manifest,
		array &$assets,
		array &$preloads,
		array &$visited = []
	): void {
		foreach ($chunk['imports'] ?? [] as $importKey) {
			if (!is_string($importKey) || isset($visited[$importKey])) {
				continue;
			}

			$visited[$importKey] = true;
			$importedChunk = $this->chunk($manifest, $importKey);
			$file = (string) $importedChunk['file'];

			if (!isset($preloads[$file])) {
				$preloads[$file] = $this->makePreloadTag(
					$importKey,
					$this->url($buildDirectory . '/' . $file),
					$importedChunk,
					$manifest
				);
			}

			$this->resolveImports($importedChunk, $buildDirectory, $manifest, $assets, $preloads, $visited);
			$this->resolveCss($importedChunk, $buildDirectory, $manifest, $assets, $preloads);
		}
	}

	/**
	 * Assign an explicit fluent configuration override on this instance.
	 */
	protected function setOverride(string $key, mixed $value): static
	{
		$this->{$key} = $value;
		$this->overriddenKeys[$key] = true;

		return $this;
	}

	/**
	 * Generate or set a Content Security Policy nonce to apply to all generated tags.
	 */
	public function useNonce(?string $nonce = null): static
	{
		return $this->setOverride('nonce', $nonce ?? bin2hex(random_bytes(20)));
	}

	/**
	 * Set the filename for the manifest file.
	 */
	public function useManifest(string $name): static
	{
		return $this->setOverride('manifest', $name);
	}

	/**
	 * Use the given key to detect integrity hashes in the manifest, or false to disable.
	 */
	public function useIntegrity(string|false $key): static
	{
		return $this->setOverride('integrity', $key);
	}

	/**
	 * Set the Vite "hot" file path.
	 */
	public function useHotFile(string $path): static
	{
		return $this->setOverride('hotFile', $path);
	}

	/**
	 * Set the Vite build directory.
	 */
	public function useBuildDirectory(string $path): static
	{
		return $this->setOverride('buildDirectory', $path);
	}

	/**
	 * Set the filesystem base path used to resolve Vite assets.
	 */
	public function useRootPath(string $path): static
	{
		return $this->setOverride('rootPath', $path);
	}

	/**
	 * Set the public URL base path used to generate Vite asset URLs.
	 */
	public function useRootUrl(string $url): static
	{
		return $this->setOverride('rootUrl', $url);
	}

	/**
	 * Use the given array or callback to resolve attributes for script tags.
	 *
	 * @param array<string, mixed>|callable $attributes
	 */
	public function useScriptTagAttributes(array|callable $attributes): static
	{
		$this->scriptTagAttributesResolvers[] = $this->normalizeAttributeResolver($attributes);

		return $this;
	}

	/**
	 * Use the given array or callback to resolve attributes for style tags.
	 *
	 * @param array<string, mixed>|callable $attributes
	 */
	public function useStyleTagAttributes(array|callable $attributes): static
	{
		$this->styleTagAttributesResolvers[] = $this->normalizeAttributeResolver($attributes);

		return $this;
	}

	/**
	 * Use the given array or callback to resolve attributes for preload tags.
	 *
	 * @param array<string, mixed>|callable $attributes
	 */
	public function usePreloadTagAttributes(array|callable $attributes): static
	{
		$this->preloadTagAttributesResolvers[] = $this->normalizeAttributeResolver($attributes);

		return $this;
	}

	/**
	 * Set the Vite entry points.
	 *
	 * @param array<int, string> $entries
	 */
	public function withEntries(array $entries): static
	{
		$this->entries = $entries;

		return $this;
	}

	/**
	 * Format an associative array of HTML attributes into an escaped attribute string.
	 *
	 * @param array<string, mixed> $attributes
	 */
	protected function getAttributes(array $attributes): string
	{
		$attrs = [];
		foreach ($attributes as $key => $value) {
			if ($value === null || $value === false) {
				continue;
			}

			if ($value === true) {
				$attrs[] = htmlspecialchars((string) $key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
				continue;
			}

			if (!is_string($value)) {
				$value = (string) $value;
			}

			$attrs[] = sprintf(
				'%1$s="%2$s"',
				htmlspecialchars((string) $key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
				htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
			);
		}

		return implode(' ', $attrs);
	}

	/**
	 * Build a public URL relative to rootUrl.
	 */
	public function url(?string $path = null): string
	{
		$this->applyGlobalSettings();

		$path = str_replace('\\', '/', (string) $path);
		if (preg_match('/^https?:\/\//i', $path) === 1) {
			return $path;
		}

		$baseUrl = rtrim(str_replace('\\', '/', $this->rootUrl), '/') . '/';

		return $baseUrl . ltrim($path, '/');
	}

	/**
	 * Build a filesystem path relative to rootPath.
	 */
	protected function path(?string $file = null): string
	{
		$this->applyGlobalSettings();

		$basePath = rtrim($this->rootPath, '/\\') . '/';

		return $basePath . ltrim((string) $file, '/\\');
	}

	/**
	 * Render the configured Vite entry points as HTML tags.
	 */
	public function __toString(): string
	{
		$entries = $this->entries;
		$this->entries = [];

		try {
			return $this->__invoke($entries);
		} catch (\Throwable $e) {
			$this->wire()->log?->error('Vite __toString exception: ' . $e->getMessage());
			return '';
		}
	}
}
