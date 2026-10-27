import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach, beforeEach, vi } from 'vitest';

/**
 * jsdom has no IntersectionObserver. Tests never rely on real intersection
 * calculations; they either use the scroll fallback or trigger an observer
 * explicitly through `MockIntersectionObserver.instances`.
 */
class MockIntersectionObserver {
  static instances = [];

  constructor(callback, options = {}) {
    this.callback = callback;
    this.options = options;
    this.elements = new Set();
    MockIntersectionObserver.instances.push(this);
  }

  observe(element) {
    this.elements.add(element);
  }

  unobserve(element) {
    this.elements.delete(element);
  }

  disconnect() {
    this.elements.clear();
  }

  takeRecords() {
    return [];
  }

  /** Simulate every observed element entering/leaving the viewport. */
  trigger(isIntersecting = true) {
    if (this.elements.size === 0) return;
    const entries = [...this.elements].map((target) => ({
      target,
      isIntersecting,
      intersectionRatio: isIntersecting ? 1 : 0,
    }));
    this.callback(entries, this);
    if (isIntersecting) {
      this.elements.clear();
    }
  }
}

globalThis.IntersectionObserver = MockIntersectionObserver;

// jsdom lacks requestAnimationFrame. Run the callback synchronously so tests
// can assert the resulting network calls without fake-timer bookkeeping.
globalThis.requestAnimationFrame = (callback) => {
  callback(performance.now());
  return 0;
};
globalThis.cancelAnimationFrame = () => {};

beforeEach(() => {
  MockIntersectionObserver.instances = [];
});

afterEach(() => {
  cleanup();
  document.getElementById('photo-count-stat')?.remove();
  document.body.style.overflow = '';
  vi.restoreAllMocks();
});
