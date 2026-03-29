export type OpsDetailEntityType = 'commitment' | 'prospect'

export function useOpsDetailDrawer() {
  const open = useState('claudriel.ops.detailDrawerOpen', () => false)
  const entityType = useState<OpsDetailEntityType | ''>('claudriel.ops.detailDrawerEntityType', () => '')
  const entityUuid = useState('claudriel.ops.detailDrawerUuid', () => '')

  function openDrawer(type: OpsDetailEntityType, uuid: string) {
    if (!uuid) {
      return
    }
    entityType.value = type
    entityUuid.value = uuid
    open.value = true
  }

  function closeDrawer() {
    open.value = false
  }

  return { open, entityType, entityUuid, openDrawer, closeDrawer }
}
