import { gql } from '~/utils/gql';
import { graphqlFetch } from '~/utils/graphqlFetch';
import type { Person } from '~/types/person';
import type { ListResult } from '~/types/graphql';

const PEOPLE_LIST_QUERY = gql`
  query PeopleList($tier: String, $tenantId: String) {
    personList(
      filter: [
        { field: "tier", value: $tier }
        { field: "tenant_id", value: $tenantId }
      ]
      sort: "-last_interaction_at"
      limit: 50
    ) {
      items {
        uuid name email tier source tenant_id
        latest_summary last_interaction_at last_inbox_category
        created_at updated_at
      }
      total
    }
  }
`;

export interface PeopleFilter {
  tier?: string;
  tenantId?: string;
}

export async function fetchPeople(
  filter: PeopleFilter = {},
): Promise<ListResult<Person>> {
  const data = await graphqlFetch<{ personList: ListResult<Person> }>(
    PEOPLE_LIST_QUERY,
    filter,
  );
  return data.personList;
}
