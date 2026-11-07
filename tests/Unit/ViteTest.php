<?php

declare(strict_types=1);

use ProcessWire\Vite as ViteModule;
use ProcessWire\WireException;
use Totoglu\Vite\Vite;

use function ProcessWire\setting;
use function ProcessWire\vite;
use function ProcessWire\wire;

beforeEach(function (): void {
	Vite::flush(true);
	setting('vite', []);
	setting('vite.scriptTagAttributes', []);
	setting('vite.styleTagAttributes', []);
	setting('vite.preloadTagAttributes', []);

	$this->tempDir = sys_get_temp_dir() . '/pw-vite-test-' . uniqid('', true);
	mkdir($this->tempDir . '/build/.vite', 0777, true);
	mkdir($this->tempDir . '/templates/assets/js', 0777, true);
	mkdir($this->tempDir . '/templates/assets/css', 0777, true);
});

afterEach(function (): void {
	Vite::flush(true);
	setting('vite', []);
	setting('vite.scriptTagAttributes', []);
	setting('vite.styleTagAttributes', []);
	setting('vite.preloadTagAttributes', []);

	if (isset($this->tempDir) && is_dir($this->tempDir)) {
		wire()->files->rmdir($this->tempDir, true);
	}
});

it('normalizes rootPath and rootUrl trailing slashes and handles null safely', function (): void {
	setting('vite', [
		'rootPath' => $this->tempDir,
		'rootUrl' => 'https://cdn.example.com',
	]);

	$instance = new Vite();

	expect($instance->url(null))->toBe('https://cdn.example.com/')
		->and($instance->url('build/assets/app.js'))->toBe('https://cdn.example.com/build/assets/app.js')
		->and($instance->url('/build/assets/app.js'))->toBe('https://cdn.example.com/build/assets/app.js')
		->and($instance->url('build\\assets\\app.js'))->toBe('https://cdn.example.com/build/assets/app.js')
		->and($instance->useRootPath('/custom/root')->useRootUrl('https://custom.example.com\\')->url('assets/app.js'))
		->toBe('https://custom.example.com/assets/app.js');
});

it('supports both relative and absolute hotFile paths and renders HMR tags', function (): void {
	$absoluteHotFile = $this->tempDir . '/custom-vite.hot';
	file_put_contents($absoluteHotFile, "http://localhost:5173\n");

	setting('vite', [
		'rootPath' => $this->tempDir,
		'rootUrl' => '/site/templates/',
		'hotFile' => $absoluteHotFile,
	]);

	$instance = new Vite();

	expect($instance->hotFile())->toBe($absoluteHotFile)
		->and($instance->isRunningHot())->toBeTrue()
		->and($instance->hotAsset('templates/assets/js/app.js'))->toBe('http://localhost:5173/templates/assets/js/app.js');

	$html = $instance(['templates/assets/css/app.css', 'templates/assets/js/app.js']);

	expect($html)
		->toContain('<script type="module" src="http://localhost:5173/@vite/client"></script>')
		->toContain('<link rel="stylesheet" href="http://localhost:5173/templates/assets/css/app.css">')
		->toContain('<script type="module" src="http://localhost:5173/templates/assets/js/app.js"></script>');
});

it('supports withReactRefresh() fluent chain without bleeding into subsequent calls', function (): void {
	$hotFile = $this->tempDir . '/hot';
	file_put_contents($hotFile, 'http://localhost:5173');

	setting('vite', [
		'rootPath' => $this->tempDir,
	]);

	expect(vite()->reactRefresh())->toStartWith('<script type="module">');

	$firstHtml = (string) vite()
		->withReactRefresh()
		->useNonce('test-nonce-123')
		->withEntries(['templates/assets/js/app.jsx']);

	$secondHtml = (string) vite('templates/assets/js/extra.js');
	$emptyHtml = (string) vite();

	expect($firstHtml)
		->toContain('<script type="module" nonce="test-nonce-123">')
		->toContain('import RefreshRuntime from \'http://localhost:5173/@react-refresh\'')
		->toContain('src="http://localhost:5173/templates/assets/js/app.jsx"')
		->and($secondHtml)
		->not->toContain('RefreshRuntime')
		->toContain('src="http://localhost:5173/templates/assets/js/extra.js"')
		->and($emptyHtml)
		->toBe('');
});

it('applies setting() overrides even when configured after singleton instantiation', function (): void {
	$manifest = [
		'templates/assets/js/app.js' => [
			'file' => 'assets/app-123.js',
			'isEntry' => true,
		],
	];

	file_put_contents(
		$this->tempDir . '/build/manifest.json',
		json_encode($manifest, JSON_THROW_ON_ERROR)
	);

	// Instantiate singleton BEFORE setting('vite', ...) is called
	$singleton = Vite::instance();

	setting('vite', [
		'rootPath' => $this->tempDir,
		'rootUrl' => 'https://late-cdn.example.com',
		'hotFile' => '',
	]);
	setting('vite.scriptTagAttributes', [
		'data-late-attr' => 'enabled',
	]);

	$html = $singleton('templates/assets/js/app.js');

	expect($html)
		->toContain('src="https://late-cdn.example.com/build/assets/app-123.js"')
		->toContain('data-late-attr="enabled"');
});

it('normalizes ConfigurableModule data via setConfigData including integrityToggle disabled', function (): void {
	$module = new ViteModule();

	$module->setConfigData([
		'rootPath' => '  /custom/path/  ',
		'rootUrl' => '  /custom/url/  ',
		'buildDirectory' => '  dist  ',
		'hotFile' => '   ',
		'integrityToggle' => 'disabled',
		'integrity' => 'integrity',
		'nonce' => '   ',
	]);

	expect($module->rootPath)->toBe('/custom/path/')
		->and($module->rootUrl)->toBe('/custom/url/')
		->and($module->buildDirectory)->toBe('dist')
		->and($module->hotFile)->toBeNull()
		->and($module->integrity)->toBeFalse()
		->and($module->nonce)->toBeNull();
});

it('renders production manifest chunks, SRI integrity, transitive imports, and optional @ entries without leaking chunk state', function (): void {
	$manifest = [
		'templates/assets/js/app.js' => [
			'file' => 'assets/app-123.js',
			'src' => 'templates/assets/js/app.js',
			'isEntry' => true,
			'integrity' => 'sha384-appHash',
			'imports' => ['_vendor-456.js'],
			'css' => ['assets/app-789.css'],
		],
		'_vendor-456.js' => [
			'file' => 'assets/vendor-456.js',
			'integrity' => 'sha384-vendorHash',
			'imports' => ['_deep-999.js'],
		],
		'_deep-999.js' => [
			'file' => 'assets/deep-999.js',
			'integrity' => 'sha384-deepHash',
		],
		'templates/assets/css/app.css' => [
			'file' => 'assets/app-789.css',
			'src' => 'templates/assets/css/app.css',
			'integrity' => 'sha384-cssHash',
		],
	];

	file_put_contents(
		$this->tempDir . '/build/.vite/manifest.json',
		json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
	);

	file_put_contents($this->tempDir . '/templates/assets/js/unbuilt-optional.js', '// optional');

	setting('vite', [
		'rootPath' => $this->tempDir,
		'rootUrl' => '/site/templates',
		'hotFile' => '',
	]);

	$instance = new Vite();
	$html = $instance([
		'templates/assets/js/app.js',
		'@templates/assets/js/unbuilt-optional.js',
		'@templates/assets/js/non-existent.js',
	]);

	expect($html)
		->toContain('<link rel="preload" as="style" href="/site/templates/build/assets/app-789.css" integrity="sha384-cssHash">')
		->toContain('<link rel="modulepreload" href="/site/templates/build/assets/app-123.js" integrity="sha384-appHash">')
		->toContain('<link rel="modulepreload" href="/site/templates/build/assets/vendor-456.js" integrity="sha384-vendorHash">')
		->toContain('<link rel="modulepreload" href="/site/templates/build/assets/deep-999.js" integrity="sha384-deepHash">')
		->toContain('<link rel="stylesheet" href="/site/templates/build/assets/app-789.css" integrity="sha384-cssHash">')
		->toContain('<script type="module" src="/site/templates/build/assets/app-123.js" integrity="sha384-appHash"></script>')
		->and(substr_count($html, 'assets/app-123.js'))->toBe(2);
});

it('generates cryptographic 40-char nonce and merges custom tag attributes', function (): void {
	$manifest = [
		'templates/assets/js/app.js' => [
			'file' => 'assets/app-123.js',
			'isEntry' => true,
		],
	];

	file_put_contents(
		$this->tempDir . '/build/manifest.json',
		json_encode($manifest, JSON_THROW_ON_ERROR)
	);

	setting('vite', [
		'rootPath' => $this->tempDir,
		'rootUrl' => '/site/templates/',
		'hotFile' => '',
		'integrity' => false,
	]);

	$instance = (new Vite())
		->useNonce()
		->useScriptTagAttributes(['data-turbo-track' => 'reload', 'defer' => true]);

	expect($instance->nonce())->toBeString()->toMatch('/^[0-9a-f]{40}$/');

	$html = $instance('templates/assets/js/app.js');

	expect($html)
		->toContain('nonce="' . $instance->nonce() . '"')
		->toContain('data-turbo-track="reload"')
		->toContain(' defer');
});

it('throws WireException when required entry or manifest is missing', function (): void {
	setting('vite', [
		'rootPath' => $this->tempDir,
		'hotFile' => '',
	]);

	$instance = new Vite();

	expect(fn(): string => $instance('templates/assets/js/missing.js'))
		->toThrow(WireException::class, 'Vite manifest not found');
});
