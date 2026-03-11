# AI Pipeline Specification

## File Map

| File | Purpose |
|------|---------|
| `src/Pipeline/CommitmentExtractionStep.php` | AI pipeline step: extracts commitment candidates from message body |

## Waaseyaa AI Pipeline Interfaces

```php
// PipelineStepInterface (Waaseyaa\AI\Pipeline)
public function process(array $input, PipelineContext $context): StepResult;
public function describe(): string;

// StepResult (Waaseyaa\AI\Pipeline)
StepResult::success(array $output = [], string $message = ''): self
StepResult::failure(string $message, array $output = []): self
// Properties: bool $success, array $output, string $message, bool $stopPipeline

// PipelineContext (Waaseyaa\AI\Pipeline)
public readonly string $pipelineId;
public readonly int $startedAt;
public function get(string $key, mixed $default = null): mixed;
public function set(string $key, mixed $value): void;
```

## CommitmentExtractionStep Data Flow

```
Input:
  $input['body']       string  — email body text
  $input['from_email'] string  — sender email (used in prompt context)

AI prompt → $this->aiClient->complete($prompt) → raw JSON string

Output (on success):
  $output['commitments'] = [
    ['title' => string, 'confidence' => float],
    ...
  ]

Failure: StepResult::failure('AI client returned invalid JSON...')
```

## AI Client Contract

`CommitmentExtractionStep` accepts `object $aiClient` (duck-typed). The client must implement:

```php
public function complete(string $prompt): string  // returns raw JSON string
```

The step expects the AI to return a JSON array: `[{"title": "...", "confidence": 0.0-1.0}, ...]` or `[]`.

## Prompt Template

```
You are an AI assistant extracting commitments from emails.
Email body: "{$body}"
Sender: {$fromEmail}
Return a JSON array of commitments. Each item: {"title": "...", "confidence": 0.0-1.0}.
Confidence > 0.7 means you are confident this is a real commitment.
Return [] if no commitments found. Return only valid JSON, no commentary.
```

## Downstream: CommitmentHandler

After extraction, pass output to `CommitmentHandler::handle()`:
- `$candidates` = `$stepResult->output['commitments']`
- Only candidates with `confidence >= 0.7` are saved as `Commitment` entities

## WorkspaceClassificationStep

`src/Pipeline/WorkspaceClassificationStep.php` — optional step that assigns a `workspace_id` to an event.

```
Input:
  $input['event_data']  array  — normalized event fields
  $input['workspaces']  array  — array of Workspace entities to match against

Output (on success):
  $output['workspace_id'] = string|null  — uuid of matched workspace, or null
```

The step uses heuristics or AI to decide which workspace the event belongs to. A `null` result means no workspace matched; the event is saved without a `workspace_id`.

## Adding More Pipeline Steps

Follow the same pattern:
1. Implement `PipelineStepInterface` in `src/Pipeline/`
2. `process()` receives `$input` (output of previous step) and returns `StepResult`
3. Use `$context->get()`/`set()` for cross-step state that doesn't fit in output arrays
