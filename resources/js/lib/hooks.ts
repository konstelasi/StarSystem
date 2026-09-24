import * as Vue from 'vue';
import { defineAsyncComponent } from 'vue';
import type { Component } from 'vue';

/**
 * What the server's HookRegistry::viewSlot() returns for each component a
 * module adds to a view slot.
 */
export type HookSlotItem = {
    component: string;
    props: Record<string, unknown> | unknown[];
    src: string | null;
};

type Loader = () => Promise<Component | { default: Component }>;

const loaders = new Map<string, Loader>();
const components = new Map<string, Component>();

/**
 * Makes a component available to view slots under `name`. Core components
 * in `components/hook-slots/` register themselves by file name; modules
 * that ship prebuilt scripts call this through `window.StarSystem`.
 */
export function registerHookComponent(name: string, loader: Loader): void {
    loaders.set(name, loader);
    components.delete(name);
}

/**
 * The component for a slot item, or null when nothing provides it. A
 * `slug::Export` name that isn't registered loads the named export from
 * the item's `src`, a module's prebuilt ES module. Modules can't be built
 * on the host, so their scripts come prebuilt with Vue left out, and use
 * `window.StarSystem.Vue` instead.
 */
export function hookComponent(item: HookSlotItem): Component | null {
    const cacheKey = item.src
        ? `${item.component}@${item.src}`
        : item.component;
    const cached = components.get(cacheKey);

    if (cached) {
        return cached;
    }

    let loader = loaders.get(item.component);

    if (!loader && item.src && item.component.includes('::')) {
        const exportName = item.component.split('::')[1];
        const src = item.src;

        loader = async () => {
            const module = (await import(/* @vite-ignore */ src)) as Record<
                string,
                Component
            >;

            return module[exportName];
        };
    }

    if (!loader) {
        return null;
    }

    const component = defineAsyncComponent(loader);
    components.set(cacheKey, component);

    return component;
}

const core = import.meta.glob<Component>('../components/hook-slots/*.vue');

for (const [path, load] of Object.entries(core)) {
    const name = path
        .split('/')
        .pop()
        ?.replace(/\.vue$/, '');

    if (name) {
        registerHookComponent(name, load);
    }
}

declare global {
    interface Window {
        StarSystem?: {
            Vue: typeof Vue;
            registerHookComponent: typeof registerHookComponent;
        };
    }
}

window.StarSystem = { ...window.StarSystem, Vue, registerHookComponent };
