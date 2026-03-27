<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Support;

use Claudriel\Support\BriefSignal;
use PHPUnit\Framework\TestCase;

final class BriefSignalTest extends TestCase
{
    private string $signalFile;

    protected function setUp(): void
    {
        $this->signalFile = sys_get_temp_dir().'/brief_signal_'.uniqid('', true).'.txt';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->signalFile)) {
            unlink($this->signalFile);
        }
    }

    public function test_touch_creates_file_and_returns_current_time(): void
    {
        $signal = new BriefSignal($this->signalFile);
        $before = time() - 1;
        $signal->touch();
        $after = time() + 1;

        self::assertFileExists($this->signalFile);
        $mtime = $signal->lastModified();
        self::assertGreaterThanOrEqual($before, $mtime);
        self::assertLessThanOrEqual($after, $mtime);
    }

    public function test_last_modified_returns_zero_when_file_does_not_exist(): void
    {
        $signal = new BriefSignal($this->signalFile);
        self::assertSame(0, $signal->lastModified());
    }

    public function test_has_changed_since_detects_touch(): void
    {
        $signal = new BriefSignal($this->signalFile);
        $signal->touch();
        $baseline = $signal->lastModified();

        // Same mtime, no change
        self::assertFalse($signal->hasChangedSince($baseline));

        // Retry until the filesystem mtime ticks forward; one second is flaky on some filesystems.
        $changed = false;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            sleep(1);
            $signal->touch();
            if ($signal->hasChangedSince($baseline)) {
                $changed = true;
                break;
            }
        }

        self::assertTrue($changed);
    }

    public function test_has_changed_since_returns_false_when_no_file(): void
    {
        $signal = new BriefSignal($this->signalFile);
        self::assertFalse($signal->hasChangedSince(0));
    }
}
