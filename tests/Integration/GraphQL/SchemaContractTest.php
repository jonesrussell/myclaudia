<?php

declare(strict_types=1);

namespace Claudriel\Tests\Integration\GraphQL;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\GraphQL\Access\GraphQlAccessGuard;
use Waaseyaa\GraphQL\Resolver\EntityResolver;
use Waaseyaa\GraphQL\Resolver\ReferenceLoader;
use Waaseyaa\GraphQL\Schema\SchemaFactory;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\Person;

/**
 * Contract test: validates Claudriel's entity types produce
 * the expected GraphQL schema structure via Waaseyaa's SchemaFactory.
 *
 * This catches field definition drift between Claudriel entity types
 * and the GraphQL layer without requiring a full kernel boot.
 */
#[CoversNothing]
final class SchemaContractTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;

    protected function setUp(): void
    {
        $this->entityTypeManager = new EntityTypeManager(new EventDispatcher());

        $this->entityTypeManager->registerCoreEntityType(new EntityType(
            id: 'person',
            label: 'Person',
            class: Person::class,
            keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'pid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'name' => ['type' => 'string', 'required' => true],
                'email' => ['type' => 'email', 'required' => true],
                'tier' => ['type' => 'string'],
                'source' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'latest_summary' => ['type' => 'string'],
                'last_interaction_at' => ['type' => 'datetime'],
                'last_inbox_category' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityTypeManager->registerCoreEntityType(new EntityType(
            id: 'commitment',
            label: 'Commitment',
            class: Commitment::class,
            keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title'],
            fieldDefinitions: [
                'cid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'title' => ['type' => 'string', 'required' => true],
                'status' => ['type' => 'string'],
                'confidence' => ['type' => 'float'],
                'due_date' => ['type' => 'datetime'],
                'person_uuid' => ['type' => 'string'],
                'source' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));
    }

    private function buildSchema(): Schema
    {
        $accessHandler = new EntityAccessHandler([]);
        $account = $this->createStub(AccountInterface::class);
        $guard = new GraphQlAccessGuard($accessHandler, $account);
        $resolver = new EntityResolver($this->entityTypeManager, $guard);
        $referenceLoader = new ReferenceLoader($this->entityTypeManager, $guard);
        $factory = new SchemaFactory(
            entityTypeManager: $this->entityTypeManager,
            entityResolver: $resolver,
            referenceLoader: $referenceLoader,
        );

        return $factory->build();
    }

    #[Test]
    public function commitmentAndPersonTypesExistInSchema(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();

        self::assertNotNull($queryType);

        // Both entity types produce query fields
        self::assertTrue($queryType->hasField('commitment'));
        self::assertTrue($queryType->hasField('commitmentList'));
        self::assertTrue($queryType->hasField('person'));
        self::assertTrue($queryType->hasField('personList'));
    }

    #[Test]
    public function commitmentMutationsExist(): void
    {
        $schema = $this->buildSchema();
        $mutationType = $schema->getMutationType();

        self::assertNotNull($mutationType);
        self::assertTrue($mutationType->hasField('createCommitment'));
        self::assertTrue($mutationType->hasField('updateCommitment'));
        self::assertTrue($mutationType->hasField('deleteCommitment'));
    }

    #[Test]
    public function personMutationsExist(): void
    {
        $schema = $this->buildSchema();
        $mutationType = $schema->getMutationType();

        self::assertNotNull($mutationType);
        self::assertTrue($mutationType->hasField('createPerson'));
        self::assertTrue($mutationType->hasField('updatePerson'));
        self::assertTrue($mutationType->hasField('deletePerson'));
    }

    #[Test]
    public function commitmentTypeHasExpectedFields(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();
        $commitmentField = $queryType->getField('commitment');
        $commitmentType = $commitmentField->getType();

        self::assertInstanceOf(ObjectType::class, $commitmentType);

        $expectedFields = ['id', 'uuid', 'title', 'status', 'confidence', 'due_date', 'person_uuid', 'source', 'tenant_id', 'created_at', 'updated_at'];
        foreach ($expectedFields as $fieldName) {
            self::assertTrue($commitmentType->hasField($fieldName), "Commitment missing field: {$fieldName}");
        }

        // Verify key type mappings
        self::assertSame('Float', $this->unwrapTypeName($commitmentType->getField('confidence')->getType()));
        self::assertSame('String', $this->unwrapTypeName($commitmentType->getField('status')->getType()));
    }

    #[Test]
    public function personTypeHasExpectedFields(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();
        $personField = $queryType->getField('person');
        $personType = $personField->getType();

        self::assertInstanceOf(ObjectType::class, $personType);

        $expectedFields = ['id', 'uuid', 'name', 'email', 'tier', 'source', 'tenant_id', 'latest_summary', 'last_interaction_at', 'last_inbox_category', 'created_at', 'updated_at'];
        foreach ($expectedFields as $fieldName) {
            self::assertTrue($personType->hasField($fieldName), "Person missing field: {$fieldName}");
        }

        // Verify key type mappings
        self::assertSame('String', $this->unwrapTypeName($personType->getField('email')->getType()));
        self::assertSame('String', $this->unwrapTypeName($personType->getField('tier')->getType()));
    }

    #[Test]
    public function commitmentCreateInputExcludesReadOnlyFields(): void
    {
        $schema = $this->buildSchema();
        $mutationType = $schema->getMutationType();
        $createField = $mutationType->getField('createCommitment');
        $inputType = $createField->getArg('input')->getType();

        if ($inputType instanceof NonNull) {
            $inputType = $inputType->getWrappedType();
        }

        self::assertInstanceOf(InputObjectType::class, $inputType);

        // Writable fields present (including label key 'title')
        self::assertTrue($inputType->hasField('title'));
        self::assertTrue($inputType->hasField('status'));
        self::assertTrue($inputType->hasField('confidence'));
        self::assertTrue($inputType->hasField('due_date'));

        // Entity key fields excluded from input (id, uuid only)
        self::assertFalse($inputType->hasField('cid'));
        self::assertFalse($inputType->hasField('id'));
        self::assertFalse($inputType->hasField('uuid'));
        // Read-only timestamp fields excluded
        self::assertFalse($inputType->hasField('created_at'));
        self::assertFalse($inputType->hasField('updated_at'));
    }

    #[Test]
    public function personCreateInputExcludesReadOnlyFields(): void
    {
        $schema = $this->buildSchema();
        $mutationType = $schema->getMutationType();
        $createField = $mutationType->getField('createPerson');
        $inputType = $createField->getArg('input')->getType();

        if ($inputType instanceof NonNull) {
            $inputType = $inputType->getWrappedType();
        }

        self::assertInstanceOf(InputObjectType::class, $inputType);

        // Writable fields present (including label key 'name')
        self::assertTrue($inputType->hasField('name'));
        self::assertTrue($inputType->hasField('email'));
        self::assertTrue($inputType->hasField('tier'));
        self::assertTrue($inputType->hasField('source'));

        // Entity key fields excluded from input (id, uuid only)
        self::assertFalse($inputType->hasField('pid'));
        self::assertFalse($inputType->hasField('id'));
        self::assertFalse($inputType->hasField('uuid'));
        // Read-only timestamp fields excluded
        self::assertFalse($inputType->hasField('created_at'));
        self::assertFalse($inputType->hasField('updated_at'));
    }

    private function unwrapTypeName(Type $type): string
    {
        if ($type instanceof NonNull) {
            $type = $type->getWrappedType();
        }

        return $type->name;
    }
}
