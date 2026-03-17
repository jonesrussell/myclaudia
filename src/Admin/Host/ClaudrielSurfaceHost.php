<?php

declare(strict_types=1);

namespace Claudriel\Admin\Host;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Entity\Tenant;
use Claudriel\Support\AdminAccess;
use Claudriel\Support\AuthenticatedAccountSessionResolver;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\AdminSurface\Catalog\CatalogBuilder;
use Waaseyaa\AdminSurface\Host\AbstractAdminSurfaceHost;
use Waaseyaa\AdminSurface\Host\AdminSurfaceResultData;
use Waaseyaa\AdminSurface\Host\AdminSurfaceSessionData;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class ClaudrielSurfaceHost extends AbstractAdminSurfaceHost
{
    /** @var \Closure(): EntityTypeManager */
    private \Closure $entityTypeManagerFactory;

    /**
     * @param  \Closure(): EntityTypeManager  $entityTypeManagerFactory
     */
    public function __construct(\Closure $entityTypeManagerFactory)
    {
        $this->entityTypeManagerFactory = $entityTypeManagerFactory;
    }

    public function resolveSession(Request $request): ?AdminSurfaceSessionData
    {
        $etm = ($this->entityTypeManagerFactory)();
        $resolver = new AuthenticatedAccountSessionResolver($etm);
        $account = $resolver->resolve();

        if (! $account instanceof AuthenticatedAccount) {
            return null;
        }

        if (! AdminAccess::allows($account)) {
            return null;
        }

        return new AdminSurfaceSessionData(
            accountId: $account->getUuid(),
            accountName: $account->getEmail(),
            roles: $account->getRoles(),
            policies: [],
            email: $account->getEmail(),
            tenantId: (string) ($account->getTenantId() ?? ''),
            tenantName: '',
        );
    }

    public function buildCatalog(AdminSurfaceSessionData $session): CatalogBuilder
    {
        $catalog = new CatalogBuilder;

        $catalog->defineEntity('workspace', 'Workspace')->group('structure');
        $catalog->defineEntity('person', 'Person')->group('people');
        $catalog->defineEntity('commitment', 'Commitment')->group('workflows');
        $catalog->defineEntity('schedule_entry', 'Schedule Entry')->group('workflows');
        $catalog->defineEntity('triage_entry', 'Triage Entry')->group('workflows');

        return $catalog;
    }

    public function list(string $type, array $query = []): AdminSurfaceResultData
    {
        return AdminSurfaceResultData::error(501, 'Not Implemented', 'Entity listing is handled by GraphQL');
    }

    public function get(string $type, string $id): AdminSurfaceResultData
    {
        return AdminSurfaceResultData::error(501, 'Not Implemented', 'Entity retrieval is handled by GraphQL');
    }

    public function action(string $type, string $action, array $payload = []): AdminSurfaceResultData
    {
        return AdminSurfaceResultData::error(501, 'Not Implemented', "Action '$action' is not supported");
    }

    /**
     * Legacy /admin/session endpoint for the frontend SPA.
     *
     * Returns the format the frontend expects: account, tenant, entity_types.
     * Will be removed once the frontend migrates to /admin/surface/* endpoints.
     */
    public function handleLegacySession(): SsrResponse
    {
        $session = $this->resolveSession(Request::createFromGlobals());
        if ($session === null) {
            return $this->jsonResponse(['error' => 'Not authenticated.'], 401);
        }

        $etm = ($this->entityTypeManagerFactory)();
        $sessionData = $session->toArray();
        $catalog = $this->buildCatalog($session)->build();

        $entityTypes = array_map(function (array $entry) use ($etm): array {
            $typeId = $entry['id'];
            $definition = $etm->hasDefinition($typeId)
                ? $etm->getDefinition($typeId)
                : null;

            return [
                'id' => $typeId,
                'label' => $entry['label'],
                'keys' => $definition instanceof EntityType ? $definition->getKeys() : ['id' => 'id'],
                'group' => $entry['group'] ?? 'other',
                'disabled' => false,
            ];
        }, $catalog);

        $tenantId = $sessionData['tenant']['id'] ?? '';
        $tenantPayload = null;
        if ($tenantId !== '') {
            $tenantPayload = $this->serializeTenant($etm, $tenantId);
        }

        return $this->jsonResponse([
            'account' => [
                'uuid' => $sessionData['account']['id'],
                'email' => $sessionData['account']['email'] ?? $sessionData['account']['name'],
                'tenant_id' => $tenantId,
                'roles' => $sessionData['account']['roles'],
            ],
            'tenant' => $tenantPayload,
            'entity_types' => $entityTypes,
        ]);
    }

    /**
     * Legacy /admin/logout endpoint.
     */
    public function handleLegacyLogout(): SsrResponse
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }

        unset($_SESSION['claudriel_account_uuid']);
        session_regenerate_id(true);

        return $this->jsonResponse(['logged_out' => true]);
    }

    /**
     * @return array{uuid: string, name: string, default_workspace_uuid: string|null}|null
     */
    private function serializeTenant(EntityTypeManager $etm, string $tenantId): ?array
    {
        $ids = $etm->getStorage('tenant')->getQuery()
            ->condition('uuid', $tenantId)
            ->range(0, 1)
            ->execute();

        if ($ids === []) {
            return null;
        }

        $tenant = $etm->getStorage('tenant')->load(reset($ids));
        if (! $tenant instanceof Tenant) {
            return null;
        }

        $metadata = $tenant->get('metadata');
        $workspaceUuid = null;
        if (is_array($metadata)) {
            $val = $metadata['default_workspace_uuid'] ?? null;
            $workspaceUuid = is_string($val) && $val !== '' ? $val : null;
        }

        return [
            'uuid' => (string) $tenant->get('uuid'),
            'name' => (string) $tenant->get('name'),
            'default_workspace_uuid' => $workspaceUuid,
        ];
    }

    private function jsonResponse(mixed $data, int $statusCode = 200): SsrResponse
    {
        return new SsrResponse(
            content: json_encode($data, JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
