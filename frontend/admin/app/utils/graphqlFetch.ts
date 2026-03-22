export class GraphQlError extends Error {
  constructor(
    public readonly errors: Array<{ message: string; locations?: unknown[]; path?: string[] }>,
  ) {
    super(errors.map(e => e.message).join('; '));
    this.name = 'GraphQlError';
  }
}

/**
 * Typed GraphQL fetch — sends a query to the /graphql endpoint
 * and returns the typed data or throws GraphQlError.
 */
export async function graphqlFetch<T = unknown>(
  query: string,
  variables?: Record<string, unknown>,
): Promise<T> {
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), 15_000);

  let response: Response;
  try {
    response = await fetch('/graphql', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'include',
      body: JSON.stringify({ query, variables }),
      signal: controller.signal,
    });
  } catch (err: any) {
    if (err.name === 'AbortError') {
      throw new Error('GraphQL request timed out after 15 seconds');
    }
    throw err;
  } finally {
    clearTimeout(timeoutId);
  }

  if (!response.ok) {
    throw new Error(`GraphQL request failed: ${response.status} ${response.statusText}`);
  }

  const json = await response.json() as { data?: T; errors?: Array<{ message: string }> };

  if (json.errors?.length) {
    throw new GraphQlError(json.errors);
  }

  if (json.data == null) {
    throw new Error('GraphQL response contained no data');
  }

  return json.data as T;
}
