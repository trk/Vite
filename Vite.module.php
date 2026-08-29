<?php

declare(strict_types=1);

namespace ProcessWire;

/**
 * A Vite adapter for ProcessWire
 *
 * @package   Totoglu Vite
 * @author    Iskender TOTOGLU <iskender@totoglu.com>
 * @link      https://totoglu.com
 * @copyright Iskender TOTOGLU
 * @license   https://opensource.org/licenses/MIT
 *
 * @property string $rootPath Filesystem base path used to resolve Vite assets (defaults to templates path).
 * @property string $rootUrl URL base path used to generate asset URLs (defaults to templates URL).
 * @property string $buildDirectory The path to the build directory, relative to rootPath/rootUrl.
 * @property string|null $hotFile The path to the "hot" file (relative to rootPath, or absolute).
 * @property string|false $integrity The manifest key used for SRI integrity hashes, or false to disable.
 * @property string $manifest The name of the manifest file inside the build directory.
 * @property string|null $nonce Content Security Policy nonce value applied to generated tags.
 */
class Vite extends WireData implements Module, ConfigurableModule
{
    public static function getModuleInfo()
    {
        return [
            'title' => 'Vite',
            'summary' => __("Integrates Vite.js with ProcessWire for a modern frontend development workflow. This module simplifies asset bundling by automatically generating the correct script and style tags for your Vite-powered assets. It supports Hot Module Replacement (HMR) for instant feedback during development and reads Vite's manifest file in production for versioned/hashed assets, enabling efficient cache-busting.", __FILE__),
            'version' => 4,
            'icon' => 'code',
            'singular' => true,
            'autoload' => true,
            'requires' => [
                'ProcessWire>=3.0.0'
            ]
        ];
    }

    /**
     * @inheritDoc
     */
    public function __construct()
    {
        $this->wire('classLoader')->addNamespace('Totoglu\Vite', __DIR__ . '/src');

        require_once __DIR__ . '/functions.php';

        /** @var Config $config */
        $config = $this->wire('config');

        $this->set('rootPath', $config->paths->templates);
        $this->set('rootUrl', $config->urls->templates);
        $this->set('buildDirectory', 'build');
        $this->set('hotFile', 'hot');
        $this->set('integrity', 'integrity');
        $this->set('manifest', 'manifest.json');
        $this->set('nonce', null);
    }

    public function wired()
    {
        $this->wire('vite', $this);
    }

    public function init() {}

    public function ready() {}

    /**
     * Recursively copy stub files to destination
     */
    protected function copyStubFilesRecursive(string $sourceDir, string $destDir, string $relativePath = ''): void
    {
        $sourceFullPath = rtrim($sourceDir, '/\\') . ($relativePath !== '' ? '/' . ltrim($relativePath, '/\\') : '');

        if (!is_dir($sourceFullPath) || !is_readable($sourceFullPath)) {
            $this->error("Source directory is missing or unreadable: " . $sourceFullPath);
            return;
        }

        $dir = new \DirectoryIterator($sourceFullPath);

        foreach ($dir as $fileInfo) {
            if ($fileInfo->isDot()) {
                continue;
            }

            $sourcePath = $fileInfo->getPathname();
            $relPath = ($relativePath !== '' ? rtrim($relativePath, '/\\') . '/' : '') . $fileInfo->getFilename();
            $destPath = rtrim($destDir, '/\\') . '/' . ltrim($relPath, '/\\');

            if ($fileInfo->isDir()) {
                if (!is_dir($destPath)) {
                    $this->message("Creating directory: " . $relPath);
                    if (!wireMkdir($destPath)) {
                        $this->error("Failed to create directory: " . $destPath . " — check filesytem permissions.");
                        continue;
                    }
                }

                $this->copyStubFilesRecursive($sourceDir, $destDir, $relPath);
                continue;
            }

            if (file_exists($destPath)) {
                $this->message("File already exists (skipping): " . $relPath);
                continue;
            }

            $parentDir = dirname($destPath);
            if (!is_dir($parentDir) && !wireMkdir($parentDir)) {
                $this->error("Failed to create parent directory: " . $parentDir);
                continue;
            }

            $this->message("Copying file: " . $relPath);

            if (!is_readable($sourcePath)) {
                $this->error("Source file is not readable: " . $sourcePath);
                continue;
            }

            if (!@copy($sourcePath, $destPath)) {
                $this->error("Failed to copy file from " . $sourcePath . " to " . $destPath . " — check filesytem permissions.");
                continue;
            }
        }
    }

    /**
     * Copy stub files to site directory
     */
    protected function copyStubFiles(): void
    {
        $stubsDir = __DIR__ . '/stubs';
        $siteDir = $this->wire('config')->paths->site;

        if (!is_dir($stubsDir)) {
            $this->error("Stubs directory not found: $stubsDir");
            return;
        }

        $this->copyStubFilesRecursive($stubsDir, $siteDir);
        $this->message("Vite stub files have been copied to site templates directory.");
    }

    public function ___install()
    {
        $this->copyStubFiles();
    }

    public function ___installConfig(): array
    {
        return [
            'rootPath'       => $this->wire('config')->paths->templates,
            'rootUrl'        => $this->wire('config')->urls->templates,
            'buildDirectory' => 'build',
            'hotFile'        => 'hot',
            'integrity'      => 'integrity',
            'manifest'       => 'manifest.json',
            'nonce'          => null,
        ];
    }

    public function getModuleConfigInputfields(array $data): InputfieldWrapper
    {
        /** @var Modules $modules */
        $modules = $this->wire('modules');
        /** @var Config $config */
        $config  = $this->wire('config');
        /** @var InputfieldWrapper $wrapper */
        $wrapper = $modules->get('InputfieldWrapper');

        /** @var InputfieldText $f */
        $f = $modules->get('InputfieldText');
        $f->attr('name', 'rootPath');
        $f->label = $this->_('Root Path');
        $f->description = $this->_('Filesystem base path used to resolve Vite assets and manifest/hot files. Defaults to the ProcessWire templates directory.');
        $f->attr('value', $data['rootPath'] ?? $config->paths->templates);
        $f->notes = $this->_('Example: /var/www/html/site/templates/');
        $f->required = true;
        $wrapper->add($f);

        $f = $modules->get('InputfieldText');
        $f->attr('name', 'rootUrl');
        $f->label = $this->_('Root URL');
        $f->description = $this->_('URL base path used to generate asset links. Defaults to the ProcessWire templates URL.');
        $f->attr('value', $data['rootUrl'] ?? $config->urls->templates);
        $f->notes = $this->_('Example: /site/templates/');
        $f->required = true;
        $wrapper->add($f);

        $f = $modules->get('InputfieldText');
        $f->attr('name', 'buildDirectory');
        $f->label = $this->_('Build Directory');
        $f->description = $this->_('Name of the build directory (relative to Root Path/URL) where Vite outputs production assets and the manifest.json file.');
        $f->attr('value', $data['buildDirectory'] ?? 'build');
        $wrapper->add($f);

        $f = $modules->get('InputfieldText');
        $f->attr('name', 'hotFile');
        $f->label = $this->_('Hot File');
        $f->description = $this->_('Path to the Vite HMR "hot" file (relative to Root Path, or an absolute path). Leave empty to disable HMR detection.');
        $f->attr('value', $data['hotFile'] ?? 'hot');
        $f->notes = $this->_('For example: hot or /var/www/html/public/hot');
        $wrapper->add($f);

        $f = $modules->get('InputfieldText');
        $f->attr('name', 'manifest');
        $f->label = $this->_('Manifest Filename');
        $f->description = $this->_('Name of the Vite manifest file inside the Build Directory. Also checked under .vite/ subdirectory as a fallback.');
        $f->attr('value', $data['manifest'] ?? 'manifest.json');
        $wrapper->add($f);

        /** @var InputfieldRadios $integrity */
        $integrity = $modules->get('InputfieldRadios');
        $integrity->attr('name', 'integrityToggle');
        $integrity->label = $this->_('Subresource Integrity (SRI)');
        $integrity->description = $this->_('Read integrity hashes from the manifest and add them to generated script/style tags, or disable SRI entirely.');
        $integrity->setOptions([
            'enabled'  => $this->_('Enabled — use custom manifest key below'),
            'disabled' => $this->_('Disabled'),
        ]);
        $currentIntegrity = $data['integrity'] ?? 'integrity';
        $integrity->attr('value', $currentIntegrity === false ? 'disabled' : 'enabled');
        $wrapper->add($integrity);

        $f = $modules->get('InputfieldText');
        $f->attr('name', 'integrity');
        $f->label = $this->_('Integrity Manifest Key');
        $f->description = $this->_('Manifest key that holds the SRI hash. Standard Vite/Laravel builds use "integrity".');
        $f->attr('value', is_string($currentIntegrity) ? $currentIntegrity : 'integrity');
        $f->showIf("integrityToggle=enabled");
        $wrapper->add($f);

        $f = $modules->get('InputfieldText');
        $f->attr('name', 'nonce');
        $f->label = $this->_('CSP Nonce (optional)');
        $f->description = $this->_('Fixed Content-Security-Policy nonce value to attach to every generated tag. Leave blank to disable or use vite()->useNonce() in templates.');
        $f->attr('value', $data['nonce'] ?? '');
        $wrapper->add($f);

        return $wrapper;
    }

    public function ___getConfig(array $data): array
    {
        $data = parent::___getConfig($data);

        if (isset($data['integrityToggle']) && $data['integrityToggle'] === 'disabled') {
            $data['integrity'] = false;
            unset($data['integrityToggle']);
        } elseif (isset($data['integrityToggle'])) {
            if (!isset($data['integrity']) || trim((string) $data['integrity']) === '') {
                $data['integrity'] = 'integrity';
            }
            unset($data['integrityToggle']);
        }

        $stringClean = ['rootPath', 'rootUrl', 'buildDirectory', 'hotFile', 'manifest'];
        foreach ($stringClean as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $data[$key] = trim($data[$key]);
            }
        }

        if (isset($data['nonce']) && is_string($data['nonce']) && trim($data['nonce']) === '') {
            $data['nonce'] = null;
        }

        return $data;
    }
}
