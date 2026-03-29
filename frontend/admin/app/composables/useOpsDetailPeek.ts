import { gql } from '~/utils/gql'
import { graphqlFetch } from '~/utils/graphqlFetch'

const PEEK_COMMITMENT = gql`
  query OpsPeekCommitment($uuid: String!) {
    commitmentList(filter: [{ field: "uuid", value: $uuid }], limit: 1) {
      items {
        uuid
        title
        status
        due_date
        person_uuid
        workspace_uuid
        updated_at
      }
    }
  }
`

const PEEK_PROSPECT = gql`
  query OpsPeekProspect($uuid: String!) {
    prospectList(filter: [{ field: "uuid", value: $uuid }], limit: 1) {
      items {
        uuid
        name
        stage
        contact_name
        contact_email
        value
        workspace_uuid
        updated_at
      }
    }
  }
`

export async function fetchCommitmentPeek(uuid: string) {
  const data = await graphqlFetch<{
    commitmentList: { items: Array<Record<string, unknown>> }
  }>(PEEK_COMMITMENT, { uuid })
  return data.commitmentList.items[0] ?? null
}

export async function fetchProspectPeek(uuid: string) {
  const data = await graphqlFetch<{
    prospectList: { items: Array<Record<string, unknown>> }
  }>(PEEK_PROSPECT, { uuid })
  return data.prospectList.items[0] ?? null
}
