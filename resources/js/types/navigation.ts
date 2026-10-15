import type { InertiaLinkProps } from '@inertiajs/vue3';
import type { LucideIcon } from '@lucide/vue';

export type BreadcrumbItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    /** Active on any page under `href`, not only on `href` itself. */
    matchPrefix?: boolean;
    /** For an item no plain match fits: says from the current path. */
    activeWhen?: (path: string) => boolean;
};

export type NavGroup = {
    /** Left out for the group at the top, which needs no heading. */
    label?: string;
    items: NavItem[];
};
