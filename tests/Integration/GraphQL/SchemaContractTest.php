<?php

declare(strict_types=1);

namespace Claudriel\Tests\Integration\GraphQL;

use Claudriel\Entity\Commitment;
use Claudriel\Entity\FilteredProspect;
use Claudriel\Entity\Person;
use Claudriel\Entity\PipelineConfig;
use Claudriel\Entity\Prospect;
use Claudriel\Entity\ProspectAttachment;
use Claudriel\Entity\ProspectAudit;
use Claudriel\Entity\ScheduleEntry;
use Claudriel\Entity\TriageEntry;
use Claudriel\Entity\Workspace;
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
        $this->entityTypeManager = new EntityTypeManager(new EventDispatcher);

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
                'importance_score' => ['type' => 'float'],
                'access_count' => ['type' => 'integer'],
                'last_accessed_at' => ['type' => 'datetime'],
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
                'workspace_uuid' => ['type' => 'string'],
                'source' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'importance_score' => ['type' => 'float'],
                'access_count' => ['type' => 'integer'],
                'last_accessed_at' => ['type' => 'datetime'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityTypeManager->registerCoreEntityType(new EntityType(
            id: 'workspace',
            label: 'Workspace',
            class: Workspace::class,
            keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'wid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'name' => ['type' => 'string', 'required' => true],
                'description' => ['type' => 'string'],
                'saved_context' => ['type' => 'text_long'],
                'anthropic_model' => ['type' => 'string'],
                'account_id' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'mode' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityTypeManager->registerCoreEntityType(new EntityType(
            id: 'schedule_entry',
            label: 'Schedule Entry',
            class: ScheduleEntry::class,
            keys: ['id' => 'seid', 'uuid' => 'uuid', 'label' => 'title'],
            fieldDefinitions: [
                'seid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'title' => ['type' => 'string', 'required' => true],
                'starts_at' => ['type' => 'datetime', 'required' => true],
                'ends_at' => ['type' => 'datetime'],
                'notes' => ['type' => 'string'],
                'source' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'external_id' => ['type' => 'string'],
                'calendar_id' => ['type' => 'string'],
                'recurring_series_id' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'raw_payload' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityTypeManager->registerCoreEntityType(new EntityType(
            id: 'triage_entry',
            label: 'Triage Entry',
            class: TriageEntry::class,
            keys: ['id' => 'teid', 'uuid' => 'uuid', 'label' => 'sender_name'],
            fieldDefinitions: [
                'teid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'sender_name' => ['type' => 'string', 'required' => true],
                'sender_email' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'source' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'occurred_at' => ['type' => 'datetime'],
                'external_id' => ['type' => 'string'],
                'content_hash' => ['type' => 'string'],
                'raw_payload' => ['type' => 'text_long'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityTypeManager->registerCoreEntityType(new EntityType(
            id: 'prospect',
            label: 'Prospect',
            class: Prospect::class,
            keys: ['id' => 'prid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'prid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'name' => ['type' => 'string', 'required' => true],
                'description' => ['type' => 'text_long'],
                'stage' => ['type' => 'string'],
                'value' => ['type' => 'string'],
                'contact_name' => ['type' => 'string'],
                'contact_email' => ['type' => 'string'],
                'source_url' => ['type' => 'string'],
                'closing_date' => ['type' => 'string'],
                'sector' => ['type' => 'string'],
                'qualify_rating' => ['type' => 'integer'],
                'qualify_keywords' => ['type' => 'text_long'],
                'qualify_confidence' => ['type' => 'float'],
                'qualify_notes' => ['type' => 'string'],
                'qualify_raw' => ['type' => 'text_long'],
                'draft_email_subject' => ['type' => 'string'],
                'draft_email_body' => ['type' => 'text_long'],
                'draft_pdf_markdown' => ['type' => 'text_long'],
                'draft_pdf_latex' => ['type' => 'text_long'],
                'external_id' => ['type' => 'string'],
                'workspace_uuid' => ['type' => 'string'],
                'person_uuid' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'deleted_at' => ['type' => 'datetime'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityTypeManager->registerCoreEntityType(new EntityType(
            id: 'prospect_attachment',
            label: 'Prospect Attachment',
            class: ProspectAttachment::class,
            keys: ['id' => 'paid', 'uuid' => 'uuid', 'label' => 'filename'],
            fieldDefinitions: [
                'paid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'prospect_uuid' => ['type' => 'string'],
                'filename' => ['type' => 'string', 'required' => true],
                'storage_path' => ['type' => 'string'],
                'content_type' => ['type' => 'string'],
                'workspace_uuid' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityTypeManager->registerCoreEntityType(new EntityType(
            id: 'prospect_audit',
            label: 'Prospect Audit',
            class: ProspectAudit::class,
            keys: ['id' => 'paud', 'uuid' => 'uuid'],
            fieldDefinitions: [
                'paud' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'prospect_uuid' => ['type' => 'string'],
                'action' => ['type' => 'string', 'required' => true],
                'payload' => ['type' => 'text_long'],
                'confirmed_at' => ['type' => 'datetime'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityTypeManager->registerCoreEntityType(new EntityType(
            id: 'filtered_prospect',
            label: 'Filtered Prospect',
            class: FilteredProspect::class,
            keys: ['id' => 'fpid', 'uuid' => 'uuid', 'label' => 'title'],
            fieldDefinitions: [
                'fpid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'external_id' => ['type' => 'string'],
                'title' => ['type' => 'string', 'required' => true],
                'description' => ['type' => 'text_long'],
                'reject_reason' => ['type' => 'string'],
                'import_batch' => ['type' => 'string'],
                'workspace_uuid' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityTypeManager->registerCoreEntityType(new EntityType(
            id: 'pipeline_config',
            label: 'Pipeline Config',
            class: PipelineConfig::class,
            keys: ['id' => 'pcid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'pcid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'name' => ['type' => 'string', 'required' => true],
                'workspace_uuid' => ['type' => 'string', 'required' => true],
                'source_type' => ['type' => 'string'],
                'source_url' => ['type' => 'string'],
                'sectors' => ['type' => 'text_long'],
                'company_profile' => ['type' => 'text_long'],
                'qualification_prompt_override' => ['type' => 'text_long'],
                'auto_qualify' => ['type' => 'boolean'],
                'leads_api_bearer' => ['type' => 'string'],
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
    public function all_admin_entity_types_exist_in_schema(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();

        self::assertNotNull($queryType);

        // All admin entity types produce query fields
        self::assertTrue($queryType->hasField('commitment'));
        self::assertTrue($queryType->hasField('commitmentList'));
        self::assertTrue($queryType->hasField('person'));
        self::assertTrue($queryType->hasField('personList'));
        self::assertTrue($queryType->hasField('workspace'));
        self::assertTrue($queryType->hasField('workspaceList'));
        self::assertTrue($queryType->hasField('scheduleEntry'));
        self::assertTrue($queryType->hasField('scheduleEntryList'));
        self::assertTrue($queryType->hasField('triageEntry'));
        self::assertTrue($queryType->hasField('triageEntryList'));
        self::assertTrue($queryType->hasField('prospect'));
        self::assertTrue($queryType->hasField('prospectList'));
        self::assertTrue($queryType->hasField('prospectAttachment'));
        self::assertTrue($queryType->hasField('prospectAttachmentList'));
        self::assertTrue($queryType->hasField('prospectAudit'));
        self::assertTrue($queryType->hasField('prospectAuditList'));
        self::assertTrue($queryType->hasField('filteredProspect'));
        self::assertTrue($queryType->hasField('filteredProspectList'));
        self::assertTrue($queryType->hasField('pipelineConfig'));
        self::assertTrue($queryType->hasField('pipelineConfigList'));
    }

    #[Test]
    public function commitment_mutations_exist(): void
    {
        $schema = $this->buildSchema();
        $mutationType = $schema->getMutationType();

        self::assertNotNull($mutationType);
        self::assertTrue($mutationType->hasField('createCommitment'));
        self::assertTrue($mutationType->hasField('updateCommitment'));
        self::assertTrue($mutationType->hasField('deleteCommitment'));
    }

    #[Test]
    public function person_mutations_exist(): void
    {
        $schema = $this->buildSchema();
        $mutationType = $schema->getMutationType();

        self::assertNotNull($mutationType);
        self::assertTrue($mutationType->hasField('createPerson'));
        self::assertTrue($mutationType->hasField('updatePerson'));
        self::assertTrue($mutationType->hasField('deletePerson'));
    }

    #[Test]
    public function commitment_type_has_expected_fields(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();
        $commitmentField = $queryType->getField('commitment');
        $commitmentType = $commitmentField->getType();

        self::assertInstanceOf(ObjectType::class, $commitmentType);

        $expectedFields = ['id', 'uuid', 'title', 'status', 'confidence', 'due_date', 'person_uuid', 'workspace_uuid', 'source', 'tenant_id', 'importance_score', 'access_count', 'last_accessed_at', 'created_at', 'updated_at'];
        foreach ($expectedFields as $fieldName) {
            self::assertTrue($commitmentType->hasField($fieldName), "Commitment missing field: {$fieldName}");
        }

        // Verify key type mappings
        self::assertSame('Float', $this->unwrapTypeName($commitmentType->getField('confidence')->getType()));
        self::assertSame('String', $this->unwrapTypeName($commitmentType->getField('status')->getType()));
    }

    #[Test]
    public function person_type_has_expected_fields(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();
        $personField = $queryType->getField('person');
        $personType = $personField->getType();

        self::assertInstanceOf(ObjectType::class, $personType);

        $expectedFields = ['id', 'uuid', 'name', 'email', 'tier', 'source', 'tenant_id', 'latest_summary', 'last_interaction_at', 'last_inbox_category', 'importance_score', 'access_count', 'last_accessed_at', 'created_at', 'updated_at'];
        foreach ($expectedFields as $fieldName) {
            self::assertTrue($personType->hasField($fieldName), "Person missing field: {$fieldName}");
        }

        // Verify key type mappings
        self::assertSame('String', $this->unwrapTypeName($personType->getField('email')->getType()));
        self::assertSame('String', $this->unwrapTypeName($personType->getField('tier')->getType()));
    }

    #[Test]
    public function commitment_create_input_excludes_read_only_fields(): void
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
    public function person_create_input_excludes_read_only_fields(): void
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

    #[Test]
    public function commitment_list_returns_items_and_total(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();
        $listField = $queryType->getField('commitmentList');
        $listType = $listField->getType();

        self::assertInstanceOf(ObjectType::class, $listType);
        self::assertTrue($listType->hasField('items'), 'commitmentList missing field: items');
        self::assertTrue($listType->hasField('total'), 'commitmentList missing field: total');

        self::assertSame('Int', $this->unwrapTypeName($listType->getField('total')->getType()));
    }

    #[Test]
    public function person_list_returns_items_and_total(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();
        $listField = $queryType->getField('personList');
        $listType = $listField->getType();

        self::assertInstanceOf(ObjectType::class, $listType);
        self::assertTrue($listType->hasField('items'), 'personList missing field: items');
        self::assertTrue($listType->hasField('total'), 'personList missing field: total');

        self::assertSame('Int', $this->unwrapTypeName($listType->getField('total')->getType()));
    }

    #[Test]
    public function workspace_mutations_exist(): void
    {
        $schema = $this->buildSchema();
        $mutationType = $schema->getMutationType();

        self::assertNotNull($mutationType);
        self::assertTrue($mutationType->hasField('createWorkspace'));
        self::assertTrue($mutationType->hasField('updateWorkspace'));
        self::assertTrue($mutationType->hasField('deleteWorkspace'));
    }

    #[Test]
    public function schedule_entry_mutations_exist(): void
    {
        $schema = $this->buildSchema();
        $mutationType = $schema->getMutationType();

        self::assertNotNull($mutationType);
        self::assertTrue($mutationType->hasField('createScheduleEntry'));
        self::assertTrue($mutationType->hasField('updateScheduleEntry'));
        self::assertTrue($mutationType->hasField('deleteScheduleEntry'));
    }

    #[Test]
    public function triage_entry_mutations_exist(): void
    {
        $schema = $this->buildSchema();
        $mutationType = $schema->getMutationType();

        self::assertNotNull($mutationType);
        self::assertTrue($mutationType->hasField('createTriageEntry'));
        self::assertTrue($mutationType->hasField('updateTriageEntry'));
        self::assertTrue($mutationType->hasField('deleteTriageEntry'));
    }

    #[Test]
    public function workspace_type_has_expected_fields(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();
        $field = $queryType->getField('workspace');
        $type = $field->getType();

        self::assertInstanceOf(ObjectType::class, $type);

        $expectedFields = ['id', 'uuid', 'name', 'description', 'saved_context', 'anthropic_model', 'account_id', 'tenant_id', 'mode', 'status', 'created_at', 'updated_at'];
        foreach ($expectedFields as $fieldName) {
            self::assertTrue($type->hasField($fieldName), "Workspace missing field: {$fieldName}");
        }
    }

    #[Test]
    public function schedule_entry_type_has_expected_fields(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();
        $field = $queryType->getField('scheduleEntry');
        $type = $field->getType();

        self::assertInstanceOf(ObjectType::class, $type);

        $expectedFields = ['id', 'uuid', 'title', 'starts_at', 'ends_at', 'notes', 'source', 'status', 'external_id', 'calendar_id', 'recurring_series_id', 'tenant_id', 'created_at', 'updated_at'];
        foreach ($expectedFields as $fieldName) {
            self::assertTrue($type->hasField($fieldName), "ScheduleEntry missing field: {$fieldName}");
        }
    }

    #[Test]
    public function triage_entry_type_has_expected_fields(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();
        $field = $queryType->getField('triageEntry');
        $type = $field->getType();

        self::assertInstanceOf(ObjectType::class, $type);

        $expectedFields = ['id', 'uuid', 'sender_name', 'sender_email', 'summary', 'status', 'source', 'tenant_id', 'occurred_at', 'external_id', 'content_hash', 'raw_payload', 'created_at', 'updated_at'];
        foreach ($expectedFields as $fieldName) {
            self::assertTrue($type->hasField($fieldName), "TriageEntry missing field: {$fieldName}");
        }

        self::assertSame('TextValue', $this->unwrapTypeName($type->getField('raw_payload')->getType()));
    }

    #[Test]
    public function workspace_create_input_excludes_read_only_fields(): void
    {
        $schema = $this->buildSchema();
        $mutationType = $schema->getMutationType();
        $createField = $mutationType->getField('createWorkspace');
        $inputType = $createField->getArg('input')->getType();

        if ($inputType instanceof NonNull) {
            $inputType = $inputType->getWrappedType();
        }

        self::assertInstanceOf(InputObjectType::class, $inputType);

        self::assertTrue($inputType->hasField('name'));
        self::assertTrue($inputType->hasField('description'));
        self::assertTrue($inputType->hasField('anthropic_model'));

        self::assertFalse($inputType->hasField('wid'));
        self::assertFalse($inputType->hasField('id'));
        self::assertFalse($inputType->hasField('uuid'));
        self::assertFalse($inputType->hasField('created_at'));
        self::assertFalse($inputType->hasField('updated_at'));
    }

    #[Test]
    public function schedule_entry_create_input_excludes_read_only_fields(): void
    {
        $schema = $this->buildSchema();
        $mutationType = $schema->getMutationType();
        $createField = $mutationType->getField('createScheduleEntry');
        $inputType = $createField->getArg('input')->getType();

        if ($inputType instanceof NonNull) {
            $inputType = $inputType->getWrappedType();
        }

        self::assertInstanceOf(InputObjectType::class, $inputType);

        self::assertTrue($inputType->hasField('title'));
        self::assertTrue($inputType->hasField('starts_at'));

        self::assertFalse($inputType->hasField('seid'));
        self::assertFalse($inputType->hasField('id'));
        self::assertFalse($inputType->hasField('uuid'));
        self::assertFalse($inputType->hasField('created_at'));
        self::assertFalse($inputType->hasField('updated_at'));
    }

    #[Test]
    public function triage_entry_create_input_excludes_read_only_fields(): void
    {
        $schema = $this->buildSchema();
        $mutationType = $schema->getMutationType();
        $createField = $mutationType->getField('createTriageEntry');
        $inputType = $createField->getArg('input')->getType();

        if ($inputType instanceof NonNull) {
            $inputType = $inputType->getWrappedType();
        }

        self::assertInstanceOf(InputObjectType::class, $inputType);

        self::assertTrue($inputType->hasField('sender_name'));
        self::assertTrue($inputType->hasField('summary'));

        self::assertFalse($inputType->hasField('teid'));
        self::assertFalse($inputType->hasField('id'));
        self::assertFalse($inputType->hasField('uuid'));
        self::assertFalse($inputType->hasField('created_at'));
        self::assertFalse($inputType->hasField('updated_at'));
    }

    #[Test]
    public function workspace_list_returns_items_and_total(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();
        $listField = $queryType->getField('workspaceList');
        $listType = $listField->getType();

        self::assertInstanceOf(ObjectType::class, $listType);
        self::assertTrue($listType->hasField('items'), 'workspaceList missing field: items');
        self::assertTrue($listType->hasField('total'), 'workspaceList missing field: total');

        self::assertSame('Int', $this->unwrapTypeName($listType->getField('total')->getType()));
    }

    #[Test]
    public function schedule_entry_list_returns_items_and_total(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();
        $listField = $queryType->getField('scheduleEntryList');
        $listType = $listField->getType();

        self::assertInstanceOf(ObjectType::class, $listType);
        self::assertTrue($listType->hasField('items'), 'scheduleEntryList missing field: items');
        self::assertTrue($listType->hasField('total'), 'scheduleEntryList missing field: total');

        self::assertSame('Int', $this->unwrapTypeName($listType->getField('total')->getType()));
    }

    #[Test]
    public function triage_entry_list_returns_items_and_total(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();
        $listField = $queryType->getField('triageEntryList');
        $listType = $listField->getType();

        self::assertInstanceOf(ObjectType::class, $listType);
        self::assertTrue($listType->hasField('items'), 'triageEntryList missing field: items');
        self::assertTrue($listType->hasField('total'), 'triageEntryList missing field: total');

        self::assertSame('Int', $this->unwrapTypeName($listType->getField('total')->getType()));
    }

    #[Test]
    public function prospect_queries_and_mutations_exist(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();
        $mutationType = $schema->getMutationType();

        self::assertNotNull($queryType);
        self::assertNotNull($mutationType);

        self::assertTrue($queryType->hasField('prospect'));
        self::assertTrue($queryType->hasField('prospectList'));
        self::assertTrue($mutationType->hasField('createProspect'));
        self::assertTrue($mutationType->hasField('updateProspect'));
        self::assertTrue($mutationType->hasField('deleteProspect'));
    }

    #[Test]
    public function prospect_type_has_expected_fields(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();
        $type = $queryType->getField('prospect')->getType();

        self::assertInstanceOf(ObjectType::class, $type);

        $expectedFields = ['id', 'uuid', 'name', 'description', 'stage', 'value', 'contact_name', 'contact_email', 'source_url', 'closing_date', 'sector', 'qualify_rating', 'qualify_confidence', 'external_id', 'workspace_uuid', 'person_uuid', 'tenant_id', 'created_at', 'updated_at'];
        foreach ($expectedFields as $fieldName) {
            self::assertTrue($type->hasField($fieldName), "Prospect missing field: {$fieldName}");
        }

        self::assertSame('Int', $this->unwrapTypeName($type->getField('qualify_rating')->getType()));
        self::assertSame('Float', $this->unwrapTypeName($type->getField('qualify_confidence')->getType()));
    }

    #[Test]
    public function prospect_create_input_excludes_read_only_fields(): void
    {
        $schema = $this->buildSchema();
        $mutationType = $schema->getMutationType();
        $createField = $mutationType->getField('createProspect');
        $inputType = $createField->getArg('input')->getType();

        if ($inputType instanceof NonNull) {
            $inputType = $inputType->getWrappedType();
        }

        self::assertInstanceOf(InputObjectType::class, $inputType);

        self::assertTrue($inputType->hasField('name'));
        self::assertTrue($inputType->hasField('stage'));
        self::assertTrue($inputType->hasField('workspace_uuid'));

        self::assertFalse($inputType->hasField('prid'));
        self::assertFalse($inputType->hasField('id'));
        self::assertFalse($inputType->hasField('uuid'));
        self::assertFalse($inputType->hasField('created_at'));
        self::assertFalse($inputType->hasField('updated_at'));
    }

    #[Test]
    public function prospect_attachment_queries_and_mutations_exist(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();
        $mutationType = $schema->getMutationType();

        self::assertTrue($queryType->hasField('prospectAttachment'));
        self::assertTrue($queryType->hasField('prospectAttachmentList'));
        self::assertTrue($mutationType->hasField('createProspectAttachment'));
        self::assertTrue($mutationType->hasField('deleteProspectAttachment'));
    }

    #[Test]
    public function prospect_audit_queries_and_mutations_exist(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();
        $mutationType = $schema->getMutationType();

        self::assertTrue($queryType->hasField('prospectAudit'));
        self::assertTrue($queryType->hasField('prospectAuditList'));
        self::assertTrue($mutationType->hasField('createProspectAudit'));
        self::assertTrue($mutationType->hasField('deleteProspectAudit'));
    }

    #[Test]
    public function filtered_prospect_queries_and_mutations_exist(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();
        $mutationType = $schema->getMutationType();

        self::assertTrue($queryType->hasField('filteredProspect'));
        self::assertTrue($queryType->hasField('filteredProspectList'));
        self::assertTrue($mutationType->hasField('createFilteredProspect'));
        self::assertTrue($mutationType->hasField('deleteFilteredProspect'));
    }

    #[Test]
    public function pipeline_config_queries_and_mutations_exist(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();
        $mutationType = $schema->getMutationType();

        self::assertTrue($queryType->hasField('pipelineConfig'));
        self::assertTrue($queryType->hasField('pipelineConfigList'));
        self::assertTrue($mutationType->hasField('createPipelineConfig'));
        self::assertTrue($mutationType->hasField('updatePipelineConfig'));
        self::assertTrue($mutationType->hasField('deletePipelineConfig'));
    }

    #[Test]
    public function pipeline_config_type_has_expected_fields(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();
        $type = $queryType->getField('pipelineConfig')->getType();

        self::assertInstanceOf(ObjectType::class, $type);

        $expectedFields = ['id', 'uuid', 'name', 'workspace_uuid', 'source_type', 'source_url', 'sectors', 'company_profile', 'qualification_prompt_override', 'auto_qualify', 'leads_api_bearer', 'tenant_id', 'created_at', 'updated_at'];
        foreach ($expectedFields as $fieldName) {
            self::assertTrue($type->hasField($fieldName), "PipelineConfig missing field: {$fieldName}");
        }
    }

    #[Test]
    public function prospect_list_returns_items_and_total(): void
    {
        $schema = $this->buildSchema();
        $queryType = $schema->getQueryType();
        $listField = $queryType->getField('prospectList');
        $listType = $listField->getType();

        self::assertInstanceOf(ObjectType::class, $listType);
        self::assertTrue($listType->hasField('items'), 'prospectList missing field: items');
        self::assertTrue($listType->hasField('total'), 'prospectList missing field: total');
    }

    private function unwrapTypeName(Type $type): string
    {
        if ($type instanceof NonNull) {
            $type = $type->getWrappedType();
        }

        return $type->name;
    }
}
