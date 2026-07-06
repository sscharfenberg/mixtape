/******************************************************************************
 * Main app entrypoint
 *****************************************************************************/
import "@/styles/app.scss";
import { createInertiaApp } from "@inertiajs/vue3";
import type { DefineComponent } from "vue";
import { createApp, h } from "vue";
import FullLayout from "./components/Layout/FullLayout.vue";

/******************************************************************************
 * mount Inertia App
 *****************************************************************************/
createInertiaApp({
    resolve: name => {
        const pages = import.meta.glob<DefineComponent>("./pages/**/*.vue");
        const pageLoader = pages[`./pages/${name}.vue`];
        if (!pageLoader) {
            throw new Error(`Page not found: ${name}`);
        }

        return pageLoader();
    },
    layout: () => FullLayout,
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    title: title => (title ? `Mixtape: ${title}` : "Mixtape"),
    progress: { color: "#4f46e5" }
});
