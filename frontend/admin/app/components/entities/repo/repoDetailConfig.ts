import { registerEntityDetailConfig, type EntityDetailConfig } from '~/composables/useEntityDetailConfig'

export const repoDetailConfig: EntityDetailConfig = {
  metadata: [
    { key: 'full_name', label: 'Full Name' },
    { key: 'default_branch', label: 'Branch' },
    { key: 'created_at', label: 'Created', format: 'date' },
    { key: 'tenant_id', label: 'Tenant', truncate: true },
  ],
  sidebar: [
    {
      key: 'workspaces',
      label: 'Workspaces',
      query: {
        entityType: 'workspace_repo',
        filterField: 'repo_uuid',
        resolveType: 'workspace',
        resolveField: 'workspace_uuid',
      },
    },
    {
      key: 'projects',
      label: 'Projects',
      query: {
        entityType: 'project_repo',
        filterField: 'repo_uuid',
        resolveType: 'project',
        resolveField: 'project_uuid',
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
}

registerEntityDetailConfig('repo', repoDetailConfig)
