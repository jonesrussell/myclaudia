// @vitest-environment node
import { describe, it, expect, vi, afterEach } from 'vitest';
import { graphqlFetch, GraphQlError } from '../graphqlFetch';
import { gql } from '../gql';

describe('graphqlFetch', () => {
  const originalFetch = globalThis.fetch;

  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  it('returns typed data on success', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: { article: { id: '1', title: 'Test' } } }),
    });

    const result = await graphqlFetch<{ article: { id: string; title: string } }>(
      '{ article(id: "1") { id title } }'
    );

    expect(result.article.title).toBe('Test');
    expect(globalThis.fetch).toHaveBeenCalledWith('/api/graphql', expect.objectContaining({
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
    }));
  });

  it('passes variables in request body', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ data: { items: [] } }),
    });

    await graphqlFetch('query ($id: ID!) { item(id: $id) { id } }', { id: '42' });

    const callBody = JSON.parse((globalThis.fetch as any).mock.calls[0][1].body);
    expect(callBody.variables).toEqual({ id: '42' });
  });

  it('throws GraphQlError on errors', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ errors: [{ message: 'Not found' }] }),
    });

    await expect(graphqlFetch('{ bad }')).rejects.toThrow(GraphQlError);
    await expect(graphqlFetch('{ bad }')).rejects.toThrow('Not found');
  });

  it('throws GraphQlError with multiple error messages', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ errors: [{ message: 'Error 1' }, { message: 'Error 2' }] }),
    });

    try {
      await graphqlFetch('{ bad }');
      expect.fail('Should have thrown');
    } catch (e) {
      expect(e).toBeInstanceOf(GraphQlError);
      expect((e as GraphQlError).message).toBe('Error 1; Error 2');
      expect((e as GraphQlError).errors).toHaveLength(2);
    }
  });
  it('throws on non-ok HTTP response', async () => {
    globalThis.fetch = vi.fn().mockResolvedValue({
      ok: false,
      status: 500,
      statusText: 'Internal Server Error',
    });

    await expect(graphqlFetch('{ bad }')).rejects.toThrow('GraphQL request failed: 500 Internal Server Error');
  });
});

describe('gql', () => {
  it('strips extra whitespace', () => {
    const query = gql`
      query {
        commitmentList {
          items { uuid title }
        }
      }
    `;
    expect(query).toBe('query { commitmentList { items { uuid title } } }');
  });

  it('interpolates values', () => {
    const fieldName = 'title';
    const query = gql`{ commitment { ${fieldName} } }`;
    expect(query).toContain('title');
  });
});
