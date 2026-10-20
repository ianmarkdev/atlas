import { describe, expect, it } from 'vite-plus/test';
import { greeting, timeAgo } from './time';

describe('timeAgo', () => {
    const now = new Date('2026-10-20T12:00:00Z');

    it('says "just now" for less than a minute', () => {
        expect(timeAgo('2026-10-20T11:59:30Z', now)).toBe('just now');
    });

    it('uses the largest whole unit', () => {
        expect(timeAgo('2026-10-20T09:00:00Z', now)).toMatch(/3 hours ago/);
        expect(timeAgo('2026-10-06T12:00:00Z', now)).toMatch(/2 weeks ago/);
    });
});

describe('greeting', () => {
    it('follows the time of day', () => {
        expect(greeting(new Date(2026, 9, 20, 8))).toBe('Good morning');
        expect(greeting(new Date(2026, 9, 20, 14))).toBe('Good afternoon');
        expect(greeting(new Date(2026, 9, 20, 21))).toBe('Good evening');
    });
});
