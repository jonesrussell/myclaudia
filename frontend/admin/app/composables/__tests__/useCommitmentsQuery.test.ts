// @vitest-environment node
import { beforeEach, describe, it, expect, vi } from 'vitest';
import { fetchCommitments } from '../useCommitmentsQuery';

vi.mock('~/utils/graphqlFetch', () => ({
  graphqlFetch: vi.fn(),
}));

vi.mock('~/utils/gql', () => ({
  gql: (strings: TemplateStringsArray, ...values: unknown[]) =>
    strings.reduce((r, s, i) => r + s + (values[i] ?? ''), '').replace(/\s+/g, ' ').trim(),
}));

import { graphqlFetch } from '~/utils/graphqlFetch';

describe('fetchCommitments', () => {
  beforeEach(() => {
    vi.mocked(graphqlFetch).mockClear();
  });

  it('calls graphqlFetch with commitment list query', async () => {
    const mockData = {
      commitmentList: {
        items: [{ uuid: '1', title: 'Test', status: 'pending' }],
        total: 1,
      },
    };
    (graphqlFetch as any).mockResolvedValue(mockData);

    const result = await fetchCommitments({ status: 'pending' });

    expect(graphqlFetch).toHaveBeenCalledWith(
      expect.stringMatching(/CommitmentsListByStatus|commitmentList/),
      { status: 'pending' },
    );
    expect(result.items).toHaveLength(1);
    expect(result.total).toBe(1);
  });

  it('works with no filter', async () => {
    (graphqlFetch as any).mockResolvedValue({
      commitmentList: { items: [], total: 0 },
    });

    const result = await fetchCommitments();

    expect(graphqlFetch).toHaveBeenCalledTimes(1);
    const args = vi.mocked(graphqlFetch).mock.calls[0]!;
    expect(args).toHaveLength(1);
    expect(args[0]).toEqual(
      expect.stringMatching(/CommitmentsListAll|commitmentList/),
    );
    expect(result.items).toEqual([]);
  });
});
