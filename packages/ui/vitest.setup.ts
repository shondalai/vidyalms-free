import '@testing-library/jest-dom/vitest';
import { afterEach } from 'vitest';
import { cleanup } from '@testing-library/react';
import { registerFreeQuestionTypes } from './src/question-types';

/*
 * The free bundle registers its six question type modules at load, before
 * any app mounts (see both main.tsx entries). A component test mounts a
 * screen without going through an entry, so the same registration happens
 * here: a suite that ran against an empty registry would exercise the
 * shape-keyed fallback everywhere and prove nothing about the six.
 */
registerFreeQuestionTypes();

afterEach(() => {
    cleanup();
});
