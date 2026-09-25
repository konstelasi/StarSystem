import { inject, provide } from 'vue';
import type { InjectionKey } from 'vue';
import type { ModelBuilder } from '@/composables/useModelBuilder';
import type { FieldStates } from '@/lib/modelSchema';
import type { FieldState } from '@/types/schema';

export type BuilderContext = {
    builder: ModelBuilder;
    /** Reads a message to screen reader users, e.g. after a move. */
    announce: (message: string) => void;
    /** Busy state per field uuid, for badges and disabled actions. */
    states: FieldStates;
    /** The model this page edits, for building preview/save URLs. */
    modelId: number;
};

const key: InjectionKey<BuilderContext> = Symbol('model-builder');

export function provideBuilder(context: BuilderContext) {
    provide(key, context);
}

/** A field's busy state. Fields not in `states` are ready. */
export function stateOf(
    context: BuilderContext,
    fieldUuid: string,
): FieldState {
    return context.states[fieldUuid] ?? 'ready';
}

export const FIELD_STATE_LABELS: Record<
    Exclude<FieldState, 'ready'>,
    string
> = {
    indexing: 'Indexing…',
    renaming: 'Renaming…',
    retyping: 'Converting…',
    deleting: 'Deleting…',
    waiting: 'Queued…',
};

export function useBuilderContext(): BuilderContext {
    const context = inject(key);

    if (context === undefined) {
        throw new Error('Builder components must be used inside the builder.');
    }

    return context;
}

/** Where a field or block sits, in words: "position 2 of 5". */
export function positionText(position: { index: number; total: number }) {
    return `position ${position.index + 1} of ${position.total}`;
}
