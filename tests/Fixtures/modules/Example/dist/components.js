// Prebuilt, as a module ships it: no bundler on the host, and Vue comes
// from the admin through window.StarSystem rather than an import.
const { defineComponent, h } = window.StarSystem.Vue;

export const ExampleNotice = defineComponent({
    props: { message: { type: String, required: true } },
    setup(props) {
        return () =>
            h(
                'p',
                {
                    class: 'rounded-md border px-3 py-2 text-sm text-muted-foreground',
                },
                props.message,
            );
    },
});
