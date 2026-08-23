#!/usr/bin/env node

import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const reference = await fs.readFile(path.join(root, 'docs/features/index.rst'), 'utf8');
const page = await fs.readFile(path.join(root, 'editor/vscode/MARKETPLACE.md'), 'utf8');
const manifest = JSON.parse(await fs.readFile(path.join(root, 'editor/vscode/package.json'), 'utf8'));
const packageIgnore = await fs.readFile(path.join(root, 'editor/vscode/.vscodeignore'), 'utf8');
const captureScript = await fs.readFile(path.join(root, 'tools/capture-vscode-guide'), 'utf8');
const tourScript = await fs.readFile(path.join(root, 'tools/generate-vscode-guide-tour'), 'utf8');

const referenceSection = reference.split('Supported Integrations\n----------------------')[1]?.split('Runtime Indexing and Trust')[0];
if (!referenceSection) {
    throw new Error('Unable to find the supported integration matrix');
}

const referenceRows = [...referenceSection.matchAll(/^    \* - (.+)\n((?:      - (?:Yes|No)\n){6})/gm)].map((match) => ({
    name: match[1].replace(/^`(.+)`_$/, '$1'),
    support: [...match[2].matchAll(/^      - (Yes|No)$/gm)].map((cell) => 'Yes' === cell[1]),
}));
const pageTable = page.split('| Integration | Completion | Hover | Definition | References | Rename | Diagnostics |')[1]?.split('\n\n')[0];
if (!pageTable) {
    throw new Error('Unable to find the integration matrix in the Marketplace overview');
}

const pageRows = [...pageTable.matchAll(/^\| ([^|:-][^|]*) \|(.+)\|$/gm)].map((match) => ({
    name: match[1].trim(),
    support: match[2].split('|').map((cell) => '✓' === cell.trim()),
}));
if (14 !== referenceRows.length || referenceRows.length !== pageRows.length) {
    throw new Error(`Expected 14 matching integration rows, found ${referenceRows.length} reference and ${pageRows.length} overview rows`);
}

for (const [index, row] of referenceRows.entries()) {
    const overview = pageRows[index];
    if (
        row.name !== overview.name
        || 6 !== overview.support.length
        || row.support.join(',') !== overview.support.join(',')
    ) {
        throw new Error(`Marketplace coverage differs from the reference matrix at ${overview.name}`);
    }
}

const supported = pageRows.flatMap((row) => row.support).filter(Boolean).length;
if (70 !== supported) {
    throw new Error(`Expected 70 supported combinations, found ${supported}`);
}

const tourSlides = [...tourScript.matchAll(/^    "([a-z-]+)\|/gm)].map((match) => `${match[1]}.webp`);
const duplicateSlides = tourSlides.filter((slide, index) => tourSlides.indexOf(slide) !== index);
if (0 < duplicateSlides.length) {
    throw new Error(`Duplicate tour slides: ${duplicateSlides.join(', ')}`);
}
if (!page.includes('](images/guide/tour.gif)')) {
    throw new Error('The Marketplace overview must embed the tour GIF');
}
if (!page.includes('](https://github.com/symfony/language-tools/blob/main/docs/features/index.rst)')) {
    throw new Error('The Marketplace feature reference must link to the documentation index');
}
if (page.includes('code --install-extension symfony.language-tools') || tourScript.includes('code --install-extension symfony.language-tools')) {
    throw new Error('The Marketplace overview must use its Install button');
}
if (page.includes('publisher is **Symfony**') || tourScript.includes('Publisher: Symfony')) {
    throw new Error('The Marketplace overview must not ask users to verify its publisher');
}

const referencedImages = new Set([
    ...[...page.matchAll(/\]\(images\/guide\/(.+?\.webp)\)/g)].map((match) => match[1]),
    ...tourSlides,
]);
const imageDirectory = path.join(root, 'editor/vscode/images/guide');
const imageFiles = (await fs.readdir(imageDirectory)).filter((file) => file.endsWith('.webp')).sort();
const missing = [...referencedImages].filter((file) => !imageFiles.includes(file));
const unused = imageFiles.filter((file) => !referencedImages.has(file));
if (0 < missing.length || 0 < unused.length) {
    throw new Error(`Image mismatch. Missing: ${missing.join(', ') || 'none'}. Unused: ${unused.join(', ') || 'none'}`);
}
if (30 !== imageFiles.length) {
    throw new Error(`Expected 30 visual captures, found ${imageFiles.length}`);
}
const missingSlides = imageFiles.filter((file) => 'install-extension.webp' !== file && !tourSlides.includes(file));
if (0 < missingSlides.length) {
    throw new Error(`Captures missing from the video tour: ${missingSlides.join(', ')}`);
}

const captureTargets = ['install', 'demo', 'runtime'].flatMap((group) => {
    const targets = captureScript.match(new RegExp(`^${group}_targets=\\(([^)]+)\\)$`, 'm'))?.[1];
    if (!targets) {
        throw new Error(`Unable to find the ${group} screenshot targets`);
    }

    return targets.split(/\s+/);
});
const duplicateTargets = captureTargets.filter((target, index) => captureTargets.indexOf(target) !== index);
const targetImages = captureTargets.map((target) => `${target}.webp`).sort();
if (0 < duplicateTargets.length || targetImages.join(',') !== imageFiles.join(',')) {
    throw new Error(`Capture targets differ from visual guide images. Duplicates: ${duplicateTargets.join(', ') || 'none'}`);
}

if (!manifest.scripts.package.includes('--readme-path MARKETPLACE.md')) {
    throw new Error('The package script must publish MARKETPLACE.md as the Marketplace overview');
}
if (!manifest.scripts.package.includes(`--baseImagesUrl ${manifest.repository.url.replace(/\.git$/, '')}/raw/HEAD/editor/vscode`)) {
    throw new Error('The package script must resolve overview images against the repository extension directory');
}
if (!packageIgnore.split('\n').includes('images/guide/')) {
    throw new Error('The guide images must be excluded from the extension package');
}
if (!packageIgnore.split('\n').includes('images/blog/')) {
    throw new Error('The blog images must be excluded from the extension package');
}

console.log(`Marketplace overview covers ${referenceRows.length} integrations, ${supported} supported combinations, ${imageFiles.length} captures and ${tourSlides.length} tour slides.`);
