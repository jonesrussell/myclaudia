<?php

declare(strict_types=1);

namespace Claudriel\Admin\Host;

use Claudriel\Access\AuthenticatedAccount;

/**
 * Internal host-side contract for the vendored Waaseyaa admin integration.
 *
 * TODO: Replace this internal contract with the packaged Waaseyaa admin host
 * contract once that release is available.
 *
 * @phpstan-type AdminAccountShape array{
 *   uuid: string,
 *   email: string,
 *   tenant_id: string,
 *   roles: list<string>
 * }
 * @phpstan-type AdminTenantShape array{
 *   uuid: string,
 *   name: string,
 *   default_workspace_uuid: string|null
 * }
 * @phpstan-type AdminEntityTypeShape array{
 *   id: string,
 *   label: string,
 *   keys: array<string, string>,
 *   group: string,
 *   disabled: bool
 * }
 * @phpstan-type AdminSessionShape array{
 *   account: AdminAccountShape,
 *   tenant: AdminTenantShape|null,
 *   entity_types: list<AdminEntityTypeShape>
 * }
 * @phpstan-type AdminLogoutShape array{logged_out: true}
 */
interface AdminHostContract
{
    /**
     * Describe the current `/admin/session` payload expected by the admin UI.
     *
     * @return AdminSessionShape
     */
    public function buildSessionPayload(AuthenticatedAccount $account): array;

    /**
     * Describe the current `/admin/logout` success payload.
     *
     * @return AdminLogoutShape
     */
    public function buildLogoutPayload(): array;

    /**
     * Describe the admin entity catalog exposed to the UI.
     *
     * @return list<AdminEntityTypeShape>
     */
    public function entityCatalog(): array;
}
