#!/usr/bin/env node

import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const guide = path.join(root, 'docs/vscode-guide');
const reference = await fs.readFile(path.join(root, 'docs/features/index.rst'), 'utf8');
const catalog = await fs.readFile(path.join(guide, 'features.html'), 'utf8');
const gettingStarted = await fs.readFile(path.join(guide, 'index.html'), 'utf8');
const stylesheet = await fs.readFile(path.join(guide, 'guide.css'), 'utf8');
const captureScript = await fs.readFile(path.join(root, 'tools/capture-vscode-guide'), 'utf8');

const referenceSection = reference.split('Supported Integrations\n----------------------')[1]?.split('Runtime Indexing and Trust')[0];
if (!referenceSection) {
    throw new Error('Unable to find the supported integration matrix');
}

const referenceRows = [...referenceSection.matchAll(/^    \* - (.+)\n((?:      - (?:Yes|No)\n){6})/gm)].map((match) => ({
    name: match[1].replace(/:doc:`(.+?)(?: <.+?>)?`/g, '$1'),
    support: [...match[2].matchAll(/^      - (Yes|No)$/gm)].map((cell) => 'Yes' === cell[1]),
}));
const catalogTable = catalog.match(/<table class="coverage-table">([\s\S]+?)<\/table>/)?.[1];
if (!catalogTable) {
    throw new Error('Unable to find the visual coverage matrix');
}

const catalogRows = [...catalogTable.matchAll(/<tr><td>(.+?)<\/td>([\s\S]+?)<\/tr>/g)].map((match) => {
    const targets = [...match[2].matchAll(/class="coverage-yes" href="#(.+?)"/g)].map((link) => link[1]);

    return {
        name: match[1],
        target: targets[0],
        targets,
        support: [...match[2].matchAll(/class="(coverage-yes|coverage-no)"/g)].map((cell) => 'coverage-yes' === cell[1]),
    };
});
if (13 !== referenceRows.length || referenceRows.length !== catalogRows.length) {
    throw new Error(`Expected 13 matching integration rows, found ${referenceRows.length} reference and ${catalogRows.length} visual rows`);
}

const capabilityNames = ['Completion', 'Hover', 'Definition', 'References', 'Rename', 'Diagnostics'];
for (const [index, row] of referenceRows.entries()) {
    const visual = catalogRows[index];
    const card = catalog.match(new RegExp(`<article id="${visual.target}" class="integration-card">([\\s\\S]+?)<\\/article>`))?.[1];
    const cardCapabilities = card ? [...card.matchAll(/<li class="capability">(.+?)<\/li>/g)].map((match) => match[1]) : [];
    const expectedCapabilities = capabilityNames.filter((_, capability) => row.support[capability]);
    if (
        row.name !== visual.name
        || 6 !== visual.support.length
        || row.support.join(',') !== visual.support.join(',')
        || visual.targets.length !== visual.support.filter(Boolean).length
        || visual.targets.some((target) => target !== visual.target)
        || cardCapabilities.join(',') !== expectedCapabilities.join(',')
    ) {
        throw new Error(`Visual coverage differs from the reference matrix at ${visual.name}`);
    }
}

const supported = catalogRows.flatMap((row) => row.support).filter(Boolean).length;
if (65 !== supported) {
    throw new Error(`Expected 65 supported visual combinations, found ${supported}`);
}

const referencedImages = new Set(
    [...`${gettingStarted}\n${catalog}`.matchAll(/(?:src|href)="images\/(.+?\.webp)"/g)].map((match) => match[1]),
);
const imageDirectory = path.join(guide, 'images');
const imageFiles = (await fs.readdir(imageDirectory)).filter((file) => file.endsWith('.webp')).sort();
const missing = [...referencedImages].filter((file) => !imageFiles.includes(file));
const unused = imageFiles.filter((file) => !referencedImages.has(file));
if (0 < missing.length || 0 < unused.length) {
    throw new Error(`Image mismatch. Missing: ${missing.join(', ') || 'none'}. Unused: ${unused.join(', ') || 'none'}`);
}
if (30 !== imageFiles.length) {
    throw new Error(`Expected 30 visual captures, found ${imageFiles.length}`);
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
if (!gettingStarted.includes('<img src="images/install-extension.webp" width="1440" height="480"')) {
    throw new Error('The installation screenshot must use its compact dimensions');
}

for (const selector of ['.workflow-grid', '.integration-card', '.gallery-pair']) {
    const escapedSelector = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    const rules = [...stylesheet.matchAll(new RegExp(`${escapedSelector}\\s*\\{([^}]*)}`, 'g'))];
    if (!rules.some((rule) => rule[1].includes('grid-template-columns: minmax(0, 1fr)'))) {
        throw new Error(`${selector} must keep screenshots in a readable single-column layout`);
    }
}

console.log(`Visual guide covers ${referenceRows.length} integrations, ${supported} supported combinations and ${imageFiles.length} captures.`);
