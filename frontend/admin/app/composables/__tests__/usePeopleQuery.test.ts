// @vitest-environment node
import { beforeEach, describe, it, expect, vi } from 'vitest';
import { fetchPeople } from '../usePeopleQuery';

vi.mock('~/utils/graphqlFetch', () => ({
  graphqlFetch: vi.fn(),
}));

vi.mock('~/utils/gql', () => ({
  gql: (strings: TemplateStringsArray, ...values: unknown[]) =>
    strings.reduce((r, s, i) => r + s + (values[i] ?? ''), '').replace(/\s+/g, ' ').trim(),
}));

import { graphqlFetch } from '~/utils/graphqlFetch';

describe('fetchPeople', () => {
  beforeEach(() => {
    vi.mocked(graphqlFetch).mockClear();
  });

  it('calls graphqlFetch with people list query', async () => {
    const mockData = {
      personList: {
        items: [{ uuid: '1', name: 'Alice', email: 'alice@example.com', tier: 'inner_circle' }],
        total: 1,
      },
    };
    (graphqlFetch as any).mockResolvedValue(mockData);

    const result = await fetchPeople({ tier: 'inner_circle' });

    expect(graphqlFetch).toHaveBeenCalledWith(
      expect.stringMatching(/PeopleListByTier|personList/),
      { tier: 'inner_circle' },
    );
    expect(result.items).toHaveLength(1);
    expect(result.total).toBe(1);
  });

  it('works with no filter', async () => {
    (graphqlFetch as any).mockResolvedValue({
      personList: { items: [], total: 0 },
    });

    const result = await fetchPeople();

    expect(graphqlFetch).toHaveBeenCalledTimes(1);
    const args = vi.mocked(graphqlFetch).mock.calls[0]!;
    expect(args).toHaveLength(1);
    expect(args[0]).toEqual(
      expect.stringMatching(/PeopleListAll|personList/),
    );
    expect(result.items).toEqual([]);
  });
});
