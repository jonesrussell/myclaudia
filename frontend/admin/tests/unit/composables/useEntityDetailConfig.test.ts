import { describe, it, expect } from 'vitest'
import { useEntityDetailConfig } from '~/composables/useEntityDetailConfig'
import '~/components/entities'

describe('useEntityDetailConfig', () => {
  it('returns null for unknown entity type', () => {
    expect(useEntityDetailConfig('nonexistent')).toBeNull()
  })

  it('returns config for registered workspace type', () => {
    const config = useEntityDetailConfig('workspace')
    expect(config).not.toBeNull()
    expect(config!.sidebar.length).toBeGreaterThan(0)
  })

  it('workspace config has required metadata fields', () => {
    const config = useEntityDetailConfig('workspace')!
    expect(config.metadata).toBeDefined()
    expect(config.metadata!.length).toBe(4)
    expect(config.metadata![0]).toEqual({ key: 'status', label: 'Status', format: 'badge' })
  })

  it('every sidebar section has key and label', () => {
    const config = useEntityDetailConfig('workspace')!
    for (const section of config.sidebar) {
      expect(section.key).toBeTruthy()
      expect(section.label).toBeTruthy()
    }
  })

  it('workspace has repos and projects with junction queries', () => {
    const config = useEntityDetailConfig('workspace')!
    const repos = config.sidebar.find(s => s.key === 'repos')!
    expect(repos.query).toEqual({
      entityType: 'workspace_repo',
      filterField: 'workspace_uuid',
      resolveType: 'repo',
      resolveField: 'repo_uuid',
    })

    const projects = config.sidebar.find(s => s.key === 'projects')!
    expect(projects.query).toEqual({
      entityType: 'workspace_project',
      filterField: 'workspace_uuid',
      resolveType: 'project',
      resolveField: 'project_uuid',
    })
  })

  it('workspace has link actions for repo and project', () => {
    const config = useEntityDetailConfig('workspace')!
    expect(config.actions).toHaveLength(2)
    expect(config.actions![0]).toEqual({ label: 'Link Repo', type: 'link', targetType: 'repo' })
    expect(config.actions![1]).toEqual({ label: 'Link Project', type: 'link', targetType: 'project' })
  })

  it('workspace has details section as last sidebar entry', () => {
    const config = useEntityDetailConfig('workspace')!
    const last = config.sidebar[config.sidebar.length - 1]
    expect(last.key).toBe('details')
  })

  // Validate all 8 entity configs are registered
  const ALL_TYPES = [
    'workspace', 'project', 'repo', 'person',
    'commitment', 'schedule_entry', 'triage_entry', 'judgment_rule',
  ]

  for (const type of ALL_TYPES) {
    it(`returns config for ${type}`, () => {
      const config = useEntityDetailConfig(type)
      expect(config).not.toBeNull()
      expect(config!.sidebar.length).toBeGreaterThan(0)
      expect(config!.sidebar.some(s => s.key === 'details')).toBe(true)
      expect(config!.metadata).toBeDefined()
      expect(config!.metadata!.length).toBeGreaterThan(0)
    })
  }

  it('project has junction queries for repos and workspaces', () => {
    const config = useEntityDetailConfig('project')!
    expect(config.sidebar.find(s => s.key === 'repos')!.query!.resolveType).toBe('repo')
    expect(config.sidebar.find(s => s.key === 'workspaces')!.query!.resolveType).toBe('workspace')
  })

  it('repo has reverse junction queries', () => {
    const config = useEntityDetailConfig('repo')!
    expect(config.sidebar.find(s => s.key === 'workspaces')!.query!.filterField).toBe('repo_uuid')
    expect(config.sidebar.find(s => s.key === 'projects')!.query!.filterField).toBe('repo_uuid')
  })

  it('person has direct commitment query', () => {
    const config = useEntityDetailConfig('person')!
    const commitments = config.sidebar.find(s => s.key === 'commitments')!
    expect(commitments.query!.entityType).toBe('commitment')
    expect(commitments.query!.filterField).toBe('person_uuid')
  })
})
