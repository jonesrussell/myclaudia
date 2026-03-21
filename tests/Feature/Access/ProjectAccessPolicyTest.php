<?php

declare(strict_types=1);

namespace Claudriel\Tests\Feature\Access;

use Claudriel\Access\ProjectAccessPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectAccessPolicy::class)]
final class ProjectAccessPolicyTest extends TestCase
{
    use AccessPolicyTestHelpers;

    private ProjectAccessPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ProjectAccessPolicy();
    }

    #[Test]
    public function applies_to_project_entity_type(): void
    {
        self::assertTrue($this->policy->appliesTo('project'));
        self::assertFalse($this->policy->appliesTo('repo'));
    }

    #[Test]
    public function unauthenticated_user_is_denied(): void
    {
        $entity = $this->createEntity('project', ['account_id' => '1', 'tenant_id' => 'tenant-1']);
        $account = $this->createAnonymousAccount();

        $result = $this->policy->access($entity, 'view', $account);

        self::assertTrue($result->isUnauthenticated());
    }

    #[Test]
    public function owner_can_view_update_delete(): void
    {
        $entity = $this->createEntity('project', ['account_id' => '42', 'tenant_id' => 'tenant-1']);
        $account = $this->createAuthenticatedAccount(42, 'tenant-1');

        foreach (['view', 'update', 'delete'] as $operation) {
            $result = $this->policy->access($entity, $operation, $account);
            self::assertTrue($result->isAllowed(), "Owner should be allowed to {$operation}.");
        }
    }

    #[Test]
    public function tenant_member_can_view_but_not_update_or_delete(): void
    {
        $entity = $this->createEntity('project', ['account_id' => '42', 'tenant_id' => 'tenant-1']);
        $account = $this->createAuthenticatedAccount(99, 'tenant-1');

        $viewResult = $this->policy->access($entity, 'view', $account);
        self::assertTrue($viewResult->isAllowed(), 'Tenant member should view.');

        $updateResult = $this->policy->access($entity, 'update', $account);
        self::assertTrue($updateResult->isNeutral(), 'Tenant member should not update.');

        $deleteResult = $this->policy->access($entity, 'delete', $account);
        self::assertTrue($deleteResult->isNeutral(), 'Tenant member should not delete.');
    }

    #[Test]
    public function non_tenant_user_gets_neutral(): void
    {
        $entity = $this->createEntity('project', ['account_id' => '42', 'tenant_id' => 'tenant-1']);
        $account = $this->createAuthenticatedAccount(99, 'tenant-2');

        $result = $this->policy->access($entity, 'view', $account);

        self::assertTrue($result->isNeutral());
    }

    #[Test]
    public function create_access_allowed_for_authenticated_with_tenant(): void
    {
        $account = $this->createAuthenticatedAccount(1, 'tenant-1');

        $result = $this->policy->createAccess('project', 'project', $account);

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function create_access_forbidden_without_tenant(): void
    {
        $account = $this->createAuthenticatedAccount(1, null);

        $result = $this->policy->createAccess('project', 'project', $account);

        self::assertTrue($result->isForbidden());
    }

    #[Test]
    public function create_access_denied_for_anonymous(): void
    {
        $account = $this->createAnonymousAccount();

        $result = $this->policy->createAccess('project', 'project', $account);

        self::assertTrue($result->isUnauthenticated());
    }
}
