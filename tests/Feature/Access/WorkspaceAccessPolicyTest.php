<?php

declare(strict_types=1);

namespace Claudriel\Tests\Feature\Access;

use Claudriel\Access\WorkspaceAccessPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(WorkspaceAccessPolicy::class)]
final class WorkspaceAccessPolicyTest extends TestCase
{
    use AccessPolicyTestHelpers;

    private WorkspaceAccessPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new WorkspaceAccessPolicy();
    }

    #[Test]
    public function applies_to_workspace_entity_type(): void
    {
        self::assertTrue($this->policy->appliesTo('workspace'));
        self::assertFalse($this->policy->appliesTo('project'));
    }

    #[Test]
    public function unauthenticated_user_is_denied(): void
    {
        $entity = $this->createEntity('workspace', ['account_id' => '1']);
        $account = $this->createAnonymousAccount();

        $result = $this->policy->access($entity, 'view', $account);

        self::assertTrue($result->isUnauthenticated());
    }

    #[Test]
    public function owner_can_view_update_delete(): void
    {
        $entity = $this->createEntity('workspace', ['account_id' => '42']);
        $account = $this->createAuthenticatedAccount(42, 'tenant-1');

        foreach (['view', 'update', 'delete'] as $operation) {
            $result = $this->policy->access($entity, $operation, $account);
            self::assertTrue($result->isAllowed(), "Owner should be allowed to {$operation}.");
        }
    }

    #[Test]
    public function non_owner_in_same_tenant_is_forbidden(): void
    {
        $entity = $this->createEntity('workspace', ['account_id' => '42']);
        $account = $this->createAuthenticatedAccount(99, 'tenant-1');

        foreach (['view', 'update', 'delete'] as $operation) {
            $result = $this->policy->access($entity, $operation, $account);
            self::assertTrue($result->isForbidden(), "Non-owner should be forbidden to {$operation} workspace.");
        }
    }

    #[Test]
    public function non_owner_different_tenant_is_forbidden(): void
    {
        $entity = $this->createEntity('workspace', ['account_id' => '42']);
        $account = $this->createAuthenticatedAccount(99, 'tenant-2');

        $result = $this->policy->access($entity, 'view', $account);

        self::assertTrue($result->isForbidden());
    }

    #[Test]
    public function create_access_allowed_for_authenticated(): void
    {
        $account = $this->createAuthenticatedAccount(1, 'tenant-1');

        $result = $this->policy->createAccess('workspace', 'workspace', $account);

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function create_access_allowed_without_tenant(): void
    {
        $account = $this->createAuthenticatedAccount(1, null);

        $result = $this->policy->createAccess('workspace', 'workspace', $account);

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function create_access_denied_for_anonymous(): void
    {
        $account = $this->createAnonymousAccount();

        $result = $this->policy->createAccess('workspace', 'workspace', $account);

        self::assertTrue($result->isUnauthenticated());
    }
}
