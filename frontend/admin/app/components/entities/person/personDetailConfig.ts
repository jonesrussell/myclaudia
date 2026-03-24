import { registerEntityDetailConfig, type EntityDetailConfig } from '~/composables/useEntityDetailConfig'

export const personDetailConfig: EntityDetailConfig = {
  metadata: [
    { key: 'email', label: 'Email' },
    { key: 'tier', label: 'Tier' },
    { key: 'source', label: 'Source' },
    { key: 'last_interaction_at', label: 'Last Interaction', format: 'date' },
  ],
  sidebar: [
    {
      key: 'commitments',
      label: 'Commitments',
      query: {
        entityType: 'commitment',
        filterField: 'person_uuid',
      },
    },
    {
      key: 'triage',
      label: 'Triage Entries',
      query: {
        entityType: 'triage_entry',
        filterField: 'sender_email',
        // Note: filters by sender_email matching person's email.
        // The parentId passed will be the person UUID, but the filter
        // uses sender_email. This needs a custom panel in the future
        // to resolve person UUID -> email -> triage entries.
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

registerEntityDetailConfig('person', personDetailConfig)
