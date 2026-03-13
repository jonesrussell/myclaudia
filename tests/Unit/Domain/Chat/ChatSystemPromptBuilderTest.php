<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain\Chat;

use Claudriel\Domain\Chat\ChatSystemPromptBuilder;
use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Support\DriftDetector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class ChatSystemPromptBuilderTest extends TestCase
{
    private function createAssembler(): DayBriefAssembler
    {
        $emptyRepo = $this->createMock(EntityRepositoryInterface::class);
        $emptyRepo->method('findBy')->willReturn([]);

        $driftDetector = new DriftDetector($emptyRepo);

        return new DayBriefAssembler($emptyRepo, $emptyRepo, $driftDetector, $emptyRepo);
    }

    #[Test]
    public function build_includes_instructions(): void
    {
        $builder = new ChatSystemPromptBuilder($this->createAssembler(), sys_get_temp_dir());
        $prompt = $builder->build();

        $this->assertStringContainsString('You are Claudriel', $prompt);
        $this->assertStringContainsString('Claudriel web dashboard', $prompt);
        $this->assertStringContainsString('interpret that as a Claudriel workspace', $prompt);
        $this->assertStringContainsString('Do not drift into generic interpretations like git worktrees', $prompt);
        $this->assertStringContainsString('interpret that as a git worktree by default', $prompt);
    }

    #[Test]
    public function build_includes_brief_context(): void
    {
        $builder = new ChatSystemPromptBuilder($this->createAssembler(), sys_get_temp_dir());
        $prompt = $builder->build();

        $this->assertStringContainsString('Pending commitments: 0', $prompt);
    }

    #[Test]
    public function build_reads_claude_md_when_present(): void
    {
        $tmpDir = sys_get_temp_dir().'/claudriel-test-'.uniqid();
        mkdir($tmpDir, 0755, true);
        file_put_contents($tmpDir.'/CLAUDE.md', "## Who I Am\n\nI am a test personality.\n");

        $builder = new ChatSystemPromptBuilder($this->createAssembler(), $tmpDir);
        $prompt = $builder->build();

        $this->assertStringContainsString('Personality & Behavior', $prompt);
        $this->assertStringContainsString('test personality', $prompt);

        unlink($tmpDir.'/CLAUDE.md');
        rmdir($tmpDir);
    }

    #[Test]
    public function build_reads_user_context_when_present(): void
    {
        $tmpDir = sys_get_temp_dir().'/claudriel-test-'.uniqid();
        mkdir($tmpDir.'/context', 0755, true);
        file_put_contents($tmpDir.'/context/me.md', "# Russell\nSoftware developer.\n");

        $builder = new ChatSystemPromptBuilder($this->createAssembler(), $tmpDir);
        $prompt = $builder->build();

        $this->assertStringContainsString('About the User', $prompt);
        $this->assertStringContainsString('Russell', $prompt);

        unlink($tmpDir.'/context/me.md');
        rmdir($tmpDir.'/context');
        rmdir($tmpDir);
    }

    #[Test]
    public function build_prefers_claude_user_md_over_claude_md(): void
    {
        $tmpDir = sys_get_temp_dir().'/claudriel-test-'.uniqid();
        mkdir($tmpDir, 0755, true);
        file_put_contents($tmpDir.'/CLAUDE.md', "## Who I Am\n\nGeneric personality.\n");
        file_put_contents($tmpDir.'/CLAUDE.user.md', "## Who I Am\n\nCustom personality from user md.\n");

        $builder = new ChatSystemPromptBuilder($this->createAssembler(), $tmpDir);
        $prompt = $builder->build();

        $this->assertStringContainsString('Custom personality from user md', $prompt);
        $this->assertStringNotContainsString('Generic personality', $prompt);

        unlink($tmpDir.'/CLAUDE.user.md');
        unlink($tmpDir.'/CLAUDE.md');
        rmdir($tmpDir);
    }
}
