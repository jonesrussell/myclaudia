<?php

declare(strict_types=1);

namespace Claudriel\Domain\Pipeline\Pdf;

use Claudriel\Entity\PipelineConfig;
use Claudriel\Entity\Prospect;

final class BrandedResponseBuilder
{
    /**
     * Build LaTeX document source for a prospect response.
     */
    public function buildLatex(Prospect $prospect, PipelineConfig $config): string
    {
        $profile = $this->decodeProfile($config);
        $markdown = (string) ($prospect->get('draft_pdf_markdown') ?? '');
        $latexOverride = (string) ($prospect->get('draft_pdf_latex') ?? '');
        $contactName = (string) ($prospect->get('contact_name') ?? 'Hiring Manager');
        $prospectName = (string) ($prospect->get('name') ?? 'Untitled');

        // Use LaTeX override if provided, otherwise convert markdown
        $body = $latexOverride !== '' ? $latexOverride : $this->markdownToLatex($markdown);
        $body = str_replace('{{contact_name}}', $this->escapeLatex($contactName), $body);

        $date = date('F j, Y');

        return <<<LATEX
        \\documentclass[11pt,letterpaper]{article}
        \\usepackage[margin=1in]{geometry}
        \\usepackage{parskip}
        \\usepackage{hyperref}

        \\begin{document}

        \\noindent
        {$this->escapeLatex($profile['name'])} \\\\
        {$this->escapeLatex($profile['title'])} \\\\
        {$this->escapeLatex($profile['company'])} \\\\
        {$this->escapeLatex($profile['address'])} \\\\
        {$this->escapeLatex($profile['phone'])} \\\\
        {$this->escapeLatex($profile['email'])}

        \\vspace{1em}
        \\noindent {$date}

        \\vspace{1em}
        \\noindent \\textbf{Re: {$this->escapeLatex($prospectName)}}

        \\vspace{1em}
        \\noindent Dear {$this->escapeLatex($contactName)},

        {$body}

        \\vspace{2em}
        \\noindent Sincerely, \\\\
        {$this->escapeLatex($profile['name'])} \\\\
        {$this->escapeLatex($profile['company'])}

        \\end{document}
        LATEX;
    }

    /**
     * Build HTML document for a prospect response.
     */
    public function buildHtml(Prospect $prospect, PipelineConfig $config): string
    {
        $profile = $this->decodeProfile($config);
        $markdown = (string) ($prospect->get('draft_pdf_markdown') ?? '');
        $contactName = htmlspecialchars((string) ($prospect->get('contact_name') ?? 'Hiring Manager'), ENT_QUOTES);
        $prospectName = htmlspecialchars((string) ($prospect->get('name') ?? 'Untitled'), ENT_QUOTES);
        $date = date('F j, Y');

        // Simple markdown to HTML: paragraphs from double newlines, bold from **
        $body = str_replace('{{contact_name}}', $contactName, $markdown);
        $body = htmlspecialchars($body, ENT_QUOTES);
        $body = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $body) ?? $body;
        $paragraphs = array_filter(array_map('trim', explode("\n\n", $body)));
        $bodyHtml = implode("\n", array_map(fn (string $p) => "<p>{$p}</p>", $paragraphs));

        $profileName = htmlspecialchars($profile['name'], ENT_QUOTES);
        $profileTitle = htmlspecialchars($profile['title'], ENT_QUOTES);
        $profileCompany = htmlspecialchars($profile['company'], ENT_QUOTES);
        $profileAddress = htmlspecialchars($profile['address'], ENT_QUOTES);
        $profilePhone = htmlspecialchars($profile['phone'], ENT_QUOTES);
        $profileEmail = htmlspecialchars($profile['email'], ENT_QUOTES);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <style>
            body { font-family: 'Nunito Sans', Arial, sans-serif; max-width: 800px; margin: 40px auto; padding: 20px; color: #333; line-height: 1.6; }
            .header { margin-bottom: 2em; }
            .header p { margin: 0; }
            .date { margin: 1.5em 0; }
            .subject { font-weight: bold; margin: 1em 0; }
            .body p { margin: 0.8em 0; }
            .signature { margin-top: 2em; }
        </style>
        </head>
        <body>
        <div class="header">
            <p>{$profileName}</p>
            <p>{$profileTitle}</p>
            <p>{$profileCompany}</p>
            <p>{$profileAddress}</p>
            <p>{$profilePhone}</p>
            <p>{$profileEmail}</p>
        </div>
        <div class="date">{$date}</div>
        <div class="subject">Re: {$prospectName}</div>
        <p>Dear {$contactName},</p>
        <div class="body">{$bodyHtml}</div>
        <div class="signature">
            <p>Sincerely,</p>
            <p>{$profileName}<br>{$profileCompany}</p>
        </div>
        </body>
        </html>
        HTML;
    }

    /**
     * @return array{name: string, title: string, company: string, address: string, postal_code: string, phone: string, email: string}
     */
    private function decodeProfile(PipelineConfig $config): array
    {
        $raw = (string) ($config->get('company_profile') ?? '');
        $decoded = $raw !== '' ? json_decode($raw, true) : [];
        if (! is_array($decoded)) {
            $decoded = [];
        }

        return [
            'name' => (string) ($decoded['name'] ?? ''),
            'title' => (string) ($decoded['title'] ?? ''),
            'company' => (string) ($decoded['company'] ?? ''),
            'address' => (string) ($decoded['address'] ?? ''),
            'postal_code' => (string) ($decoded['postal_code'] ?? ''),
            'phone' => (string) ($decoded['phone'] ?? ''),
            'email' => (string) ($decoded['email'] ?? ''),
        ];
    }

    private function escapeLatex(string $text): string
    {
        $replacements = [
            '\\' => '\\textbackslash{}',
            '{' => '\\{',
            '}' => '\\}',
            '$' => '\\$',
            '&' => '\\&',
            '#' => '\\#',
            '^' => '\\textasciicircum{}',
            '_' => '\\_',
            '~' => '\\textasciitilde{}',
            '%' => '\\%',
        ];

        return strtr($text, $replacements);
    }

    private function markdownToLatex(string $markdown): string
    {
        $text = $markdown;

        // Bold: **text** -> \textbf{text}
        $text = preg_replace('/\*\*(.*?)\*\*/', '\\textbf{$1}', $text) ?? $text;
        // Italic: *text* -> \textit{text}
        $text = preg_replace('/\*(.*?)\*/', '\\textit{$1}', $text) ?? $text;
        // Bullet lists: - item -> \item item
        $text = preg_replace('/^- (.+)$/m', '\\item $1', $text) ?? $text;
        // Wrap \item blocks in itemize
        if (str_contains($text, '\\item')) {
            $text = preg_replace('/((?:\\\\item .+\n?)+)/', "\\begin{itemize}\n$1\\end{itemize}\n", $text) ?? $text;
        }
        // Paragraphs from double newlines
        $text = preg_replace('/\n{2,}/', "\n\n", $text) ?? $text;

        return $text;
    }
}
