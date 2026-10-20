import { describe, expect, it } from 'vite-plus/test';
import { toUrl } from './utils';

describe('toUrl', () => {
    it('returns a string href unchanged', () => {
        expect(toUrl('/dashboard')).toBe('/dashboard');
    });

    it('returns the url of a Wayfinder route definition', () => {
        expect(toUrl({ url: '/settings/profile', method: 'get' })).toBe(
            '/settings/profile',
        );
    });
});
