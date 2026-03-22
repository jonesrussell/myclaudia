# Entity CRUD Skill Template

Base template for entity lifecycle skills. All Claudriel entity skills MUST follow this architecture.

## Architecture Rules

1. **Skills orchestrate, they do not implement.** Skills parse user intent and call Claudriel's GraphQL API. They never manipulate storage, create directories, or invent entity semantics.

2. **GraphQL is the CRUD interface.** All entity mutations go through `POST /graphql`. No direct database access, no filesystem manipulation for entities.

3. **Access policies are enforced by the API.** Skills never bypass them. If the API rejects an operation, surface the error to the user.

4. **Intent parsing comes first.** Never use the full user sentence as a field value. Extract the entity identifier, the operation, and any secondary intents before calling the API.

## Required Sections

Every entity CRUD skill MUST include these operations:

### Operation Detection

```
| Signal | Operation |
|--------|-----------|
| "create", "new", "add", "set up" | **Create** |
| "list", "show", "what {{entities}}" | **List** |
| "update", "change", "rename", "edit" | **Update** |
| "delete", "remove", "get rid of" | **Delete** |
```

If ambiguous, ask. Default assumption for bare entity mentions is **not** delete.

### Create

1. **Parse intent** — extract entity name/fields from the user's request
2. **Gather missing required fields** — ask only for what wasn't provided
3. **Confirm before creating** — show the fields that will be sent
4. **Call GraphQL mutation**:
   ```graphql
   mutation {
     create{{EntityType}}(input: { ...fields }) {
       id
       uuid
       ...returnFields
     }
   }
   ```
5. **Report result** — show created entity with UUID

### List

1. **Call GraphQL query**:
   ```graphql
   query {
     {{entityType}}List(limit: N, filter: { ...criteria }) {
       total
       items { id uuid ...displayFields }
     }
   }
   ```
2. **Present as table** with key fields
3. **If filter provided** (e.g., "show active workspaces"), pass as query filter

### Read (Single)

1. **Resolve entity reference** — match user's mention against known entities (by name, UUID, or recent context)
2. **Call GraphQL query**:
   ```graphql
   query {
     {{entityType}}(id: "uuid") {
       ...allFields
     }
   }
   ```
3. **Present formatted details**

### Update

1. **Resolve entity reference**
2. **Determine which fields to change** — from user request or by asking
3. **Confirm changes** — show before/after
4. **Call GraphQL mutation**:
   ```graphql
   mutation {
     update{{EntityType}}(id: "uuid", input: { ...changedFields }) {
       id
       uuid
       ...returnFields
     }
   }
   ```
5. **Report result**

### Delete

1. **Resolve entity reference**
2. **Show what will be deleted** — entity name and key details
3. **Require explicit confirmation** — for destructive operations, require the entity name echoed back, not just "yes"
4. **Call GraphQL mutation**:
   ```graphql
   mutation {
     delete{{EntityType}}(id: "uuid") {
       success
     }
   }
   ```
5. **Report result**

## Entity Reference Resolution (Resolve-First for Update/Delete)

For **update** and **delete** operations, always resolve against existing entities **before** parsing the user's sentence for a name. Entity names may contain conjunctions, prepositions, or full sentences.

1. Fetch existing entities via the list query
2. Match the user's reference against returned entity names (substring or fuzzy match)
3. If exactly one match, use it
4. If multiple matches, present them and ask
5. If no matches, say so and offer to create

**Do NOT split the user's input on conjunctions or heuristic word boundaries** when resolving existing entities. The entity name is whatever was stored at creation time.

For **create** operations, parse the name from the user's sentence using skill-specific heuristics (e.g., stop at conjunctions that introduce secondary intents). Never use the full user sentence as a field value.

## Multi-Step Workflows

Skills may orchestrate multi-step workflows (e.g., "create a workspace and link repo X"). Each step MUST:

1. Call the correct API endpoint
2. Use the response from the previous step (e.g., the UUID of the created entity)
3. Confirm intermediate results if the workflow has destructive steps

## Confirmation Rules

| Operation | Confirmation Required |
|-----------|----------------------|
| Create | Show fields, ask "Create this?" |
| List/Read | No confirmation needed |
| Update | Show before/after diff |
| Delete | Require entity name echo-back |

## Template Variables

When creating a new entity CRUD skill, replace:

- `{{EntityType}}` — PascalCase entity name (e.g., `Workspace`)
- `{{entityType}}` — camelCase entity name (e.g., `workspace`)
- `{{entities}}` — plural display name (e.g., `workspaces`)
- `{{displayFields}}` — fields to show in list view
- `{{returnFields}}` — fields to return after mutations
- `{{requiredFields}}` — fields required for creation
