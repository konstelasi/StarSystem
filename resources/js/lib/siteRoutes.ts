/**
 * The Site page's address table: which models the site publishes, and where.
 * These are the choices a row can make, kept apart from the page so the
 * rules are testable. The server checks every row again when it is saved.
 */

export type SiteField = {
    uuid: string;
    label: string;
    type: string;
    filterable: boolean;
    required: boolean;
    /** Whether the database has finished preparing the field for searching. */
    indexed: boolean;
};

export type SiteModel = {
    id: number;
    label: string;
    slug: string;
    fields: SiteField[];
};

export type SiteRoute = {
    model_id: number | null;
    path: string;
    slug_field: string | null;
    title_field: string | null;
    published_field: string | null;
    list: boolean;
    home_slug: string | null;
    label: string;
};

/** Field types that can name an entry on a page. Mirrors FrontendSettings::TITLE_TYPES. */
export const TITLE_TYPES = ['text', 'textarea', 'email', 'url'];

/** Fields that can hold an entry's address: required, filterable text. */
export function addressFields(model: SiteModel | undefined): SiteField[] {
    return (model?.fields ?? []).filter(
        (field) => field.type === 'text' && field.filterable && field.required,
    );
}

export function titleFields(model: SiteModel | undefined): SiteField[] {
    return (model?.fields ?? []).filter((field) =>
        TITLE_TYPES.includes(field.type),
    );
}

/** Checkbox fields that can switch an entry on and off. */
export function publishedFields(model: SiteModel | undefined): SiteField[] {
    return (model?.fields ?? []).filter(
        (field) => field.type === 'checkbox' && field.filterable,
    );
}

/** Whether a field is picked in a row but not yet usable. */
export function stillIndexing(field: SiteField): boolean {
    return !field.indexed;
}

/**
 * A model's slug as an address: lowercase words joined by single hyphens,
 * which is what an address can be.
 */
export function addressFromSlug(slug: string): string {
    return slug
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 64);
}

/**
 * A new row for a model. It starts at an address named after the model,
 * unless another row has that, with the first fields that can do each job.
 */
export function newRoute(
    model: SiteModel,
    takenPaths: readonly string[],
): SiteRoute {
    const path = addressFromSlug(model.slug);

    return {
        model_id: model.id,
        path: takenPaths.includes(path) ? '' : path,
        slug_field: addressFields(model)[0]?.uuid ?? null,
        title_field: titleFields(model)[0]?.uuid ?? null,
        published_field: null,
        list: false,
        home_slug: null,
        label: model.label,
    };
}

/**
 * The row with another model chosen: the fields are the new model's, and
 * the menu label follows the model if it still carried the old one's name.
 */
export function withModel(
    route: SiteRoute,
    model: SiteModel,
    previous: SiteModel | undefined,
): SiteRoute {
    return {
        ...route,
        model_id: model.id,
        slug_field: addressFields(model)[0]?.uuid ?? null,
        title_field: titleFields(model)[0]?.uuid ?? null,
        published_field: null,
        label:
            route.label === '' || route.label === previous?.label
                ? model.label
                : route.label,
    };
}

/**
 * Where an entry of the row appears, for the line under the address.
 */
export function entryAddress(route: SiteRoute): string {
    return route.path === '' ? '/address' : `/${route.path}/address`;
}
