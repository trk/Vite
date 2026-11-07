<?php

declare(strict_types=1);

namespace ProcessWire;

/**
 * A Vite adapter for ProcessWire
 *
 * @package   Totoglu Vite
 * @author    Iskender TOTOGLU <iskender@totoglu.com>
 * @link      https://totoglu.com
 * @copyright 2026 Iskender TOTOGLU
 * @license   https://opensource.org/licenses/MIT
 *
 * @property string $rootPath Filesystem base path used to resolve Vite assets (defaults to templates path).
 * @property string $rootUrl URL base path used to generate asset URLs (defaults to templates URL).
 * @property string $buildDirectory The path to the build directory, relative to rootPath/rootUrl.
 * @property string|null $hotFile The path to the "hot" file (relative to rootPath, or absolute).
 * @property string|false $integrity The manifest key used for SRI integrity hashes, or false to disable.
 * @property string $manifest The name of the manifest file inside the build directory.
 * @property string|null $nonce Content Security Policy nonce value applied to generated tags.
 *
 * @method void install()
 */
class Vite extends WireData implements Module, ConfigurableModule
{
	/**
	 * Return module metadata for ProcessWire.
	 *
	 * @return array<string, mixed>
	 */
	public static function getModuleInfo(): array
	{
		return [
			'title' => 'Vite',
			'summary' => __('Integrates Vite.js with ProcessWire for a modern frontend development workflow. This module simplifies asset bundling by automatically generating the correct script and style tags for your Vite-powered assets. It supports Hot Module Replacement (HMR) for instant feedback during development and reads Vite\'s manifest file in production for versioned/hashed assets, enabling efficient cache-busting.', __FILE__),
			'version' => 5,
			'icon' => 'code',
			'singular' => true,
			'autoload' => true,
			'requires' => [
				'ProcessWire>=3.0.165',
				'PHP>=8.3.0',
			],
		];
	}

	public function __construct()
	{
		parent::__construct();

		$this->wire()->classLoader->addNamespace('Totoglu\Vite', __DIR__ . '/src');

		require_once __DIR__ . '/functions.php';

		$this->setConfigData([]);
	}

	public function wired(): void
	{
		parent::wired();
		$this->wire('vite', $this);
	}

	/**
	 * Default module configuration values.
	 *
	 * @return array{rootPath: string, rootUrl: string, buildDirectory: string, hotFile: string|null, integrity: string|false, manifest: string, nonce: string|null}
	 */
	public function getDefaultConfig(): array
	{
		$config = $this->wire()->config;

		return [
			'rootPath' => (string) $config->paths->templates,
			'rootUrl' => (string) $config->urls->templates,
			'buildDirectory' => 'build',
			'hotFile' => 'hot',
			'integrity' => 'integrity',
			'manifest' => 'manifest.json',
			'nonce' => null,
		];
	}

	/**
	 * Normalize raw configuration array against module defaults.
	 *
	 * @param array<string, mixed> $data
	 * @return array{rootPath: string, rootUrl: string, buildDirectory: string, hotFile: string|null, integrity: string|false, manifest: string, nonce: string|null}
	 */
	public function normalizeConfig(array $data): array
	{
		$normalized = $this->getDefaultConfig();

		if (($data['integrityToggle'] ?? null) === 'disabled') {
			$data['integrity'] = false;
		}

		foreach ($normalized as $key => $default) {
			if (array_key_exists($key, $data)) {
				$normalized[$key] = \Totoglu\Vite\Vite::normalizeConfigValue($key, $data[$key], $default);
			}
		}

		return $normalized;
	}

	/**
	 * Receive and normalize configuration data from ProcessWire.
	 *
	 * @param array<string, mixed> $data
	 */
	public function setConfigData(array $data): void
	{
		foreach ($this->normalizeConfig($data) as $key => $value) {
			$this->set($key, $value);
		}
	}

	/**
	 * Copy starter stub files into the active templates directory without overwriting existing files.
	 */
	protected function copyStubFiles(): void
	{
		$config = $this->wire()->config;
		$files = $this->wire()->files;
		$stubsDir = __DIR__ . '/stubs/templates';
		$templatesDir = rtrim((string) $config->paths->templates, '/\\');

		if (!is_dir($stubsDir) || !is_readable($stubsDir)) {
			$this->error(sprintf($this->_('Stubs directory not found or unreadable: %s'), $stubsDir));
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($stubsDir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::SELF_FIRST
		);

		$prefixLength = strlen(rtrim($stubsDir, '/\\')) + 1;

		/** @var \SplFileInfo $fileInfo */
		foreach ($iterator as $fileInfo) {
			$sourcePath = $fileInfo->getPathname();
			$relPath = str_replace('\\', '/', substr($sourcePath, $prefixLength));
			$destPath = $templatesDir . '/' . ltrim($relPath, '/');

			if ($fileInfo->isDir()) {
				if (!is_dir($destPath)) {
					$this->message(sprintf($this->_('Creating directory: %s'), $relPath));
					if (!$files->mkdir($destPath, true)) {
						$this->error(sprintf($this->_('Failed to create directory: %s — check filesystem permissions.'), $destPath));
					}
				}
				continue;
			}

			if (file_exists($destPath)) {
				$this->message(sprintf($this->_('File already exists (skipping): %s'), $relPath));
				continue;
			}

			$parentDir = dirname($destPath);
			if (!is_dir($parentDir) && !$files->mkdir($parentDir, true)) {
				$this->error(sprintf($this->_('Failed to create parent directory: %s'), $parentDir));
				continue;
			}

			$this->message(sprintf($this->_('Copying file: %s'), $relPath));

			if (!is_readable($sourcePath)) {
				$this->error(sprintf($this->_('Source file is not readable: %s'), $sourcePath));
				continue;
			}

			if (!$files->copy($sourcePath, $destPath)) {
				$this->error(sprintf($this->_('Failed to copy file from %1$s to %2$s — check filesystem permissions.'), $sourcePath, $destPath));
			}
		}

		$this->message($this->_('Vite stub files have been copied to site templates directory.'));
	}

	public function ___install(): void
	{
		$this->copyStubFiles();
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function getModuleConfigInputfields(array $data): InputfieldWrapper
	{
		$modules = $this->wire()->modules;
		$normalized = $this->normalizeConfig($data);

		/** @var InputfieldWrapper $wrapper */
		$wrapper = $modules->get('InputfieldWrapper');

		/** @var InputfieldText $field */
		$field = $modules->get('InputfieldText');
		$field->attr('name', 'rootPath');
		$field->label = $this->_('Root Path');
		$field->description = $this->_('Filesystem base path used to resolve Vite assets and manifest/hot files. Defaults to the ProcessWire templates directory.');
		$field->attr('value', $normalized['rootPath']);
		$field->notes = $this->_('Example: /var/www/html/site/templates/');
		$field->required = true;
		$wrapper->add($field);

		/** @var InputfieldText $field */
		$field = $modules->get('InputfieldText');
		$field->attr('name', 'rootUrl');
		$field->label = $this->_('Root URL');
		$field->description = $this->_('URL base path used to generate asset links. Defaults to the ProcessWire templates URL.');
		$field->attr('value', $normalized['rootUrl']);
		$field->notes = $this->_('Example: /site/templates/');
		$field->required = true;
		$wrapper->add($field);

		/** @var InputfieldText $field */
		$field = $modules->get('InputfieldText');
		$field->attr('name', 'buildDirectory');
		$field->label = $this->_('Build Directory');
		$field->description = $this->_('Name of the build directory (relative to Root Path/URL) where Vite outputs production assets and the manifest.json file.');
		$field->attr('value', $normalized['buildDirectory']);
		$field->required = true;
		$wrapper->add($field);

		/** @var InputfieldText $field */
		$field = $modules->get('InputfieldText');
		$field->attr('name', 'hotFile');
		$field->label = $this->_('Hot File');
		$field->description = $this->_('Path to the Vite HMR "hot" file (relative to Root Path, or an absolute path). Leave this field blank to completely disable Hot Module Replacement detection.');
		$field->attr('value', $normalized['hotFile'] ?? '');
		$field->notes = $this->_('For example: hot or /var/www/html/public/hot. Leave empty to disable HMR.');
		$wrapper->add($field);

		/** @var InputfieldText $field */
		$field = $modules->get('InputfieldText');
		$field->attr('name', 'manifest');
		$field->label = $this->_('Manifest Filename');
		$field->description = $this->_('Name of the Vite manifest file inside the Build Directory. Also checked under .vite/ subdirectory as a fallback.');
		$field->attr('value', $normalized['manifest']);
		$field->required = true;
		$wrapper->add($field);

		/** @var InputfieldRadios $integrity */
		$integrity = $modules->get('InputfieldRadios');
		$integrity->attr('name', 'integrityToggle');
		$integrity->label = $this->_('Subresource Integrity (SRI)');
		$integrity->description = $this->_('Read integrity hashes from the manifest and add them to generated script/style tags, or disable SRI entirely.');
		$integrity->setOptions([
			'enabled' => $this->_('Enabled — use custom manifest key below'),
			'disabled' => $this->_('Disabled'),
		]);
		$currentIntegrity = $normalized['integrity'];
		$integrity->attr('value', $currentIntegrity === false ? 'disabled' : 'enabled');
		$wrapper->add($integrity);

		/** @var InputfieldText $field */
		$field = $modules->get('InputfieldText');
		$field->attr('name', 'integrity');
		$field->label = $this->_('Integrity Manifest Key');
		$field->description = $this->_('Manifest key that holds the SRI hash. Standard Vite/Laravel builds use "integrity".');
		$field->attr('value', is_string($currentIntegrity) ? $currentIntegrity : 'integrity');
		$field->showIf('integrityToggle=enabled');
		$wrapper->add($field);

		/** @var InputfieldText $field */
		$field = $modules->get('InputfieldText');
		$field->attr('name', 'nonce');
		$field->label = $this->_('CSP Nonce (optional)');
		$field->description = $this->_('Fixed Content-Security-Policy nonce value to attach to every generated tag. Leave blank to disable or use vite()->useNonce() in templates.');
		$field->attr('value', $normalized['nonce'] ?? '');
		$wrapper->add($field);

		return $wrapper;
	}
}
