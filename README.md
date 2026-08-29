# ProcessWire Vite Module — Laravel Vite Plugin Adapter

Integrates the official `laravel-vite-plugin` with ProcessWire CMS/CMF so you
can compile, version and hot-reload your front-end assets (CSS, JavaScript,
TypeScript, React/Vue, static images and fonts) directly from your templates.

After installation you simply call the global `vite()` helper from any
ProcessWire template file and the module takes care of the rest — serving
hot-reloaded assets from the Vite dev server during development and serving
cache-busted, versioned files from the manifest.json on production.

The module ships with:

- Automatic, zero-config copy of ready-to-use starter files (package.json,
  vite.config.js, postcss.config.js, tailwind.config.js, example entry points)
  into your templates directory during module install
- Built-in Subresource Integrity (SRI) support using the sha-256/sha-384/sha-512
  hashes written by Vite into the production manifest
- Full Content-Security-Policy nonce support via configurable per-request nonces
- Optional React HMR runtime helper (`reactRefresh()`)
- Optional `@auto` / `@asset` prefix for per-template "load if exists" optional
  assets
- Fluent API: `vite()->useBuildDirectory(...)->withEntries(...)` as well as
  centralized configuration through ProcessWire's native `setting()` registry
  and through the module's **Module Admin Config Screen** (ConfigurableModule)
- Global-namespace helper function (`vite()`) that works from any template,
  regardless of the PHP namespace declared at the top of the file

---

## Ready-to-Use Starter Files

When you click **Install** in the ProcessWire admin the module copies all files
from the `stubs/templates/` folder into your active `site/templates/` directory.
The stubs give you a working starting point with:

- a `package.json` with pinned dev-dependency versions
- a `vite.config.js` already configured for a typical ProcessWire project
  layout (entry points inside `templates/assets/`)
- an optional Tailwind + PostCSS setup
- example `app.css` and `app.js` entry files

You can browse the latest upstream stub files on GitHub at:
<https://github.com/trk/processwire-vite/tree/main/stubs>

---

## Requirements

| Requirement | Minimum Version |
| --- | --- |
| PHP                 | 8.0 or newer (strict_types enabled throughout) |
| ProcessWire         | 3.0.165 or newer (uses `wire()` API, module-info array format) |
| Node / npm          | Any Node LTS release supported by the `vite` package (v18+) |
| laravel-vite-plugin | 2.x or 3.x (stubs ship with 3.x) |
| Vite                | 7.x or 8.x (stubs ship with 8.x) |

---

## Installation

The module can be installed through any of the three standard ProcessWire
module installation methods.

**Option 1 — Modules Directory (recommended for beginners)**

Click **Modules → New** in the ProcessWire admin and enter **Vite** in the
"Install from Modules Directory" search box, then click install. The
ProcessWire Modules Directory listing is available at
<https://modules.processwire.com/modules/vite/>.

**Option 2 — Composer**

```bash
composer require trk/processwire-vite
```

**Option 3 — Git Submodule**

```bash
git submodule add https://github.com/trk/processwire-vite.git site/modules/Vite
```

**Option 4 — Manual Download**

Download the repository as a ZIP file from GitHub and unpack it into the
`site/modules/Vite/` folder inside your ProcessWire installation (or into
`site-dev/modules/Vite/` if you use a multi-site layout).

After placing the files, go to **Modules → Site** in the ProcessWire admin and
click the **Refresh** button, then click **Install** next to the "Vite" entry.

> **Note:** If you see the module listed twice it is because ProcessWire has
> detected both the `.module` and the `.module.php` file extension. This is
> harmless; either variant works with this module.

---

## Installing the Laravel Vite Plugin and Vite

Detailed documentation for the Laravel Vite plugin itself is on the Laravel
website at <https://laravel.com/docs/vite>. The ProcessWire adapter mirrors its
conventions and API so examples from the Laravel docs can be reused almost
verbatim.

If you start from a blank template folder, first initialize npm inside the
root of your ProcessWire project (the same folder where your project's
`composer.json` lives):

```bash
npm init -y
```

Then install Vite and the Laravel Vite plugin as dev dependencies:

```bash
npm install --save-dev vite laravel-vite-plugin
```

Next, add the standard `dev` and `build` scripts to your `package.json`:

```json
{
  "scripts": {
    "dev":   "vite",
    "build": "vite build"
  }
}
```

Finally, create a `vite.config.js` that points Vite to your ProcessWire entry
points. For a typical project where source files live inside
`templates/assets/` the starter config looks like this:

```js
import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";

export default defineConfig({
    plugins: [
        laravel({
            input: [
                "templates/assets/css/app.css",
                "templates/assets/js/app.js",
            ],
            refresh: true,
        }),
    ],
});
```

Run the dev server with:

```bash
npm run dev
```

And build production assets with:

```bash
npm run build
```

Production assets are written into your configured `buildDirectory` (by default
`templates/build/`) together with a `manifest.json` and per-file
`integrity.json` that the ProcessWire module reads at render time.

---

## Refreshing the Browser on Template / PHP File Save

With Traditional Server-Side Rendered (SSR) projects like ProcessWire you can
ask the Vite dev server to watch for changes to `.php` files and reload the
browser automatically when a template, include or module class is saved.

The Laravel plugin provides a dedicated `refresh` option for this:

```js
import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";

export default defineConfig({
    plugins: [
        laravel({
            input: [
                "templates/assets/css/app.css",
                "templates/assets/js/app.js",
            ],
            refresh: [
                "templates/**/*.php",
                "templates/**/*.latte",
                "templates/**/*.twig",
                "site/classes/**/*.php",
            ],
        }),
    ],
});
```

Using `refresh: true` (a boolean) makes the plugin watch all supported files
in the current working directory automatically.

---

## Quick Start — Using `vite()` From Templates

Once both the module and the npm packages are installed, render all script
and style tags for your configured entry points by calling the global helper
at the top of any ProcessWire template:

```php
<?php
// Inside any site/templates/*.php file:
echo vite([
    "templates/assets/css/app.css",
    "templates/assets/js/app.js",
]);
```

This single call expands into one `<link rel="stylesheet">` tag per CSS entry
plus one `<script type="module">` tag per JS entry, with:

- `@vite/client` runtime script injected automatically when the dev server is
  running, so hot module replacement works out of the box
- relative asset URLs automatically rewritten using the production manifest
- `integrity` attributes injected from `integrity.json` when SRI is enabled
- `nonce="..."` attributes added to every tag when a CSP nonce is configured
- all related stylesheets (CSS imported by JS files and CSS chunk files) and
  `<link rel="modulepreload">` tags for transitive JS dependencies resolved
  automatically

A full HTML template skeleton therefore looks like the following:

```php
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $page->title; ?></title>

    <?php echo vite([
        "templates/assets/css/app.css",
        "templates/assets/js/app.js",
    ]); ?>
</head>
<body>
    <h1><?php echo $page->title; ?></h1>
</body>
</html>
```

---

## Optional Assets — the `@` "Load If Exists" Prefix

When you prepend an `@` sign to the beginning of an asset path, the ProcessWire
module treats it as **optional**: if no corresponding file exists on disk at
the given source path, nothing is rendered for that entry and no error is
raised.

This is ideal for per-template CSS or JS files where you want a convention
over configuration approach.

```php
<?php
echo vite([
    // Always loaded:
    "templates/assets/css/app.css",
    "templates/assets/js/app.js",

    // Loaded only when a matching file exists on disk:
    "@templates/assets/css/templates/{$page->template->name}.css",
    "@templates/assets/js/templates/{$page->template->name}.js",
]);
```

> **Important:** Even though optional assets are skipped at render time when
> missing, you **still need to declare them inside the `input:` array of your
> `vite.config.js`**. The reason is simple: `npm run build` has to know which
> files to compile into the production manifest. If you forget to add them to
> the Vite config, optional assets work in `npm run dev` mode but will be
> missing completely on the production site after `vite build`.

A correct Vite config for a project that has a "home" and a "basic-page"
template with their own optional CSS/JS looks like this:

```js
export default defineConfig({
    plugins: [
        laravel({
            input: [
                "templates/assets/css/app.css",
                "templates/assets/js/app.js",
                "templates/assets/css/templates/home.css",
                "templates/assets/js/templates/home.js",
                "templates/assets/css/templates/basic-page.css",
                "templates/assets/js/templates/basic-page.js",
            ],
        }),
    ],
});
```

---

## React Support with Automatic HMR Runtime

When you use React, the Vite dev server expects a small runtime script to be
injected before your entry point so that Fast Refresh works correctly for JSX
components.

This module exposes a dedicated `reactRefresh()` fluent helper for that
purpose. The React runtime injection **must** appear before the main `vite()`
call in your markup:

```php
<?php echo vite()->reactRefresh(); ?>
<?php echo vite("templates/assets/js/app.jsx"); ?>
```

Or, using the fluent chain form (equivalent and shorter):

```php
<?php echo vite()->reactRefresh()->withEntries([
    "templates/assets/js/app.jsx",
]); ?>
```

No `npm install` step for the React plugin is required from the ProcessWire
side of things; the standard `@vitejs/plugin-react` must be installed and
registered in your `vite.config.js` for the runtime to have any effect.

---

## Processing Static Assets (Images, Fonts, etc.)

When you reference images or fonts inside a `.css` file (with `url(...)`) or
inside a `.js` module (with `import logo from "./logo.png"`), Vite processes
those files automatically: it copies them into the build directory with a
content-hashed filename and rewrites all references to point at the versioned
copy.

To make the same versioning behavior work for assets referenced **directly in
ProcessWire templates**, Vite needs to be told to "see" those static files at
build time. The idiomatic way is to use `import.meta.glob` inside a JS entry
point.

The following example makes every file under `templates/assets/images/` and
`templates/assets/fonts/` known to Vite so it gets versioned and appears in
the production manifest:

```js
// templates/assets/js/app.js
import.meta.glob([
    "../images/**",
    "../fonts/**",
]);
```

Once they are registered in the manifest, you can obtain the versioned URL of
any static asset from within your ProcessWire templates by calling the fluent
`asset()` method on the Vite instance:

```php
<img
    src="<?php echo vite()->asset("templates/assets/images/logo.png"); ?>"
    alt="Company Logo"
>
```

The return value of `asset()` is always a string containing either the dev
server URL (while `npm run dev` is active) or the cache-busted production URL
with a content hash in the filename.

---

## Adding Arbitrary HTML Attributes to Tags

You can attach custom HTML attributes to every `<script>`, `<link rel="stylesheet">`
and `<link rel="modulepreload">` tag that the module generates. Attributes are
configured through ProcessWire's native `setting()` registry and can be set
either **statically** (an array of key/value pairs) or **dynamically** (a
PHP closure that receives the source path, resolved URL, manifest chunk and
full manifest array and returns an attribute map).

### Static Attributes

```php
setting("vite.scriptTagAttributes", [
    "data-turbo-track" => "reload",  // attribute with a string value
    "async"            => true,      // boolean attribute: renders as `async`
    "integrity"        => false,     // FALSE = remove attribute entirely
]);

setting("vite.styleTagAttributes", [
    "data-turbo-track" => "reload",
]);

setting("vite.preloadTagAttributes", [
    "crossorigin" => false,          // omit crossorigin from preloads
    "integrity"   => false,          // omit SRI from preload tags
]);
```

### Dynamic / Closure Attributes

```php
setting("vite.scriptTagAttributes", function (
    string $src,
    string $url,
    array  $chunk,
    array  $manifest
): array {
    return [
        "data-turbo-track" => $src === "templates/assets/js/app.js"
            ? "reload"
            : false,
    ];
});

setting("vite.styleTagAttributes", function (
    string $src,
    string $url,
    array  $chunk,
    array  $manifest
): array {
    return [
        "data-entry" => isset($chunk["isEntry"]) && $chunk["isEntry"]
            ? "1"
            : false,
    ];
});
```

> **Note:** When running under the Vite development server the `$chunk` and
> `$manifest` arguments passed into the callback will be empty arrays because
> no manifest is generated by `vite`. If your attribute decision logic needs
> manifest information, branch on `empty($manifest)` first to avoid errors.

---

## Configuration — Module Admin Screen and/or `setting()` Overrides

Out of the box the module ships with ProcessWire's `ConfigurableModule`
interface implemented. Navigate to **Modules → Site → Vite → Module Config**
in the ProcessWire admin to tweak:

- **Root Path** (default: ProcessWire's `$config->paths->templates`)
- **Root URL**  (default: ProcessWire's `$config->urls->templates`)
- **Build Directory** name
- **Hot File** path or name
- **Manifest Filename**
- **Subresource Integrity** on/off + manifest key name
- **Content-Security-Policy Nonce** value or leave blank to disable

All the same options can also be overridden programmatically via ProcessWire's
`setting()` helper, by setting a single top-level `setting("vite", [...])`
array entry. Values set via `setting()` **take precedence over** values saved
in the module admin screen.

```php
setting("vite", [
    "hotFile"        => "/var/www/vite.hot",   // absolute path or callable
    "buildDirectory" => "bundle",
    "manifest"       => "assets.json",
    "integrity"      => "integrity",
    "nonce"          => "my-project-csp-nonce",
    "rootPath"       => "/srv/app/public",
    "rootUrl"        => "https://cdn.example.com",
]);
```

Any of the values above may also be a **callable / closure** that returns the
final value at render time; this is handy when you need to read the value from
ProcessWire's `$config` or from an environment variable:

```php
setting("vite", [
    "hotFile" => function () {
        return wire("config")->paths->root . "vite.hot";
    },
]);
```

Finally, you can override any option per-request using the fluent API on the
Vite instance itself:

```php
<?php
echo vite()
    ->useHotFile(wire("config")->paths->root . "vite.hot")
    ->useBuildDirectory("bundle")
    ->useManifest("assets.json")
    ->withEntries(["templates/assets/js/app.js"]);
```

The corresponding options inside `vite.config.js` must match:

```js
import { defineConfig } from "vite";
import laravel from "laravel-vite-plugin";

export default defineConfig({
    plugins: [
        laravel({
            hotFile:        "/var/www/vite.hot",
            buildDirectory: "bundle",
            input: [
                "templates/assets/css/app.css",
                "templates/assets/js/app.js",
            ],
        }),
    ],
    build: {
        manifest: "assets.json",
    },
});
```

---

## Commercial Usage

This ProcessWire Vite module is free and open source under the MIT license. If
it saves you time on a commercial project, please consider sponsoring the
maintainer on Patreon at <https://patreon.com/ukyo> — every bit helps fund
new features and testing.

---

## License

The ProcessWire Vite Adapter is open source software released under the terms
of the **MIT License**. See the `LICENSE` file distributed with the module for
the full legal text.

---

## Credits

- **Iskender TOTOGLU** (<https://github.com/trk>) — original ProcessWire
  module author and maintainer
- **Lukas Kleinschmidt** (<https://github.com/lukasklei>) — early
  ProcessWire 3.x compatibility patches and testing
- **The Laravel Framework Team** (<https://github.com/laravel>) — authors of
  the upstream `laravel-vite-plugin` whose conventions and API surface this
  module closely mirrors

A substantial portion of the usage examples and prose documentation in this
README has been adapted from the official Laravel documentation at
<https://laravel.com/docs/vite>, in accordance with the MIT license under
which both projects are released.
