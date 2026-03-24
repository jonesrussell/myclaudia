<?php

declare(strict_types=1);

namespace Claudriel\Domain\Pipeline\Pdf;

use Claudriel\Entity\PipelineConfig;
use Claudriel\Entity\Prospect;

final class LatexPdfGenerator implements PdfGeneratorInterface
{
    public function __construct(
        private readonly BrandedResponseBuilder $builder = new BrandedResponseBuilder,
    ) {}

    public function generate(Prospect $prospect, PipelineConfig $config): string
    {
        $latex = $this->builder->buildLatex($prospect, $config);

        $tmpDir = sys_get_temp_dir().'/claudriel-pdf-'.uniqid();
        mkdir($tmpDir, 0o755, true);

        $texFile = $tmpDir.'/response.tex';
        file_put_contents($texFile, $latex);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            ['pdflatex', '-interaction=nonstopmode', '-output-directory', $tmpDir, $texFile],
            $descriptors,
            $pipes,
        );

        if (! is_resource($process)) {
            throw new \RuntimeException('Failed to start pdflatex process.');
        }

        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);

        $pdfFile = $tmpDir.'/response.pdf';
        if (! file_exists($pdfFile)) {
            throw new \RuntimeException('pdflatex did not produce a PDF file.');
        }

        return $pdfFile;
    }

    public function isAvailable(): bool
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open(['pdflatex', '--version'], $descriptors, $pipes);
        if (! is_resource($process)) {
            return false;
        }
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        return proc_close($process) === 0;
    }
}
