import { defineConfig, loadEnv } from "vite";
import laravel from "laravel-vite-plugin";
import path from "path";

export default defineConfig(({ mode }) => {
	const env = loadEnv(mode, import.meta.dirname, "");
	const appUrl =
		env.APP_URL && /^https?:\/\//i.test(env.APP_URL) && URL.canParse(env.APP_URL)
			? new URL(env.APP_URL)
			: null;
	const host = appUrl && appUrl.hostname !== "domain.ltd" ? appUrl.hostname : null;
	const isHttps = appUrl?.protocol === "https:";

	return {
		root: import.meta.dirname,
		...(host && !isHttps
			? {
					server: {
						host,
						hmr: { host },
					},
				}
			: {}),
		plugins: [
			laravel({
				input: ["assets/css/app.css", "assets/js/app.js"],
				refresh: ["./**/*.php", "../classes/**/*.php"],
				publicDirectory: "./",
				detectTls: isHttps && host ? host : false,
			}),
		],
		resolve: {
			alias: {
				"@": path.resolve(import.meta.dirname, "./assets/js"),
			},
		},
	};
});
