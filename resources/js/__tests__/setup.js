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

global.IntersectionObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
};
