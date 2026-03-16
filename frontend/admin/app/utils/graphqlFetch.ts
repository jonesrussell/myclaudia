export class GraphQlError extends Error {
  constructor(
    public readonly errors: Array<{ message: string; locations?: unknown[]; path?: string[] }>,
  ) {
    super(errors.map(e => e.message).join('; '));
    this.name = 'GraphQlError';
  }
}

/**
 * Typed GraphQL fetch — sends a query to the /api/graphql endpoint
 * and returns the typed data or throws GraphQlError.
 */
export async function graphqlFetch<T = unknown>(
  query: string,
  variables?: Record<string, unknown>,
): Promise<T> {
  const response = await fetch('/api/graphql', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ query, variables }),
  });

  if (!response.ok) {
    throw new Error(`GraphQL request failed: ${response.status} ${response.statusText}`);
  }

  const json = await response.json() as { data?: T; errors?: Array<{ message: string }> };

  if (json.errors?.length) {
    throw new GraphQlError(json.errors);
  }

  return json.data as T;
}
