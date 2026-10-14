import type { FormDataConvertible } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useLeaveGuard } from '@/composables/useLeaveGuard';
import { formValue, initialValue } from '@/fields/values';
import type { ModelSchemaJson } from '@/types/schema';

/**
 * The state behind the new and edit entry pages: the values keyed by
 * field key, the server's errors per field, and the submit. Leaving with
 * unsaved changes asks first.
 *
 * `stored` is an existing entry's values as the server keeps them; without
 * it every field starts as a new entry's would.
 */
export function useEntryForm(
    schema: ModelSchemaJson,
    stored?: Record<string, unknown>,
) {
    const values = ref<Record<string, unknown>>(
        Object.fromEntries(
            schema.fields.map((field) => [
                field.key,
                stored
                    ? formValue(field, stored[field.key])
                    : initialValue(field),
            ]),
        ),
    );
    const errors = ref<Record<string, string>>({});
    const processing = ref(false);

    const initial = JSON.stringify(values.value);
    useLeaveGuard(computed(() => JSON.stringify(values.value) !== initial));

    function updateValue(key: string, value: unknown) {
        values.value[key] = value;
    }

    function submit(url: string, method: 'post' | 'put') {
        processing.value = true;

        router.visit(url, {
            method,
            data: {
                fields: values.value as Record<string, FormDataConvertible>,
            },
            preserveScroll: true,
            onError: (serverErrors) => {
                const mapped: Record<string, string> = {};

                for (const [key, message] of Object.entries(serverErrors)) {
                    if (key.startsWith('fields.')) {
                        mapped[key.slice('fields.'.length)] = message;
                    }
                }

                errors.value = mapped;
            },
            onFinish: () => {
                processing.value = false;
            },
        });
    }

    return { values, errors, processing, updateValue, submit };
}
