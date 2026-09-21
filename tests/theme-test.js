'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const themeSource = fs.readFileSync(path.join(__dirname, '..', 'theme.js'), 'utf8');

function runTheme({savedTheme = null, systemDark = false, storageThrows = false} = {}) {
    const writes = [];
    const listeners = new Map();
    const root = {dataset: {theme: 'light'}};
    const controls = ['light', 'dark'].map((themeOption) => ({
        dataset: {themeOption},
        pressed: null,
        active: false,
        classList: {
            toggle(name, value) {
                assert.equal(name, 'is-active');
                this.owner.active = value;
            },
            owner: null,
        },
        setAttribute(name, value) {
            assert.equal(name, 'aria-pressed');
            this.pressed = value;
        },
        addEventListener(name, callback) {
            assert.equal(name, 'click');
            listeners.set(themeOption, callback);
        },
    }));
    controls.forEach((control) => {
        control.classList.owner = control;
    });

    const context = {
        document: {
            documentElement: root,
            querySelectorAll(selector) {
                assert.equal(selector, '[data-theme-option]');
                return controls;
            },
            addEventListener(name, callback) {
                assert.equal(name, 'DOMContentLoaded');
                callback();
            },
        },
        localStorage: {
            getItem(key) {
                assert.equal(key, '3dprinterstatus-theme');
                if (storageThrows) {
                    throw new Error('storage unavailable');
                }
                return savedTheme;
            },
            setItem(key, value) {
                assert.equal(key, '3dprinterstatus-theme');
                if (storageThrows) {
                    throw new Error('storage unavailable');
                }
                writes.push(value);
            },
        },
        matchMedia(query) {
            assert.equal(query, '(prefers-color-scheme: dark)');
            return {matches: systemDark};
        },
    };

    vm.runInNewContext(themeSource, context, {filename: 'theme.js'});
    return {controls, listeners, root, writes};
}

const persisted = runTheme({savedTheme: 'dark'});
assert.equal(persisted.root.dataset.theme, 'dark');
assert.equal(persisted.controls[0].pressed, 'false');
assert.equal(persisted.controls[1].pressed, 'true');
persisted.listeners.get('light')();
assert.equal(persisted.root.dataset.theme, 'light');
assert.deepEqual(persisted.writes, ['light']);

const systemPreference = runTheme({systemDark: true});
assert.equal(systemPreference.root.dataset.theme, 'dark');

const unavailableStorage = runTheme({systemDark: false, storageThrows: true});
assert.equal(unavailableStorage.root.dataset.theme, 'light');
unavailableStorage.listeners.get('dark')();
assert.equal(unavailableStorage.root.dataset.theme, 'dark');

console.log('Shared theme behavior test passed');
