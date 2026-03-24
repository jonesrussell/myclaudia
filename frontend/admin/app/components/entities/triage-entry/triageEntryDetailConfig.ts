import { registerEntityDetailConfig, type EntityDetailConfig } from '~/composables/useEntityDetailConfig'

export const triageEntryDetailConfig: EntityDetailConfig = {
  metadata: [
    { key: 'status', label: 'Status', format: 'badge' },
    { key: 'sender_name', label: 'Sender' },
    { key: 'sender_email', label: 'Email' },
    { key: 'source', label: 'Source' },
    { key: 'occurred_at', label: 'Occurred', format: 'date' },
  ],
  sidebar: [
    {
      key: 'details',
      label: 'Details',
    },
  ],
}

registerEntityDetailConfig('triage_entry', triageEntryDetailConfig)
