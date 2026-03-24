import { registerEntityDetailConfig, type EntityDetailConfig } from '~/composables/useEntityDetailConfig'

export const commitmentDetailConfig: EntityDetailConfig = {
  metadata: [
    { key: 'status', label: 'Status', format: 'badge' },
    { key: 'workflow_state', label: 'State' },
    { key: 'direction', label: 'Direction' },
    { key: 'confidence', label: 'Confidence' },
    { key: 'due_date', label: 'Due', format: 'date' },
  ],
  sidebar: [
    {
      key: 'person',
      label: 'Person',
      query: {
        entityType: 'person',
        filterField: 'uuid',
        // Single entity lookup: person_uuid on the commitment
        // points to the person. Custom panel needed for proper
        // single-entity display (future improvement).
      },
    },
    {
      key: 'details',
      label: 'Details',
    },
  ],
}

registerEntityDetailConfig('commitment', commitmentDetailConfig)
