'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const appPath = path.resolve(__dirname, '../../pwa/app.js');

function fail(message) {
    console.error(message);
    process.exit(1);
}

function assert(cond, message) {
    if (!cond) {
        fail(message);
    }
}

function extractFunction(source, name) {
    const start = source.indexOf(`function ${name}(`);
    if (start < 0) {
        fail(`missing function ${name}`);
    }
    let open = source.indexOf('{', start);
    let depth = 0;
    for (let i = open; i < source.length; i += 1) {
        const ch = source[i];
        if (ch === '{') {
            depth += 1;
        } else if (ch === '}') {
            depth -= 1;
            if (depth === 0) {
                return source.slice(start, i + 1);
            }
        }
    }
    fail(`unclosed function ${name}`);
}

function loadChartWindow(source) {
    const handle = source.match(/const BRUSH_HANDLE_PX = (\d+);/);
    const narrow = source.match(/const BRUSH_NARROW_PX = (\d+);/);
    if (!handle || !narrow) {
        fail('brush constants missing');
    }
    const code = [
        `const BRUSH_HANDLE_PX = ${handle[1]};`,
        `const BRUSH_NARROW_PX = ${narrow[1]};`,
        extractFunction(source, 'brushHit'),
        extractFunction(source, 'windowShift'),
    ].join('\n');
    const sandbox = {};
    vm.createContext(sandbox);
    vm.runInContext(code, sandbox);
    return sandbox;
}

function main() {
    const source = fs.readFileSync(appPath, 'utf8');
    const { brushHit, windowShift } = loadChartWindow(source);
    const viewStart = 1_000_000;
    const viewEnd = 2_000_000;

    // A wide selector keeps edge handles and a move body.
    assert(brushHit(250, 100, 400, viewStart + 10, viewStart, viewEnd).mode === 'move', 'wide center moves');
    assert(brushHit(105, 100, 400, viewStart, viewStart, viewEnd).mode === 'start', 'wide left edge resizes start');
    assert(brushHit(395, 100, 400, viewEnd, viewStart, viewEnd).mode === 'end', 'wide right edge resizes end');
    assert(brushHit(90, 100, 400, viewStart - 10, viewStart, viewEnd).mode === 'start', 'wide outside left handle resizes start');
    assert(brushHit(50, 100, 400, viewStart - 100, viewStart, viewEnd).mode === 'jump', 'wide outside jumps');

    // A few pixels wide (long history, short window): dragging the body moves.
    // The old 10px handles covered this whole selector and resized it instead.
    assert(brushHit(102, 100, 104, viewStart, viewStart, viewEnd).mode === 'move', 'narrow center moves');
    assert(brushHit(98, 100, 104, viewStart, viewStart, viewEnd).mode === 'move', 'narrow drawn handle moves');
    assert(brushHit(94, 100, 104, viewStart, viewStart, viewEnd).mode === 'move', 'narrow pad edge moves');
    assert(brushHit(93, 100, 104, viewStart - 1, viewStart, viewEnd).mode === 'start', 'just outside a narrow window resizes start');
    assert(brushHit(112, 100, 104, viewEnd + 1, viewStart, viewEnd).mode === 'end', 'just outside a narrow window resizes end');
    assert(brushHit(130, 100, 104, viewEnd + 50, viewStart, viewEnd).mode === 'jump', 'far from a narrow window jumps');
    assert(brushHit(500, 100, 104, viewStart + 1, viewStart, viewEnd).mode === 'move', 'time inside a narrow window still moves');

    assert(brushHit(10, Number.NaN, 40, viewStart, viewStart, viewEnd).mode === 'move', 'bad pixels with time inside moves');
    assert(brushHit(10, Number.NaN, 40, viewStart - 5, viewStart, viewEnd).mode === 'jump', 'bad pixels outside jumps');

    const nudged = windowShift(0, 10 * 60 * 1000, 1, 0.1, 60 * 1000);
    assert(nudged.start === 60 * 1000 && nudged.end === 11 * 60 * 1000, 'short window shifts by at least a minute');
    const hour = 60 * 60 * 1000;
    const panned = windowShift(0, 10 * hour, -1, 0.1, 60 * 1000);
    assert(panned.start === -hour && panned.end === 9 * hour, 'left arrow shifts back by 10%');
    const paged = windowShift(0, 10 * hour, 1, 0.5, 60 * 1000);
    assert(paged.start === 5 * hour && paged.end === 15 * hour, 'shift-arrow shifts by half the window');
    assert(paged.end - paged.start === 10 * hour, 'shift keeps the window width');

    console.log('brush-hit: ok');
}

main();
