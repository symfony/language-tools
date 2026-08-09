#!/usr/bin/env node

import fs from 'node:fs/promises';

const [, , port, action, ...arguments_] = process.argv;
if (!port || !action) {
    throw new Error('Usage: vscode-guide-cdp.mjs PORT ACTION [ARGUMENTS]');
}

const pages = await fetch(`http://127.0.0.1:${port}/json/list`).then((response) => response.json());
const page = pages.find((candidate) => 'page' === candidate.type && candidate.title && !candidate.title.startsWith('Welcome'))
    ?? pages.find((candidate) => 'page' === candidate.type);
if (!page) {
    throw new Error('No VS Code page target found');
}

const socket = new WebSocket(page.webSocketDebuggerUrl);
const pending = new Map();
let sequence = 0;

socket.addEventListener('message', (event) => {
    const message = JSON.parse(event.data);
    if (!message.id || !pending.has(message.id)) {
        return;
    }

    const { resolve, reject } = pending.get(message.id);
    pending.delete(message.id);
    if (message.error) {
        reject(new Error(JSON.stringify(message.error)));
    } else {
        resolve(message.result);
    }
});

await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
});

function send(method, params = {}) {
    const id = ++sequence;
    socket.send(JSON.stringify({ id, method, params }));

    return new Promise((resolve, reject) => pending.set(id, { resolve, reject }));
}

async function evaluate(expression) {
    const result = await send('Runtime.evaluate', {
        expression,
        awaitPromise: true,
        returnByValue: true,
    });
    if (result.exceptionDetails) {
        throw new Error(result.exceptionDetails.text);
    }

    return result.result.value;
}

async function waitFor(expression, timeoutSeconds) {
    const deadline = Date.now() + timeoutSeconds * 1000;
    while (Date.now() < deadline) {
        if (await evaluate(expression)) {
            return;
        }
        await new Promise((resolve) => setTimeout(resolve, 200));
    }

    throw new Error(`Timed out waiting for: ${expression}`);
}

async function screenshot(path) {
    const result = await send('Page.captureScreenshot', {
        format: 'png',
        fromSurface: true,
        captureBeyondViewport: false,
    });
    await fs.writeFile(path, Buffer.from(result.data, 'base64'));
}

if ('evaluate' === action) {
    console.log(JSON.stringify(await evaluate(arguments_[0]), null, 2));
} else if ('wait' === action) {
    await waitFor(arguments_[0], Number(arguments_[1] ?? 30));
} else if ('screenshot' === action) {
    await screenshot(arguments_[0]);
} else if ('wait-screenshot' === action) {
    await waitFor(arguments_[0], Number(arguments_[2] ?? 30));
    await screenshot(arguments_[1]);
} else if ('key' === action) {
    const [modifiers, key, code, virtualKeyCode] = arguments_;
    const params = {
        modifiers: Number(modifiers),
        key,
        code,
        windowsVirtualKeyCode: Number(virtualKeyCode),
    };
    await send('Input.dispatchKeyEvent', { type: 'keyDown', ...params });
    await send('Input.dispatchKeyEvent', { type: 'keyUp', ...params });
} else if ('text' === action) {
    await send('Input.insertText', { text: arguments_[0] });
} else if ('mouse' === action) {
    await send('Input.dispatchMouseEvent', {
        type: 'mouseMoved',
        x: Number(arguments_[0]),
        y: Number(arguments_[1]),
    });
} else if ('close' === action) {
    const closed = new Promise((resolve) => socket.addEventListener('close', resolve, { once: true }));
    await Promise.race([send('Browser.close'), closed]);
} else {
    throw new Error(`Unknown action: ${action}`);
}

socket.close();
