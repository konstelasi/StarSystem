import Sortable from 'sortablejs';
import type { SortableEvent } from 'sortablejs';
import { onBeforeUnmount, onMounted } from 'vue';
import type { Ref } from 'vue';

/**
 * Vue owns the DOM of every list, so after a drop the element SortableJS
 * moved goes straight back where it came from, and the builder state is
 * updated instead. Vue then re-renders the list in the new order.
 */
export type SortableDrop = {
    /** The dropped element's `data-id`. */
    id: string;
    /** The target list's `data-list` value. */
    to: string;
    /** Position among the target list's draggable items. */
    index: number;
};

type Options = {
    group: Sortable.GroupOptions;
    /** Selector for the drag handle; the whole item when omitted. */
    handle?: string;
    sort?: boolean;
    onDrop: (drop: SortableDrop) => void;
};

export const DRAGGABLE = '[data-sortable-item]';

export function useSortable(el: Ref<HTMLElement | null>, options: Options) {
    let instance: Sortable | null = null;
    let restoreBefore: Node | null = null;

    const onStart = (event: SortableEvent) => {
        restoreBefore = event.item.nextSibling;
    };

    const onEnd = (event: SortableEvent) => {
        const { item, from, to, clone, pullMode } = event;
        const index = event.newDraggableIndex ?? 0;

        if (from === to && index === event.oldDraggableIndex) {
            return;
        }

        if (pullMode === 'clone') {
            // The original went to the target list; a copy took its place.
            clone.replaceWith(item);
        } else {
            from.insertBefore(item, restoreBefore);
        }

        options.onDrop({
            id: item.dataset.id ?? '',
            to: to.dataset.list ?? '',
            index,
        });
    };

    onMounted(() => {
        if (el.value === null) {
            return;
        }

        instance = Sortable.create(el.value, {
            group: options.group,
            sort: options.sort ?? true,
            handle: options.handle,
            draggable: DRAGGABLE,
            animation: 150,
            emptyInsertThreshold: 24,
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            onStart,
            onEnd,
        });
    });

    onBeforeUnmount(() => {
        instance?.destroy();
        instance = null;
    });
}
