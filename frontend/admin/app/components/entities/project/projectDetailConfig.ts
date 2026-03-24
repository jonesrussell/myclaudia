import { registerEntityDetailConfig, type EntityDetailConfig } from '~/composables/useEntityDetailConfig'

export const projectDetailConfig: EntityDetailConfig = {
  metadata: [
    { key: 'status', label: 'Status', format: 'badge' },
    { key: 'created_at', label: 'Created', format: 'date' },
    { key: 'tenant_id', label: 'Tenant', truncate: true },
  ],
  sidebar: [
    {
      key: 'repos',
      label: 'Repos',
      query: {
        entityType: 'project_repo',
        filterField: 'project_uuid',
        resolveType: 'repo',
        resolveField: 'repo_uuid',
      },
    },
    {
      key: 'workspaces',
      label: 'Workspaces',
      query: {
        entityType: 'workspace_project',
        filterField: 'project_uuid',
        resolveType: 'workspace',
        resolveField: 'workspace_uuid',
      },
    },
    {
      key: 'commitments',
      label: 'Commitments',
      query: {
        entityType: 'commitment',
        filterField: 'project',
      },
    },
    {
      key: 'activity',
      label: 'Activity',
    },
    {
      key: 'details',
      label: 'Details',
    },
  ],
  actions: [
    { label: 'Link Repo', type: 'link', targetType: 'repo' },
    { label: 'Link Workspace', type: 'link', targetType: 'workspace' },
  ],
}

registerEntityDetailConfig('project', projectDetailConfig)
