import { router } from '@inertiajs/vue3';
import { onMounted, onUnmounted } from 'vue';
import type { Ref } from 'vue';

export const LEAVE_MESSAGE = 'You have unsaved changes. Leave without saving?';

type VisitShape = {
    method: string;
    only: string[];
    except: string[];
    prefetch: boolean;
};

/**
 * Whether a visit leaves the page. Posts (saving, submitting), partial
 * reloads (polling, previews) and hover prefetches all keep the page, so
 * they never ask.
 */
export function leavesPage(visit: VisitShape): boolean {
    return (
        visit.method === 'get' &&
        !visit.prefetch &&
        visit.only.length === 0 &&
        visit.except.length === 0
    );
}

/**
 * Asks before a link, redirect, Back or Forward, or tab close throws away
 * unsaved work. In-app navigation gets the browser's confirm box; closing
 * or reloading the tab gets the browser's own leave prompt, which can't be
 * styled.
 */
export function useLeaveGuard(
    isDirty: Readonly<Ref<boolean>>,
    message: string = LEAVE_MESSAGE,
) {
    const removers: (() => void)[] = [];

    // This page's history entry as Inertia last wrote it, to put back when
    // the user stays after pressing Back or Forward. By the time popstate
    // fires, the browser has already moved to the other entry.
    let entry = { state: null as unknown, url: '' };
    const remember = () => {
        entry = { state: window.history.state, url: window.location.href };
    };

    function onBeforeUnload(event: BeforeUnloadEvent) {
        if (isDirty.value) {
            event.preventDefault();
        }
    }

    /**
     * Back and Forward don't go through Inertia's `before` event: Inertia
     * restores the other page straight from its own popstate listener. A
     * capture listener on window runs before that one, so it can ask first
     * and, when the user stays, stop Inertia from switching pages.
     */
    function onPopState(event: PopStateEvent) {
        if (!isDirty.value || window.confirm(message)) {
            return;
        }

        event.stopImmediatePropagation();
        window.history.pushState(entry.state, '', entry.url);
    }

    onMounted(() => {
        remember();

        removers.push(
            router.on('before', (event) => {
                if (
                    isDirty.value &&
                    leavesPage(event.detail.visit) &&
                    !window.confirm(message)
                ) {
                    return false;
                }
            }),
            // Partial reloads (the builder's badge poll) rewrite the entry.
            router.on('success', remember),
        );

        window.addEventListener('beforeunload', onBeforeUnload);
        window.addEventListener('popstate', onPopState, { capture: true });
    });

    onUnmounted(() => {
        removers.forEach((remove) => remove());
        window.removeEventListener('beforeunload', onBeforeUnload);
        window.removeEventListener('popstate', onPopState, { capture: true });
    });
}
