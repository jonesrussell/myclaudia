import { gql } from '~/utils/gql';
import { graphqlFetch } from '~/utils/graphqlFetch';
import type { Commitment } from '~/types/commitment';
import type { ListResult } from '~/types/graphql';

const COMMITMENTS_LIST_QUERY = gql`
  query CommitmentsList($status: String, $tenantId: String) {
    commitmentList(
      filter: [
        { field: "status", value: $status }
        { field: "tenant_id", value: $tenantId }
      ]
      sort: "-updated_at"
      limit: 50
    ) {
      items {
        uuid title status confidence due_date
        person_uuid source tenant_id created_at updated_at
      }
      total
    }
  }
`;

export interface CommitmentsFilter {
  status?: string;
  tenantId?: string;
}

export async function fetchCommitments(
  filter: CommitmentsFilter = {},
): Promise<ListResult<Commitment>> {
  const data = await graphqlFetch<{ commitmentList: ListResult<Commitment> }>(
    COMMITMENTS_LIST_QUERY,
    filter,
  );
  return data.commitmentList;
}
