<?php

declare(strict_types=1);

namespace Claudriel\Service\Governance;

use Claudriel\Entity\CommitmentExtractionLog;
use Claudriel\Service\Audit\CommitmentExtractionAuditService;
use DateTimeImmutable;
use DateTimeInterface;
use ReflectionClass;
use Throwable;

final class CodifiedContextIntegrityScanner
{
    /**
     * @param array{
     *   expected_failure_categories?: list<string>,
     *   documented_drift_thresholds?: array{severe: float, moderate: float, minor: float},
     *   required_heuristic_fields?: array{
     *     text_fields: list<string>,
     *     date_fields: list<string>,
     *     person_fields: list<string>
     *   },
     *   controller_templates?: array<string, list<string>>,
     *   template_required_variables?: array<string, list<string>>,
     *   audit_methods?: list<string>,
     *   training_export_fields?: list<string>,
     *   training_export_commitment_fields?: list<string>,
     *   batch_metadata_keys?: list<string>
     * }|null $contracts
     */
    public function __construct(
        private readonly string $projectRoot = __DIR__.'/../../..',
        private readonly ?array $contracts = null,
        private readonly ?DateTimeImmutable $referenceDate = null,
    ) {}

    /**
     * @return array{
     *   generated_at: string,
     *   issues: list<array{code: string, message: string, context: string}>
     * }
     */
    public function scan(): array
    {
        $issues = [];
        $contracts = $this->contracts();

        $issues = [...$issues, ...$this->scanFailureCategories($contracts['expected_failure_categories'])];
        $issues = [...$issues, ...$this->scanDriftThresholds($contracts['documented_drift_thresholds'])];
        $issues = [...$issues, ...$this->scanExtractionHeuristics($contracts['required_heuristic_fields'])];
        $issues = [...$issues, ...$this->scanControllerTemplates($contracts['controller_templates'])];
        $issues = [...$issues, ...$this->scanTemplateVariables($contracts['template_required_variables'])];
        $issues = [...$issues, ...$this->scanAuditMethods($contracts['audit_methods'])];
        $issues = [...$issues, ...$this->scanTrainingExportFields(
            $contracts['training_export_fields'],
            $contracts['training_export_commitment_fields'],
        )];
        $issues = [...$issues, ...$this->scanBatchMetadataKeys($contracts['batch_metadata_keys'])];

        return [
            'generated_at' => ($this->referenceDate ?? new DateTimeImmutable)->format(DateTimeInterface::ATOM),
            'issues' => $issues,
        ];
    }

    /**
     * @param  list<array{code: string, message: string, context: string}>  $issues
     * @return array<string, string>
     */
    public function classifyIssues(array $issues): array
    {
        $classifications = [];

        foreach ($issues as $issue) {
            $classifications[$issue['code']] = match (true) {
                str_starts_with($issue['code'], 'missing_'),
                str_starts_with($issue['code'], 'controller_'),
                str_starts_with($issue['code'], 'template_'),
                str_starts_with($issue['code'], 'training_export_'),
                str_starts_with($issue['code'], 'batch_') => 'critical',
                str_starts_with($issue['code'], 'drift_'),
                str_starts_with($issue['code'], 'failure_category_'),
                str_starts_with($issue['code'], 'audit_') => 'warning',
                default => 'info',
            };
        }

        return $classifications;
    }

    /**
     * @param  list<array{code: string, message: string, context: string}>  $issues
     */
    public function summarize(array $issues): string
    {
        if ($issues === []) {
            return 'Codified context integrity is healthy. No governance issues were detected in the current scan.';
        }

        $classifications = $this->classifyIssues($issues);
        $critical = count(array_filter($classifications, static fn (string $severity): bool => $severity === 'critical'));
        $warning = count(array_filter($classifications, static fn (string $severity): bool => $severity === 'warning'));
        $info = count(array_filter($classifications, static fn (string $severity): bool => $severity === 'info'));

        return sprintf(
            'Integrity scan detected %d issue%s: %d critical, %d warning, and %d informational. Review template wiring, service contracts, and governance heuristics before the next model update.',
            count($issues),
            count($issues) === 1 ? '' : 's',
            $critical,
            $warning,
            $info,
        );
    }

    /**
     * @return array{
     *   expected_failure_categories: list<string>,
     *   documented_drift_thresholds: array{severe: float, moderate: float, minor: float},
     *   required_heuristic_fields: array{text_fields: list<string>, date_fields: list<string>, person_fields: list<string>},
     *   controller_templates: array<string, list<string>>,
     *   template_required_variables: array<string, list<string>>,
     *   audit_methods: list<string>,
     *   training_export_fields: list<string>,
     *   training_export_commitment_fields: list<string>,
     *   batch_metadata_keys: list<string>
     * }
     */
    private function contracts(): array
    {
        return array_replace_recursive([
            'expected_failure_categories' => [
                'ambiguous',
                'insufficient_context',
                'non_actionable',
                'model_parse_error',
                'unknown',
            ],
            'documented_drift_thresholds' => [
                'severe' => 0.15,
                'moderate' => 0.08,
                'minor' => 0.03,
            ],
            'required_heuristic_fields' => [
                'text_fields' => ['title', 'action', 'summary', 'text', 'description'],
                'date_fields' => ['date', 'due_date', 'deadline', 'scheduled_for'],
                'person_fields' => ['person', 'person_id', 'person_email', 'person_name', 'assignee'],
            ],
            'controller_templates' => [
                'src/Controller/Audit/CommitmentExtractionAuditController.php' => [
                    'audit/commitment-extraction/index.twig',
                    'audit/commitment-extraction/show.twig',
                    'audit/commitment-extraction/trends.twig',
                    'audit/commitment-extraction/sender-trends.twig',
                    'audit/commitment-extraction/drift.twig',
                    'audit/commitment-extraction/sender-drift.twig',
                ],
                'src/Controller/Ai/ExtractionSelfAssessmentController.php' => [
                    'ai/self-assessment/index.twig',
                ],
                'src/Controller/Ai/ExtractionImprovementSuggestionController.php' => [
                    'ai/improvement-suggestions/index.twig',
                ],
            ],
            'template_required_variables' => [
                'templates/audit/commitment-extraction/index.twig' => ['metrics.total_extraction_attempts', 'confidence_distribution', 'top_senders'],
                'templates/audit/commitment-extraction/trends.twig' => ['daily_trends_7', 'daily_trends_30', 'failure_category_distribution'],
                'templates/ai/self-assessment/index.twig' => ['assessment.overall_score', 'focus_summary', 'assessment.sender_hotspots'],
                'templates/ai/improvement-suggestions/index.twig' => ['report.suggestions', 'summary', 'report.drift.classification'],
            ],
            'audit_methods' => [
                'getDailyTrends',
                'getMonthlyTrends',
                'getSenderTrends',
                'getFailureCategoryCounts',
                'getFailureCategoryDistribution',
                'getSenderFailureCategories',
            ],
            'training_export_fields' => [
                'mc_event_id',
                'raw_event_payload',
                'extracted_commitment_payload',
                'confidence',
                'failure_category',
                'label',
                'occurred_at',
                'sender',
            ],
            'training_export_commitment_fields' => [
                'title',
                'confidence',
                'person_email',
                'due_date',
            ],
            'batch_metadata_keys' => [
                'generated_at',
                'window_days',
                'total_samples',
                'failure_rate',
                'drift_classification',
            ],
        ], $this->contracts ?? []);
    }

    /**
     * @param  list<string>  $expectedCategories
     * @return list<array{code: string, message: string, context: string}>
     */
    private function scanFailureCategories(array $expectedCategories): array
    {
        $issues = [];
        $actualCategories = CommitmentExtractionLog::FAILURE_CATEGORIES;

        if ($actualCategories !== $expectedCategories) {
            $issues[] = $this->issue(
                'failure_category_mismatch',
                'Failure categories do not match the expected governance contract.',
                'CommitmentExtractionLog::FAILURE_CATEGORIES',
            );
        }

        $classifierSource = $this->read('src/Service/Audit/CommitmentExtractionFailureClassifier.php');
        foreach ($expectedCategories as $category) {
            if ($category === 'unknown') {
                continue;
            }

            if (! str_contains($classifierSource, "'".$category."'")) {
                $issues[] = $this->issue(
                    'missing_failure_category_'.$category,
                    sprintf('Failure classifier does not reference the "%s" category.', $category),
                    'src/Service/Audit/CommitmentExtractionFailureClassifier.php',
                );
            }
        }

        return $issues;
    }

    /**
     * @param  array{severe: float, moderate: float, minor: float}  $thresholds
     * @return list<array{code: string, message: string, context: string}>
     */
    private function scanDriftThresholds(array $thresholds): array
    {
        $source = $this->read('src/Service/Audit/CommitmentExtractionDriftDetector.php');
        $issues = [];

        foreach ($thresholds as $label => $threshold) {
            if (! str_contains($source, '$confidenceDrop > '.$this->formatFloat($threshold))) {
                $issues[] = $this->issue(
                    'drift_threshold_'.$label.'_out_of_sync',
                    sprintf('Drift threshold for %s does not match the documented contract.', $label),
                    'src/Service/Audit/CommitmentExtractionDriftDetector.php',
                );
            }
        }

        return $issues;
    }

    /**
     * @param  array{text_fields: list<string>, date_fields: list<string>, person_fields: list<string>}  $fields
     * @return list<array{code: string, message: string, context: string}>
     */
    private function scanExtractionHeuristics(array $fields): array
    {
        $source = $this->read('src/Service/Audit/CommitmentExtractionFailureClassifier.php');
        $issues = [];

        foreach ($fields['text_fields'] as $field) {
            if (! str_contains($source, "'".$field."'")) {
                $issues[] = $this->issue(
                    'missing_heuristic_field_'.$field,
                    sprintf('Failure classifier is missing the "%s" text heuristic field.', $field),
                    'src/Service/Audit/CommitmentExtractionFailureClassifier.php',
                );
            }
        }

        foreach (['date_fields' => 'date', 'person_fields' => 'person'] as $group => $label) {
            $found = false;
            foreach ($fields[$group] as $field) {
                if (str_contains($source, "'".$field."'")) {
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                $issues[] = $this->issue(
                    'missing_'.$label.'_heuristics',
                    sprintf('Failure classifier is missing %s heuristic fields.', $label),
                    'src/Service/Audit/CommitmentExtractionFailureClassifier.php',
                );
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, list<string>>  $controllerTemplates
     * @return list<array{code: string, message: string, context: string}>
     */
    private function scanControllerTemplates(array $controllerTemplates): array
    {
        $issues = [];

        foreach ($controllerTemplates as $controllerPath => $templates) {
            $controllerSource = $this->read($controllerPath);
            foreach ($templates as $template) {
                if (! str_contains($controllerSource, "'".$template."'")) {
                    $issues[] = $this->issue(
                        'controller_template_mapping_missing_'.md5($controllerPath.$template),
                        sprintf('Controller %s is missing a render reference for %s.', basename($controllerPath), $template),
                        $controllerPath,
                    );

                    continue;
                }

                if (! is_file($this->path('templates/'.$template))) {
                    $issues[] = $this->issue(
                        'missing_template_'.md5($template),
                        sprintf('Template %s is referenced by %s but does not exist.', $template, basename($controllerPath)),
                        'templates/'.$template,
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * @param  array<string, list<string>>  $templateVariables
     * @return list<array{code: string, message: string, context: string}>
     */
    private function scanTemplateVariables(array $templateVariables): array
    {
        $issues = [];

        foreach ($templateVariables as $templatePath => $variables) {
            $templateSource = $this->read($templatePath);
            foreach ($variables as $variable) {
                if (! str_contains($templateSource, $variable)) {
                    $issues[] = $this->issue(
                        'template_variable_missing_'.md5($templatePath.$variable),
                        sprintf('Template %s is missing the required variable "%s".', basename($templatePath), $variable),
                        $templatePath,
                    );
                }
            }
        }

        return $issues;
    }

    /**
     * @param  list<string>  $auditMethods
     * @return list<array{code: string, message: string, context: string}>
     */
    private function scanAuditMethods(array $auditMethods): array
    {
        $issues = [];

        try {
            $reflection = new ReflectionClass(CommitmentExtractionAuditService::class);
            foreach ($auditMethods as $method) {
                if (! $reflection->hasMethod($method)) {
                    $issues[] = $this->issue(
                        'audit_method_missing_'.$method,
                        sprintf('Audit service is missing the expected aggregation method %s().', $method),
                        CommitmentExtractionAuditService::class,
                    );
                }
            }
        } catch (Throwable) {
            $issues[] = $this->issue(
                'audit_service_reflection_failed',
                'Audit service could not be reflected during the integrity scan.',
                CommitmentExtractionAuditService::class,
            );
        }

        return $issues;
    }

    /**
     * @param  list<string>  $exportFields
     * @param  list<string>  $commitmentFields
     * @return list<array{code: string, message: string, context: string}>
     */
    private function scanTrainingExportFields(array $exportFields, array $commitmentFields): array
    {
        $source = $this->read('src/Service/Ai/TrainingExportService.php');
        $issues = [];

        foreach ($exportFields as $field) {
            if (! str_contains($source, "'".$field."'")) {
                $issues[] = $this->issue(
                    'training_export_field_missing_'.$field,
                    sprintf('Training export service is missing the "%s" sample field.', $field),
                    'src/Service/Ai/TrainingExportService.php',
                );
            }
        }

        foreach ($commitmentFields as $field) {
            if (! str_contains($source, "'".$field."'")) {
                $issues[] = $this->issue(
                    'training_export_commitment_field_missing_'.$field,
                    sprintf('Training export service does not export the commitment field "%s".', $field),
                    'src/Service/Ai/TrainingExportService.php',
                );
            }
        }

        return $issues;
    }

    /**
     * @param  list<string>  $metadataKeys
     * @return list<array{code: string, message: string, context: string}>
     */
    private function scanBatchMetadataKeys(array $metadataKeys): array
    {
        $source = $this->read('src/Service/Ai/ModelUpdateBatchGenerator.php');
        $issues = [];

        foreach ($metadataKeys as $key) {
            if (! str_contains($source, "'".$key."'")) {
                $issues[] = $this->issue(
                    'batch_metadata_missing_'.$key,
                    sprintf('Model update batch generator is missing metadata key "%s".', $key),
                    'src/Service/Ai/ModelUpdateBatchGenerator.php',
                );
            }
        }

        return $issues;
    }

    /**
     * @return array{code: string, message: string, context: string}
     */
    private function issue(string $code, string $message, string $context): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'context' => $context,
        ];
    }

    private function read(string $relativePath): string
    {
        $path = $this->path($relativePath);
        if (! is_file($path)) {
            return '';
        }

        $contents = file_get_contents($path);

        return is_string($contents) ? $contents : '';
    }

    private function path(string $relativePath): string
    {
        return rtrim($this->projectRoot, '/').'/'.$relativePath;
    }

    private function formatFloat(float $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.2f', $value), '0'), '.');

        return str_contains($formatted, '.') ? $formatted : $formatted.'.0';
    }
}
