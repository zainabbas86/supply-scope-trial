import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

// Vitest does not unmount between tests on its own; without this, a component
// from a previous test is still in the document and queries match the wrong one.
afterEach(() => cleanup());
