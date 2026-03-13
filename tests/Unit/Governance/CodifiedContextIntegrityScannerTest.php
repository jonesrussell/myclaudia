<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Governance;

use Claudriel\Service\Governance\CodifiedContextIntegrityScanner;
use PHPUnit\Framework\TestCase;

final class CodifiedContextIntegrityScannerTest extends TestCase
{
    private ?string $projectRoot = null;

    protected function tearDown(): void
    {
        if ($this->projectRoot !== null && is_dir($this->projectRoot)) {
            $this->removeDirectory($this->projectRoot);
        }

        parent::tearDown();
    }

    public function test_scan_detects_missing_fields_threshold_mismatches_missing_templates_and_missing_template_variables(): void
    {
        $scanner = $this->buildBrokenFixtureScanner();

        $scan = $scanner->scan();
        $codes = array_column($scan['issues'], 'code');

        self::assertContains('drift_threshold_severe_out_of_sync', $codes);
        self::assertContains('missing_date_heuristics', $codes);
        self::assertContains('missing_template_'.md5('ai/improvement-suggestions/index.twig'), $codes);
        self::assertContains(
            'template_variable_missing_'.md5('templates/ai/self-assessment/index.twig'.'focus_summary'),
            $codes,
        );
    }

    public function test_scan_detects_missing_audit_methods_when_contract_requires_them(): void
    {
        $scanner = new CodifiedContextIntegrityScanner(
            __DIR__.'/../../..',
            ['audit_methods' => ['getDailyTrends', 'getMissingGovernanceMethod']],
            new \DateTimeImmutable('2026-03-13 12:00:00'),
        );

        $scan = $scanner->scan();
        $codes = array_column($scan['issues'], 'code');

        self::assertContains('audit_method_missing_getMissingGovernanceMethod', $codes);
    }

    public function test_classify_issues_and_summarize_return_expected_governance_output(): void
    {
        $scanner = new CodifiedContextIntegrityScanner(__DIR__.'/../../..', null, new \DateTimeImmutable('2026-03-13 12:00:00'));
        $issues = [
            ['code' => 'missing_date_heuristics', 'message' => 'Date heuristics missing.', 'context' => 'fixture'],
            ['code' => 'drift_threshold_severe_out_of_sync', 'message' => 'Threshold mismatch.', 'context' => 'fixture'],
            ['code' => 'governance_note', 'message' => 'Informational.', 'context' => 'fixture'],
        ];

        $classifications = $scanner->classifyIssues($issues);
        $summary = $scanner->summarize($issues);

        self::assertSame('critical', $classifications['missing_date_heuristics']);
        self::assertSame('warning', $classifications['drift_threshold_severe_out_of_sync']);
        self::assertSame('info', $classifications['governance_note']);
        self::assertStringContainsString('3 issues', $summary);
        self::assertStringContainsString('1 critical, 1 warning, and 1 informational', $summary);
    }

    private function buildBrokenFixtureScanner(): CodifiedContextIntegrityScanner
    {
        $this->projectRoot = sys_get_temp_dir().'/claudriel-integrity-'.bin2hex(random_bytes(6));

        mkdir($this->projectRoot.'/src/Service/Audit', 0755, true);
        mkdir($this->projectRoot.'/src/Controller/Ai', 0755, true);
        mkdir($this->projectRoot.'/src/Controller/Audit', 0755, true);
        mkdir($this->projectRoot.'/src/Service/Ai', 0755, true);
        mkdir($this->projectRoot.'/templates/ai/self-assessment', 0755, true);

        file_put_contents($this->projectRoot.'/src/Service/Audit/CommitmentExtractionFailureClassifier.php', <<<'PHP'
<?php
final class CommitmentExtractionFailureClassifier
{
    public function classify(array $mcEvent, ?array $extractedCommitment, float $confidence): string
    {
        $fields = ['title', 'action', 'summary', 'text', 'description'];
        $personFields = ['person', 'person_id', 'person_email'];
        return 'unknown';
    }
}
PHP);
        file_put_contents($this->projectRoot.'/src/Service/Audit/CommitmentExtractionDriftDetector.php', <<<'PHP'
<?php
final class CommitmentExtractionDriftDetector
{
    public function classifyDrift(array $delta): string
    {
        return match (true) {
            $delta['avg_confidence_drop'] > 0.25 => 'severe',
            $delta['avg_confidence_drop'] > 0.08 => 'moderate',
            $delta['avg_confidence_drop'] > 0.03 => 'minor',
            default => 'none',
        };
    }
}
PHP);
        file_put_contents($this->projectRoot.'/src/Controller/Ai/ExtractionSelfAssessmentController.php', <<<'PHP'
<?php
final class ExtractionSelfAssessmentController
{
    public function index(): void
    {
        $this->twig->render('ai/self-assessment/index.twig', ['assessment' => []]);
    }
}
PHP);
        file_put_contents($this->projectRoot.'/src/Controller/Ai/ExtractionImprovementSuggestionController.php', <<<'PHP'
<?php
final class ExtractionImprovementSuggestionController
{
    public function index(): void
    {
        $this->twig->render('ai/improvement-suggestions/index.twig', ['report' => []]);
    }
}
PHP);
        file_put_contents($this->projectRoot.'/src/Controller/Audit/CommitmentExtractionAuditController.php', <<<'PHP'
<?php
final class CommitmentExtractionAuditController
{
    public function index(): void
    {
        $this->twig->render('audit/commitment-extraction/index.twig', []);
    }
}
PHP);
        file_put_contents($this->projectRoot.'/src/Service/Ai/TrainingExportService.php', <<<'PHP'
<?php
final class TrainingExportService
{
    private function build(): array
    {
        return ['mc_event_id' => 1, 'raw_event_payload' => '{}', 'confidence' => 0.5, 'label' => 'success'];
    }
}
PHP);
        file_put_contents($this->projectRoot.'/src/Service/Ai/ModelUpdateBatchGenerator.php', <<<'PHP'
<?php
final class ModelUpdateBatchGenerator
{
    public function generateBatch(): array
    {
        return ['metadata' => ['generated_at' => '', 'window_days' => 14]];
    }
}
PHP);
        file_put_contents($this->projectRoot.'/templates/ai/self-assessment/index.twig', <<<'TWIG'
<h1>{{ assessment.overall_score }}</h1>
<div>{{ assessment.sender_hotspots|length }}</div>
TWIG);

        return new CodifiedContextIntegrityScanner(
            $this->projectRoot,
            [
                'controller_templates' => [
                    'src/Controller/Ai/ExtractionSelfAssessmentController.php' => ['ai/self-assessment/index.twig'],
                    'src/Controller/Ai/ExtractionImprovementSuggestionController.php' => ['ai/improvement-suggestions/index.twig'],
                ],
                'template_required_variables' => [
                    'templates/ai/self-assessment/index.twig' => ['assessment.overall_score', 'focus_summary'],
                ],
            ],
            new \DateTimeImmutable('2026-03-13 12:00:00'),
        );
    }

    private function removeDirectory(string $directory): void
    {
        $entries = scandir($directory);
        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);

                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
