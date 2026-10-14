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
 * Asks before a link, redirect or tab close throws away unsaved work.
 * In-app navigation gets the browser's confirm box; closing or reloading
 * the tab gets the browser's own leave prompt, which can't be styled.
 */
export function useLeaveGuard(
    isDirty: Readonly<Ref<boolean>>,
    message: string = LEAVE_MESSAGE,
) {
    let removeBefore: (() => void) | null = null;

    function onBeforeUnload(event: BeforeUnloadEvent) {
        if (isDirty.value) {
            event.preventDefault();
        }
    }

    onMounted(() => {
        removeBefore = router.on('before', (event) => {
            if (
                isDirty.value &&
                leavesPage(event.detail.visit) &&
                !window.confirm(message)
            ) {
                return false;
            }
        });

        window.addEventListener('beforeunload', onBeforeUnload);
    });

    onUnmounted(() => {
        removeBefore?.();
        window.removeEventListener('beforeunload', onBeforeUnload);
    });
}
