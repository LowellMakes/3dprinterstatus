'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'index.php'), 'utf8');
const inlineScripts = [...source.matchAll(/<script>([\s\S]*?)<\/script>/g)];
assert.equal(inlineScripts.length, 1, 'expected one inline dashboard script');
new vm.Script(inlineScripts[0][1], {filename: 'index.php:inline-script'});
console.log('Dashboard JavaScript syntax test passed');
