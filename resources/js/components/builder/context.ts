import { inject, provide } from 'vue';
import type { InjectionKey } from 'vue';
import type { ModelBuilder } from '@/composables/useModelBuilder';

export type BuilderContext = {
    builder: ModelBuilder;
    /** Reads a message to screen reader users, e.g. after a move. */
    announce: (message: string) => void;
};

const key: InjectionKey<BuilderContext> = Symbol('model-builder');

export function provideBuilder(context: BuilderContext) {
    provide(key, context);
}

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
