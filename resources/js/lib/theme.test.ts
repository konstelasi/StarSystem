/// <reference types="node" />
// Read from disk: Vitest empties CSS imports, `?raw` included.
import { readFileSync } from 'node:fs';
import { expect, test } from 'vite-plus/test';

/*
  Holds every text colour to WCAG AA (4.5:1) against every surface it can
  sit on, in both themes, the same check the website runs on its tokens.

  The colours are read straight out of resources/css/app.css, so a palette
  change is checked without this file changing. Only the pairings below are
  this test's opinion.
*/

const AA_NORMAL = 4.5;

const css = readFileSync(new URL('../../css/app.css', import.meta.url), 'utf8');

/** Every `--token: #rrggbb;` in the first block `opener` matches. */
function readBlock(opener: RegExp): Map<string, string> {
    const start = css.search(opener);

    if (start < 0) {
        throw new Error(`No block matching ${opener} in app.css.`);
    }

    const body = css.slice(start, css.indexOf('}', start));
    const tokens = new Map<string, string>();

    for (const match of body.matchAll(
        /--([a-z0-9-]+):\s*(#[0-9a-fA-F]{6})\s*;/g,
    )) {
        tokens.set(match[1], match[2]);
    }

    return tokens;
}

function luminance(hex: string): number {
    const [r, g, b] = [1, 3, 5].map((i) => {
        const value = parseInt(hex.slice(i, i + 2), 16) / 255;

        return value <= 0.03928
            ? value / 12.92
            : Math.pow((value + 0.055) / 1.055, 2.4);
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function ratio(a: string, b: string): number {
    const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x);

    return (light + 0.05) / (dark + 0.05);
}

const THEMES: [string, Map<string, string>][] = [
    ['light', readBlock(/^:root\s*{/m)],
    ['dark', readBlock(/^\.dark\s*{/m)],
];

/** Every surface page text can land on. */
const SURFACES = ['background', 'card', 'muted', 'secondary', 'accent'];

/** Every token used as a text colour on those surfaces. */
const TEXT = ['foreground', 'muted-foreground', 'primary', 'destructive'];

/** Text that only ever sits on one particular fill. */
const FILLED: [string, string][] = [
    ['primary-foreground', 'primary'],
    ['destructive-foreground', 'destructive'],
    ['sidebar-foreground', 'sidebar-background'],
    ['sidebar-accent-foreground', 'sidebar-accent'],
    ['sidebar-primary-foreground', 'sidebar-primary'],
];

const PAIRS: [string, string][] = [
    ...TEXT.flatMap((text) =>
        SURFACES.map((surface): [string, string] => [text, surface]),
    ),
    ...FILLED,
];

for (const [theme, tokens] of THEMES) {
    test(`every ${theme} text colour passes 4.5:1 on its surfaces`, () => {
        const failures: string[] = [];

        for (const [text, surface] of PAIRS) {
            const foreground = tokens.get(text);
            const background = tokens.get(surface);

            if (!foreground || !background) {
                failures.push(`--${text} or --${surface} is missing`);
                continue;
            }

            const contrast = ratio(foreground, background);

            if (contrast < AA_NORMAL) {
                failures.push(
                    `--${text} ${foreground} on --${surface} ${background} is ${contrast.toFixed(2)}:1`,
                );
            }
        }

        expect(failures).toEqual([]);
    });
}
