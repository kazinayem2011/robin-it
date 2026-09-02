import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach, vi } from 'vitest';

// Unmount between tests so a component left mounted cannot keep firing timers
// or requests into the next one — exactly the failure mode these tests exist
// to catch.
afterEach(() => {
    cleanup();
});

// jsdom does not implement these, and components that use them would otherwise
// throw during render rather than being tested.
Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: vi.fn().mockImplementation((query) => ({
        matches: false,
        media: query,
        onchange: null,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
    })),
});

window.scrollTo = vi.fn();

/*
 * jsdom in this configuration exposes no Storage at all, so anything reading
 * localStorage sees `undefined` rather than an empty store — which is not what
 * a browser does, and would let a test pass on the error path while the real
 * thing takes the happy one. A minimal in-memory implementation, fresh for
 * each file.
 */
const memoryStorage = () => {
    let items = new Map();

    return {
        getItem: (key) => (items.has(key) ? items.get(key) : null),
        setItem: (key, value) => items.set(String(key), String(value)),
        removeItem: (key) => items.delete(key),
        clear: () => items.clear(),
        key: (i) => [...items.keys()][i] ?? null,
        get length() {
            return items.size;
        },
    };
};

if (!window.localStorage) {
    Object.defineProperty(window, 'localStorage', {
        writable: true,
        configurable: true,
        value: memoryStorage(),
    });
}

if (!window.sessionStorage) {
    Object.defineProperty(window, 'sessionStorage', {
        writable: true,
        configurable: true,
        value: memoryStorage(),
    });
}

global.IntersectionObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
};
