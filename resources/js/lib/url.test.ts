import { beforeEach, describe, expect, it } from 'vitest';

import { appUrl, getAppBase, setAppBase } from './url';

beforeEach(() => setAppBase(''));

describe('appUrl', () => {
    it('returns unprefixed paths when no base is set', () => {
        expect(appUrl('/documents')).toBe('/documents');
    });

    it('prefixes the configured base', () => {
        setAppBase('/labelextractionagent');

        expect(appUrl('/documents')).toBe('/labelextractionagent/documents');
        expect(appUrl('/login')).toBe('/labelextractionagent/login');
    });

    it('joins with exactly one slash however the parts are written', () => {
        setAppBase('/labelextractionagent/');

        // The base arrives from PHP and the path is written by hand, so
        // neither side can be trusted to agree about slashes.
        expect(appUrl('documents')).toBe('/labelextractionagent/documents');
        expect(appUrl('//documents')).toBe('/labelextractionagent/documents');
    });

    it('maps the app root to the base itself, not to base + slash', () => {
        setAppBase('/labelextractionagent');

        // '/labelextractionagent/' would be a redirect on every visit home.
        expect(appUrl('/')).toBe('/labelextractionagent');
        expect(appUrl()).toBe('/labelextractionagent');
    });

    it('falls back to the site root when there is no base and no path', () => {
        expect(appUrl('/')).toBe('/');
    });

    it('strips a trailing slash from the base once set', () => {
        setAppBase('/x/');

        expect(getAppBase()).toBe('/x');
    });

    it('names the real problem when the shared prop is missing', () => {
        // A stale server against a fresh bundle. Without this the symptom is
        // "undefined.replace is not a function" and a white page.
        expect(() => setAppBase(undefined as unknown as string)).toThrow(
            /appBase.*missing/i,
        );
    });
});
