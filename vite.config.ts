import path from "node:path";
import { fileURLToPath } from "node:url";
import { default as vitePluginVue } from "@vitejs/plugin-vue";
import laravelPlugin from "laravel-vite-plugin";
import { Features } from "lightningcss";
import { defineConfig, loadEnv } from "vite";
import { ViteImageOptimizer } from "vite-plugin-image-optimizer";
import vitePluginVueDevtools from "vite-plugin-vue-devtools";
const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

/*
 * https://vite.dev/config/
 */
export default defineConfig(({ mode }) => {
    const env = loadEnv(mode, process.cwd(), "");

    return {
        /*
         * https://vite.dev/config/shared-options.html#root
         */
        // root: "resources/app",

        /*
         * https://vite.dev/config/shared-options.html#publicdir
         */
        // publicDir: path.resolve(__dirname, "resources/app/static/"),

        /*
         * https://vite.dev/config/shared-options.html#plugins
         */
        plugins: [
            // https://github.com/laravel/vite-plugin
            // https://laravel.com/docs/12.x/vite
            laravelPlugin({
                input: ["resources/app/main.ts"],
                refresh: true
            }),

            // https://github.com/vitejs/vite/tree/main/packages/plugin-vue
            vitePluginVue({
                template: {
                    transformAssetUrls: {
                        // The Vue plugin will re-write asset URLs, when referenced
                        // in Single File Components, to point to the Laravel web
                        // server. Setting this to `null` allows the Laravel plugin
                        // to instead re-write asset URLs to point to the Vite
                        // server instead.
                        base: null,

                        // The Vue plugin will parse absolute URLs and treat them
                        // as absolute paths to files on disk. Setting this to
                        // `false` will leave absolute URLs untouched so they can
                        // reference assets in the public directly as expected.
                        includeAbsolute: false
                    },
                    compilerOptions: {
                        isCustomElement: tag => tag.startsWith("media-")
                    }
                }
            }),

            // https://devtools.vuejs.org/
            // https://www.npmjs.com/package/vite-plugin-vue-devtools
            vitePluginVueDevtools(),

            // https://www.npmjs.com/package/vite-plugin-image-optimizer
            ViteImageOptimizer({
                test: /\.(jpe?g|png|webp|svg|avif)$/i,
                exclude: undefined,
                include: undefined,
                includePublic: true,
                logStats: true,
                ansiColors: true,
                svg: {
                    multipass: true,
                    plugins: [
                        {
                            name: "preset-default",
                            params: {
                                overrides: {
                                    cleanupNumericValues: false
                                },
                                cleanupIDs: {
                                    minify: false,
                                    remove: false
                                },
                                convertPathData: false
                            }
                        },
                        "sortAttrs",
                        {
                            name: "addAttributesToSVGElement",
                            params: {
                                attributes: [{ xmlns: "http://www.w3.org/2000/svg" }]
                            }
                        }
                    ]
                },
                png: {
                    // https://sharp.pixelplumbing.com/api-output#png
                    quality: 100
                },
                jpeg: {
                    // https://sharp.pixelplumbing.com/api-output#jpeg
                    quality: 60
                },
                jpg: {
                    // https://sharp.pixelplumbing.com/api-output#jpeg
                    quality: 60
                },
                webp: {
                    // https://sharp.pixelplumbing.com/api-output#webp
                    lossless: true
                },
                avif: {
                    // https://sharp.pixelplumbing.com/api-output#avif
                    lossless: true
                }
            })
        ],

        /*
         * https://vite.dev/config/shared-options.html#resolve-alias
         */
        resolve: {
            alias: {
                "~": path.resolve(__dirname, "node_modules"),
                "@": path.resolve(__dirname, "resources/app"),
                Assets: path.resolve(__dirname, "resources/app/assets"),
                Components: path.resolve(__dirname, "resources/app/components"),
                Composables: path.resolve(__dirname, "resources/app/composables"),
                Utils: path.resolve(__dirname, "resources/app/utils"),
                Abstracts: path.resolve(__dirname, "resources/app/styles/abstracts"),
                Types: path.resolve(__dirname, "resources/app/types")
            }
        },

        optimizeDeps: {
            exclude: ["js-big-decimal"]
        },

        /*
         * https://vite.dev/config/build-options.html
         */
        css: {
            lightningcss: {
                exclude: Features.LightDark
            }
        },

        build: {
            // outDir: path.resolve(__dirname, "public"),
            emptyOutDir: true
        },

        server: {
            // Bind all interfaces (the `dev` npm script also passes --host).
            host: true,

            // When VITE_SERVER_ORIGIN is set, Vite advertises that URL in
            // public/hot so Laravel generates correct asset URLs. On a remote
            // dev box a TLS-terminating nginx vhost (e.g. https://dev.local:5174)
            // proxies to this dev server on 127.0.0.1:5173, so the origin is that URL.
            origin: env.VITE_SERVER_ORIGIN || undefined,
            cors: true,

            // Hostnames Vite accepts in the Host header. Required when a reverse
            // proxy forwards requests as the proxy's own host. Unset in local dev
            // (Vite's default already allows localhost / 127.0.0.1).
            allowedHosts: env.VITE_ALLOWED_HOSTS ? env.VITE_ALLOWED_HOSTS.split(",") : undefined,

            // HMR websocket target the browser connects to. Behind the proxy this
            // must point at the public TLS port so the client opens
            // wss://<dev-host>:5174 instead of the unreachable ws://…:5173.
            // Unset (local dev) keeps Vite's default localhost websocket.
            hmr: env.VITE_HMR_HOST
                ? {
                      host: env.VITE_HMR_HOST,
                      protocol: env.VITE_HMR_PROTOCOL || "wss",
                      clientPort: env.VITE_HMR_PORT ? Number(env.VITE_HMR_PORT) : undefined
                  }
                : undefined,

            watch: {
                ignored: [
                    "**/vendor/**",
                    "**/storage/**",
                    "**/node_modules/**",
                    "**/public/build/**",
                    "**/public/storage/**"
                ]
            }
        }
    };
});
