import { gql } from '~/utils/gql'
import { graphqlFetch } from '~/utils/graphqlFetch'

const WORKSPACE_LIST = gql`
  query OpsWorkspaceList {
    workspaceList(sort: "name", limit: 200) {
      total
      items {
        uuid
        name
        description
        tenant_id
        created_at
      }
    }
  }
`

const PROSPECT_LIST = gql`
  query OpsProspectList($workspaceUuid: String) {
    prospectList(
      filter: [{ field: "workspace_uuid", value: $workspaceUuid }]
      sort: "-updated_at"
      limit: 200
    ) {
      total
      items {
        uuid
        name
        stage
        value
        contact_name
        contact_email
        workspace_uuid
        updated_at
      }
    }
  }
`

const PROSPECT_LIST_ALL = gql`
  query OpsProspectListAll($tenantId: String) {
    prospectList(
      filter: [{ field: "tenant_id", value: $tenantId }]
      sort: "-updated_at"
      limit: 300
    ) {
      total
      items {
        uuid
        name
        stage
        value
        contact_name
        contact_email
        workspace_uuid
        updated_at
      }
    }
  }
`

const COMMITMENT_LIST_TENANT = gql`
  query OpsCommitmentListTenant($tenantId: String) {
    commitmentList(
      filter: [{ field: "tenant_id", value: $tenantId }]
      sort: "-updated_at"
      limit: 40
    ) {
      total
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

const COMMITMENT_LIST_WS = gql`
  query OpsCommitmentListWs($workspaceUuid: String) {
    commitmentList(
      filter: [{ field: "workspace_uuid", value: $workspaceUuid }]
      sort: "-updated_at"
      limit: 40
    ) {
      total
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

const SCHEDULE_LIST_WS = gql`
  query OpsScheduleListWs($tenantId: String) {
    scheduleEntryList(
      filter: [{ field: "tenant_id", value: $tenantId }]
      sort: "starts_at"
      limit: 30
    ) {
      total
      items {
        uuid
        title
        starts_at
        ends_at
        status
      }
    }
  }
`

const TRIAGE_LIST_WS = gql`
  query OpsTriageListWs($tenantId: String) {
    triageEntryList(
      filter: [{ field: "tenant_id", value: $tenantId }]
      sort: "-occurred_at"
      limit: 30
    ) {
      total
      items {
        uuid
        sender_name
        summary
        status
        occurred_at
      }
    }
  }
`

export interface WorkspaceRow {
  uuid: string
  name: string
  description?: string | null
  tenant_id?: string | null
  created_at?: string | null
}

export interface ProspectRow {
  uuid: string
  name: string
  stage?: string | null
  value?: string | null
  contact_name?: string | null
  contact_email?: string | null
  workspace_uuid?: string | null
  updated_at?: string | null
}

export async function fetchWorkspaceList(): Promise<WorkspaceRow[]> {
  const data = await graphqlFetch<{ workspaceList: { items: WorkspaceRow[] } }>(WORKSPACE_LIST)
  return data.workspaceList.items
}

export async function fetchProspectsForWorkspace(workspaceUuid: string | null): Promise<ProspectRow[]> {
  if (!workspaceUuid) {
    return []
  }
  const data = await graphqlFetch<{ prospectList: { items: ProspectRow[] } }>(PROSPECT_LIST, {
    workspaceUuid,
  })
  return data.prospectList.items
}

export async function fetchAllProspectsForTenant(tenantId: string | null): Promise<ProspectRow[]> {
  if (!tenantId) {
    return []
  }
  const data = await graphqlFetch<{ prospectList: { items: ProspectRow[] } }>(PROSPECT_LIST_ALL, {
    tenantId,
  })
  return data.prospectList.items
}

export async function fetchCommitmentsForTenant(
  tenantId: string | null,
): Promise<Array<{ uuid: string; title?: string; status?: string; due_date?: string | null; workspace_uuid?: string | null }>> {
  if (!tenantId) {
    return []
  }
  const data = await graphqlFetch<{
    commitmentList: {
      items: Array<{ uuid: string; title?: string; status?: string; due_date?: string | null; workspace_uuid?: string | null }>
    }
  }>(COMMITMENT_LIST_TENANT, { tenantId })
  return data.commitmentList.items
}

export async function fetchCommitmentsForWorkspace(
  workspaceUuid: string | null,
): Promise<Array<{ uuid: string; title?: string; status?: string; due_date?: string | null; workspace_uuid?: string | null }>> {
  if (!workspaceUuid) {
    return []
  }
  const data = await graphqlFetch<{
    commitmentList: {
      items: Array<{ uuid: string; title?: string; status?: string; due_date?: string | null; workspace_uuid?: string | null }>
    }
  }>(COMMITMENT_LIST_WS, { workspaceUuid })
  return data.commitmentList.items
}

export async function fetchScheduleEntries(tenantId: string | null): Promise<
  Array<{ uuid: string; title?: string; starts_at?: string; ends_at?: string; status?: string }>
> {
  if (!tenantId) {
    return []
  }
  const data = await graphqlFetch<{
    scheduleEntryList: {
      items: Array<{ uuid: string; title?: string; starts_at?: string; ends_at?: string; status?: string }>
    }
  }>(SCHEDULE_LIST_WS, { tenantId })
  return data.scheduleEntryList.items
}

export async function fetchTriageEntries(tenantId: string | null): Promise<
  Array<{ uuid: string; sender_name?: string; summary?: string; status?: string; occurred_at?: string }>
> {
  if (!tenantId) {
    return []
  }
  const data = await graphqlFetch<{
    triageEntryList: {
      items: Array<{ uuid: string; sender_name?: string; summary?: string; status?: string; occurred_at?: string }>
    }
  }>(TRIAGE_LIST_WS, { tenantId })
  return data.triageEntryList.items
}
