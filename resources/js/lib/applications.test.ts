import { describe, expect, it } from 'vite-plus/test';
import type { JobApplication } from '@/types';
import { daysUntil, deadlineLabel, groupByStage } from './applications';

const today = new Date(2026, 9, 20, 15, 30);

describe('daysUntil', () => {
    it('counts calendar days, ignoring the time of day', () => {
        expect(daysUntil('2026-10-20', today)).toBe(0);
        expect(daysUntil('2026-10-23', today)).toBe(3);
        expect(daysUntil('2026-10-18', today)).toBe(-2);
    });
});

describe('deadlineLabel', () => {
    it('flags past deadlines of applications not yet sent', () => {
        expect(
            deadlineLabel(
                { deadline_on: '2026-10-19', stage: 'preparing' },
                today,
            ),
        ).toEqual({ text: 'Overdue by 1 day', tone: 'overdue' });
    });

    it('marks the next three days as soon', () => {
        expect(
            deadlineLabel({ deadline_on: '2026-10-20', stage: 'saved' }, today)
                ?.text,
        ).toBe('Due today');
        expect(
            deadlineLabel({ deadline_on: '2026-10-22', stage: 'saved' }, today),
        ).toEqual({ text: 'Due in 2 days', tone: 'soon' });
    });

    it('ignores deadlines once the application was sent or closed', () => {
        expect(
            deadlineLabel(
                { deadline_on: '2026-10-01', stage: 'applied' },
                today,
            ),
        ).toBeNull();
        expect(
            deadlineLabel(
                { deadline_on: '2026-10-01', stage: 'rejected' },
                today,
            ),
        ).toBeNull();
        expect(
            deadlineLabel({ deadline_on: null, stage: 'saved' }, today),
        ).toBeNull();
    });
});

describe('groupByStage', () => {
    it('puts open stages in columns and closed ones apart', () => {
        const make = (ulid: string, stage: JobApplication['stage']) =>
            ({ ulid, stage }) as JobApplication;

        const { columns, closed } = groupByStage([
            make('a', 'saved'),
            make('b', 'offer'),
            make('c', 'rejected'),
            make('d', 'saved'),
        ]);

        expect(columns.map((column) => column.stage)).toEqual([
            'saved',
            'preparing',
            'applied',
            'interviewing',
            'offer',
        ]);
        expect(columns[0].items.map((a) => a.ulid)).toEqual(['a', 'd']);
        expect(columns[4].items.map((a) => a.ulid)).toEqual(['b']);
        expect(closed.map((a) => a.ulid)).toEqual(['c']);
    });
});
