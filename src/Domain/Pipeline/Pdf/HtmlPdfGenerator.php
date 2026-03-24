<?php

declare(strict_types=1);

namespace Claudriel\Domain\Pipeline\Pdf;

use Claudriel\Entity\PipelineConfig;
use Claudriel\Entity\Prospect;

final class HtmlPdfGenerator implements PdfGeneratorInterface
{
    public function __construct(
        private readonly BrandedResponseBuilder $builder = new BrandedResponseBuilder,
    ) {}

    public function generate(Prospect $prospect, PipelineConfig $config): string
    {
        $html = $this->builder->buildHtml($prospect, $config);

        $tmpDir = sys_get_temp_dir().'/claudriel-pdf-'.uniqid();
        mkdir($tmpDir, 0o755, true);

        $htmlFile = $tmpDir.'/response.html';
        $pdfFile = $tmpDir.'/response.pdf';
        file_put_contents($htmlFile, $html);

        // Try wkhtmltopdf first, then Chrome headless
        $commands = [
            ['wkhtmltopdf', '--quiet', $htmlFile, $pdfFile],
            ['chromium', '--headless', '--disable-gpu', '--print-to-pdf='.$pdfFile, $htmlFile],
            ['google-chrome', '--headless', '--disable-gpu', '--print-to-pdf='.$pdfFile, $htmlFile],
        ];

        foreach ($commands as $command) {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open($command, $descriptors, $pipes);
            if (! is_resource($process)) {
                continue;
            }

            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            proc_close($process);

            if (file_exists($pdfFile)) {
                return $pdfFile;
            }
        }

        throw new \RuntimeException('No PDF renderer available (tried wkhtmltopdf, chromium, google-chrome).');
    }

    public function isAvailable(): bool
    {
        foreach (['wkhtmltopdf', 'chromium', 'google-chrome'] as $binary) {
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open([$binary, '--version'], $descriptors, $pipes);
            if (! is_resource($process)) {
                continue;
            }
            stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            if (proc_close($process) === 0) {
                return true;
            }
        }

        return false;
    }
}
