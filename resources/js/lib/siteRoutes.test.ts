import { expect, test } from 'vite-plus/test';
import {
    addressFields,
    addressFromSlug,
    entryAddress,
    newRoute,
    publishedFields,
    titleFields,
    withModel,
} from '@/lib/siteRoutes';
import type { SiteField, SiteModel } from '@/lib/siteRoutes';

const field = (
    uuid: string,
    type: string,
    changes: Partial<SiteField> = {},
): SiteField => ({
    uuid,
    label: uuid,
    type,
    filterable: false,
    required: false,
    indexed: true,
    ...changes,
});

const posts: SiteModel = {
    id: 3,
    label: 'Posts',
    slug: 'blog_posts',
    fields: [
        field('title', 'text'),
        field('slug', 'text', { filterable: true, required: true }),
        field('loose', 'text', { filterable: true }),
        field('plain', 'text', { required: true }),
        field('summary', 'textarea'),
        field('body', 'rich_text'),
        field('live', 'checkbox', { filterable: true }),
        field('draft', 'checkbox'),
    ],
};

test('only a required, filterable text field can hold an address', () => {
    expect(addressFields(posts).map((f) => f.uuid)).toEqual(['slug']);
    expect(addressFields(undefined)).toEqual([]);
});

test('a title can be text, a text area, an email or a link', () => {
    expect(titleFields(posts).map((f) => f.uuid)).toEqual([
        'title',
        'slug',
        'loose',
        'plain',
        'summary',
    ]);
});

test('a published switch is a filterable checkbox', () => {
    expect(publishedFields(posts).map((f) => f.uuid)).toEqual(['live']);
});

test('a model slug becomes an address of lowercase words and single hyphens', () => {
    expect(addressFromSlug('blog_posts')).toBe('blog-posts');
    expect(addressFromSlug('News & Events')).toBe('news-events');
    expect(addressFromSlug('__x__')).toBe('x');
    expect(addressFromSlug('a'.repeat(80))).toHaveLength(64);
});

test('a new row starts at an address named after the model, with the first fields that fit', () => {
    expect(newRoute(posts, [])).toEqual({
        model_id: 3,
        path: 'blog-posts',
        slug_field: 'slug',
        title_field: 'title',
        published_field: null,
        list: false,
        home_slug: null,
        label: 'Posts',
    });
});

test('a new row takes no address that another row has', () => {
    expect(newRoute(posts, ['blog-posts']).path).toBe('');
});

test('a model with no field that can hold an address leaves it for the person to fix', () => {
    const bare: SiteModel = { id: 4, label: 'Bare', slug: 'bare', fields: [] };

    expect(newRoute(bare, []).slug_field).toBeNull();
    expect(newRoute(bare, []).title_field).toBeNull();
});

test('choosing another model swaps the fields and follows the label only while it was the old name', () => {
    const pages: SiteModel = {
        id: 5,
        label: 'Pages',
        slug: 'pages',
        fields: [
            field('s', 'text', { filterable: true, required: true }),
            field('t', 'text'),
        ],
    };
    const row = { ...newRoute(posts, []), published_field: 'live', list: true };

    expect(withModel(row, pages, posts)).toMatchObject({
        model_id: 5,
        slug_field: 's',
        title_field: 's',
        published_field: null,
        label: 'Pages',
        list: true,
        path: 'blog-posts',
    });
    expect(withModel({ ...row, label: 'Journal' }, pages, posts).label).toBe(
        'Journal',
    );
});

test('the line under the address shows where an entry will appear', () => {
    const row = newRoute(posts, []);

    expect(entryAddress(row)).toBe('/blog-posts/address');
    expect(entryAddress({ ...row, path: '' })).toBe('/address');
});
