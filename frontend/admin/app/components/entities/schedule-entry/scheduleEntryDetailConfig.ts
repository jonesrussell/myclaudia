import { registerEntityDetailConfig, type EntityDetailConfig } from '~/composables/useEntityDetailConfig'

export const scheduleEntryDetailConfig: EntityDetailConfig = {
  metadata: [
    { key: 'status', label: 'Status', format: 'badge' },
    { key: 'starts_at', label: 'Starts', format: 'date' },
    { key: 'ends_at', label: 'Ends', format: 'date' },
    { key: 'source', label: 'Source' },
  ],
  sidebar: [
    {
      key: 'details',
      label: 'Details',
    },
  ],
}

registerEntityDetailConfig('schedule_entry', scheduleEntryDetailConfig)
