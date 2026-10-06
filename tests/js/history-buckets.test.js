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
    let open = start;
    let parens = 0;
    let sawParen = false;
    for (; open < source.length; open += 1) {
        if (source[open] === '(') {
            parens += 1;
            sawParen = true;
        } else if (source[open] === ')') {
            parens -= 1;
            if (sawParen && parens === 0) {
                open += 1;
                break;
            }
        }
    }
    while (source[open] !== '{') {
        open += 1;
    }
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

function load(source) {
    const code = [
        'const MAX_BACKFILL_BUCKETS = 8640;',
        extractFunction(source, 'normalizeBucketIndex'),
        extractFunction(source, 'historyBuckets'),
        extractFunction(source, 'shouldReplayRewind'),
    ].join('\n');
    const sandbox = {};
    vm.createContext(sandbox);
    vm.runInContext(code, sandbox);
    return sandbox;
}

function main() {
    const { normalizeBucketIndex, historyBuckets, shouldReplayRewind } = load(fs.readFileSync(appPath, 'utf8'));
    const index = normalizeBucketIndex([300, 0, 300, 1.5, -4, '900', null]);
    assert(index.join() === '0,300', 'index keeps unique non-negative integers, sorted');
    assert(normalizeBucketIndex(null) === null, 'missing index is the probe path');

    const probed = historyBuckets({
        index: null,
        from: 0,
        lastFinal: 600,
        openBucket: 900,
        bucketSeconds: 300,
    });
    assert(probed.indexed === false, 'probe path is not indexed');
    assert(probed.buckets.join() === '0,300,600,900', 'probe path requests every slot and the open bucket');

    const listed = historyBuckets({
        index: [0, 300, 900, 1500],
        from: 300,
        lastFinal: 1200,
        openBucket: 1500,
        bucketSeconds: 300,
    });
    assert(listed.indexed === true, 'a bucket list is indexed');
    assert(listed.buckets.join() === '300,900,1500', 'index skips empty slots and still fetches the open bucket');

    const caughtUp = historyBuckets({
        index: [0, 300, 600],
        previousIndex: [0, 300],
        from: 900,
        lastFinal: 900,
        openBucket: 1200,
        bucketSeconds: 300,
    });
    assert(caughtUp.buckets.join() === '600,1200', 'a new id behind the checkpoint is fetched once');

    const recent = historyBuckets({
        index: [1000, 2000, 5000],
        previousIndex: null,
        from: 5000,
        lastFinal: 5000,
        openBucket: 5300,
        bucketSeconds: 300,
        recentFloor: 1000,
    });
    assert(recent.buckets.join() === '1000,2000,5000,5300', 'first index load includes the last day behind the checkpoint');

    const capped = historyBuckets({
        index: [100, 200, 300, 400],
        from: 0,
        lastFinal: 400,
        openBucket: 500,
        bucketSeconds: 100,
        coldStart: true,
        maxBuckets: 2,
    });
    assert(capped.buckets.join() === '300,400,500', 'cold start keeps the newest bucket files');

    assert(shouldReplayRewind(10, null, 0, null, 1000) === true, 'first gap rewinds');
    assert(shouldReplayRewind(10, 10, 500, 0, 1000) === false, 'the same gap does not rewind again inside the interval');
    assert(shouldReplayRewind(5, 10, 500, 0, 1000) === true, 'an older gap rewinds immediately');
    assert(shouldReplayRewind(10, 10, 1000, 0, 1000) === true, 'the same gap can rewind again after the interval');

    console.log('history-buckets: ok');
}

main();
