<?php

namespace App\Services\Scoreboard;

use App\Contracts\ScoreboardDriver;
use App\Models\Bout;
use App\Models\Court;
use PHPUnit\Framework\Assert;

/**
 * An in-memory scoreboard.
 *
 * Lets a whole tournament — pushes out, results back — run in a test with no
 * hardware present, and lets you rehearse a venue setup before the equipment
 * arrives. Bind it with SCOREBOARD_DRIVER=fake.
 */
class FakeScoreboardDriver implements ScoreboardDriver
{
    /** @var list<array{bout:Bout, court:Court}> */
    public array $pushed = [];

    /** @var list<Court> */
    public array $cleared = [];

    private bool $shouldFail = false;

    private ?string $failureMessage = null;

    /** Make every subsequent call fail, to exercise the retry path. */
    public function failWith(string $message = 'Scoreboard unreachable'): self
    {
        $this->shouldFail = true;
        $this->failureMessage = $message;

        return $this;
    }

    public function recover(): self
    {
        $this->shouldFail = false;
        $this->failureMessage = null;

        return $this;
    }

    public function pushBout(Bout $bout, Court $court): ScoreboardResponse
    {
        if ($this->shouldFail) {
            return ScoreboardResponse::failure($this->failureMessage ?? 'failed');
        }

        $this->pushed[] = ['bout' => $bout, 'court' => $court];

        return ScoreboardResponse::ok();
    }

    public function clearCourt(Court $court): ScoreboardResponse
    {
        if ($this->shouldFail) {
            return ScoreboardResponse::failure($this->failureMessage ?? 'failed');
        }

        $this->cleared[] = $court;

        return ScoreboardResponse::ok();
    }

    public function assertPushed(Bout $bout): void
    {
        Assert::assertTrue(
            collect($this->pushed)->contains(fn (array $p) => $p['bout']->is($bout)),
            "Expected bout {$bout->play_code} to have been pushed to a scoreboard."
        );
    }

    public function assertNothingPushed(): void
    {
        Assert::assertSame([], $this->pushed, 'Expected no scoreboard pushes.');
    }

    public function pushCount(): int
    {
        return count($this->pushed);
    }
}
