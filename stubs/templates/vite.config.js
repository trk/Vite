import { defineConfig, loadEnv } from "vite";
import laravel from "laravel-vite-plugin";
import path from "path";

export default defineConfig(({ mode }) => {
	const env = loadEnv(mode, import.meta.dirname, "");
	const detectTls = env.APP_URL?.startsWith("https://")
		? new URL(env.APP_URL).hostname
		: null;

	return {
		root: import.meta.dirname,
		plugins: [
			laravel({
				input: ["assets/css/app.css", "assets/js/app.js"],
				refresh: ["./**/*.php", "../classes/**/*.php"],
				publicDirectory: "./",
				detectTls,
			}),
		],
		resolve: {
			alias: {
				"@": path.resolve(import.meta.dirname, "./assets/js"),
			},
		},
	};
});
