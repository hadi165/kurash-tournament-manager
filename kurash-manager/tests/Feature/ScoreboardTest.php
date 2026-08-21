<?php

use App\Contracts\ScoreboardDriver;
use App\Jobs\PushBoutToScoreboard;
use App\Livewire\Competition\Bracket;
use App\Livewire\Competition\Courts;
use App\Models\Bout;
use App\Models\BoutEvent;
use App\Models\Court;
use App\Models\User;
use App\Services\BracketGenerator;
use App\Services\MedalTable;
use App\Services\Scoreboard\FakeScoreboardDriver;
use App\Services\Scoreboard\HttpScoreboardDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('scoreboard.webhook_secret', 'test-secret');

    $this->fake = new FakeScoreboardDriver;
    $this->app->instance(ScoreboardDriver::class, $this->fake);

    $this->admin = User::factory()->create(['role' => 'admin']);
});

function postResult(string $playCode, string $winnerSide, array $extra = [], ?string $token = 'test-secret'): TestResponse
{
    return test()->postJson(route('webhooks.scoreboard'), array_merge([
        'play_code' => $playCode,
        'winner_side' => $winnerSide,
        'score_a' => $winnerSide === 'a' ? 10 : 0,
        'score_b' => $winnerSide === 'b' ? 10 : 0,
        'win_type' => 'khalol',
    ], $extra), $token === null ? [] : ['X-Scoreboard-Token' => $token]);
}

describe('webhook authentication', function () {
    it('rejects a request with no token', function () {
        [$category] = categoryWithAthletes(4);
        app(BracketGenerator::class)->generate($category);
        $bout = $category->bouts()->where('round', 1)->first();

        postResult($bout->play_code, 'a', token: null)->assertStatus(401);

        expect($bout->refresh()->winner_athlete_id)->toBeNull();
    });

    it('rejects a wrong token', function () {
        [$category] = categoryWithAthletes(4);
        app(BracketGenerator::class)->generate($category);
        $bout = $category->bouts()->where('round', 1)->first();

        postResult($bout->play_code, 'a', token: 'not-the-secret')->assertStatus(401);

        expect($bout->refresh()->winner_athlete_id)->toBeNull();
    });

    /**
     * The original webhook fell back to a literal 'CHANGE_ME' when the
     * environment variable was missing, so forgetting to set it left the
     * endpoint open to anyone who had read the source.
     */
    it('refuses everything when no secret is configured', function () {
        config()->set('scoreboard.webhook_secret', null);

        postResult('anything', 'a', token: 'CHANGE_ME')->assertStatus(503);
    });

    it('does not require a CSRF token or a session', function () {
        [$category, $athletes] = categoryWithAthletes(4);
        app(BracketGenerator::class)->generate($category);
        $bout = $category->bouts()->where('round', 1)->where('position_in_round', 0)->first();

        postResult($bout->play_code, 'a')->assertOk();

        expect($bout->refresh()->winner_athlete_id)->toBe($athletes[1]->id);
    });
});

describe('webhook payload handling', function () {
    beforeEach(function () {
        [$this->category, $this->athletes] = categoryWithAthletes(4);
        app(BracketGenerator::class)->generate($this->category);
        $this->bout = $this->category->bouts()->where('round', 1)->where('position_in_round', 0)->first();
    });

    it('rejects a malformed payload', function (array $payload) {
        $this->postJson(route('webhooks.scoreboard'), $payload, ['X-Scoreboard-Token' => 'test-secret'])
            ->assertStatus(422);
    })->with([
        'no play code' => [['winner_side' => 'a']],
        'no winner' => [['play_code' => 'x']],
        'winner side is not a or b' => [['play_code' => 'x', 'winner_side' => 'c']],
        'negative score' => [['play_code' => 'x', 'winner_side' => 'a', 'score_a' => -5]],
    ]);

    /**
     * Redrawing issues fresh play codes, so a result from a discarded bracket
     * must not land on whatever now occupies that slot.
     */
    it('refuses a play code from a bracket that has been redrawn', function () {
        $staleCode = $this->bout->play_code;

        app(BracketGenerator::class)->generate($this->category, discardResults: true);

        postResult($staleCode, 'a')->assertStatus(404);
    });

    it('refuses a side with no athlete yet', function () {
        $final = $this->category->bouts()->whereNull('next_bout_id')->first();

        postResult($final->play_code, 'a')->assertStatus(409);
    });

    it('records the result and reports where the winner went', function () {
        $response = postResult($this->bout->play_code, 'a')->assertOk();

        $this->bout->refresh();

        $response->assertJson([
            'status' => 'success',
            'winner_athlete_id' => $this->athletes[1]->id,
            'advanced_to' => $this->bout->nextBout->play_code,
        ]);
    });

    it('attributes the result to the scoreboard in the audit trail', function () {
        postResult($this->bout->play_code, 'a')->assertOk();

        $event = BoutEvent::where('bout_id', $this->bout->id)->where('action', 'result_recorded')->first();

        expect($event->source)->toBe('scoreboard')
            ->and($event->user_id)->toBeNull();
    });

    /** A vendor that retries must not advance the same athlete twice. */
    it('is idempotent when the same result arrives again', function () {
        postResult($this->bout->play_code, 'a')->assertOk();
        $eventsAfterFirst = BoutEvent::count();

        postResult($this->bout->play_code, 'a')->assertOk();

        expect(BoutEvent::count())->toBe($eventsAfterFirst);
    });

    it('applies a corrected result and unwinds the bracket', function () {
        postResult($this->bout->play_code, 'a')->assertOk();
        postResult($this->bout->play_code, 'b')->assertOk();

        $this->bout->refresh();

        expect($this->bout->winner_athlete_id)->toBe($this->athletes[4]->id)
            ->and($this->bout->nextBout->athlete_a_id)->toBe($this->athletes[4]->id);
    });
});

describe('a whole tournament over the webhook, with no hardware', function () {
    it('reaches the right podium', function () {
        [$category, $athletes] = categoryWithAthletes(8);
        app(BracketGenerator::class)->generate($category);

        $fought = 0;

        while (true) {
            $ready = $category->bouts()->readyToFight()->orderBy('round')->get();

            if ($ready->isEmpty() || $fought > 50) {
                break;
            }

            foreach ($ready as $bout) {
                $bout->refresh();

                if (! $bout->isReadyToFight()) {
                    continue;
                }

                $side = $bout->athleteA->draw_number < $bout->athleteB->draw_number ? 'a' : 'b';
                postResult($bout->play_code, $side)->assertOk();
                $fought++;
            }
        }

        expect($fought)->toBe(7);

        $podium = app(MedalTable::class)->forCategory($category);

        expect($podium['decided'])->toBeTrue()
            ->and($podium['gold']->id)->toBe($athletes[1]->id)
            ->and($podium['silver']->id)->toBe($athletes[2]->id);
    });
});

describe('pushing a bout to a mat', function () {
    beforeEach(function () {
        [$this->category, $this->athletes] = categoryWithAthletes(4);
        app(BracketGenerator::class)->generate($this->category);

        $this->court = Court::factory()->create([
            'championship_id' => $this->category->ageCategory->championship_id,
            'number' => 1,
        ]);

        $this->bout = $this->category->bouts()->where('round', 1)->where('position_in_round', 0)->first();
        $this->actingAs($this->admin);
    });

    it('assigns the mat and queues the push', function () {
        Queue::fake();

        Livewire::test(Bracket::class, ['weightCategory' => $this->category])
            ->call('sendToMat', $this->bout->id, $this->court->id);

        expect($this->bout->refresh()->court_id)->toBe($this->court->id)
            ->and($this->bout->status)->toBe(Bout::STATUS_ON_COURT);

        Queue::assertPushed(PushBoutToScoreboard::class);
    });

    it('sends the bout when the job runs, and records the sync', function () {
        $this->bout->update(['court_id' => $this->court->id]);

        (new PushBoutToScoreboard($this->bout->id))->handle($this->fake);

        $this->fake->assertPushed($this->bout);

        expect($this->bout->refresh()->scoreboard_synced_at)->not->toBeNull()
            ->and(BoutEvent::where('bout_id', $this->bout->id)->where('action', 'pushed_to_scoreboard')->exists())->toBeTrue();
    });

    /** An unplugged display must surface as a retry, not a silent success. */
    it('throws so the queue retries when the display does not answer', function () {
        $this->bout->update(['court_id' => $this->court->id]);
        $this->fake->failWith('Connection refused');

        expect(fn () => (new PushBoutToScoreboard($this->bout->id))->handle($this->fake))
            ->toThrow(RuntimeException::class, 'Connection refused');

        expect($this->bout->refresh()->scoreboard_synced_at)->toBeNull();
    });

    it('does nothing when the bracket was redrawn before the job ran', function () {
        $boutId = $this->bout->id;
        $this->category->bouts()->delete();

        (new PushBoutToScoreboard($boutId))->handle($this->fake);

        $this->fake->assertNothingPushed();
    });

    it('skips an inactive mat', function () {
        $this->court->update(['is_active' => false]);
        $this->bout->update(['court_id' => $this->court->id]);

        (new PushBoutToScoreboard($this->bout->id))->handle($this->fake);

        $this->fake->assertNothingPushed();
    });

    it('refuses a bout whose opponent is not yet known', function () {
        $final = $this->category->bouts()->whereNull('next_bout_id')->first();

        Livewire::test(Bracket::class, ['weightCategory' => $this->category])
            ->call('sendToMat', $final->id, $this->court->id);

        expect($final->refresh()->court_id)->toBeNull();
    });

    it('refuses a mat belonging to another championship', function () {
        $foreign = Court::factory()->create(['number' => 9]);

        Livewire::test(Bracket::class, ['weightCategory' => $this->category])
            ->call('sendToMat', $this->bout->id, $foreign->id);

        expect($this->bout->refresh()->court_id)->toBeNull();
    });
});

describe('the HTTP driver', function () {
    it('posts the bout to the court URL with a bearer token', function () {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        [$category, $athletes] = categoryWithAthletes(4);
        app(BracketGenerator::class)->generate($category);
        $bout = $category->bouts()->where('round', 1)->first()->load(['athleteA', 'athleteB', 'weightCategory']);

        $court = Court::factory()->create([
            'championship_id' => $category->ageCategory->championship_id,
            'scoreboard_base_url' => 'http://192.168.1.40/',
            'scoreboard_api_key' => 'secret-key',
        ]);

        $response = (new HttpScoreboardDriver)->pushBout($bout, $court);

        expect($response->successful)->toBeTrue();

        Http::assertSent(function ($request) use ($bout) {
            return $request->url() === 'http://192.168.1.40/match'
                && $request->hasHeader('Authorization', 'Bearer secret-key')
                && $request['play_code'] === $bout->play_code
                && $request['athlete_blue']['name'] === $bout->athleteA->fullname;
        });
    });

    it('reports a failure instead of throwing when the display refuses', function () {
        Http::fake(['*' => Http::response('boom', 500)]);

        [$category] = categoryWithAthletes(4);
        app(BracketGenerator::class)->generate($category);
        $bout = $category->bouts()->where('round', 1)->first();
        $court = Court::factory()->create(['championship_id' => $category->ageCategory->championship_id]);

        $response = (new HttpScoreboardDriver)->pushBout($bout, $court);

        expect($response->failed())->toBeTrue()
            ->and($response->status)->toBe(500);
    });

    it('reports a failure when the court has no URL configured', function () {
        [$category] = categoryWithAthletes(4);
        app(BracketGenerator::class)->generate($category);
        $bout = $category->bouts()->where('round', 1)->first();

        $court = Court::factory()->create([
            'championship_id' => $category->ageCategory->championship_id,
            'scoreboard_base_url' => null,
        ]);

        expect((new HttpScoreboardDriver)->pushBout($bout, $court)->failed())->toBeTrue();
    });
});

describe('mat management', function () {
    beforeEach(fn () => $this->actingAs($this->admin));

    it('adds a mat', function () {
        [$category] = categoryWithAthletes(2);
        $championship = $category->ageCategory->championship;

        Livewire::test(Courts::class, ['championship' => $championship])
            ->set('number', 1)
            ->set('name', 'Mat A')
            ->set('scoreboard_base_url', 'http://192.168.1.40')
            ->call('save')
            ->assertHasNoErrors();

        expect($championship->courts()->count())->toBe(1);
    });

    it('rejects a duplicate mat number', function () {
        $court = Court::factory()->create(['number' => 1]);

        Livewire::test(Courts::class, ['championship' => $court->championship])
            ->set('number', 1)
            ->call('save')
            ->assertHasErrors('number');

        expect($court->championship->courts()->count())->toBe(1);
    });

    /** The stored key is encrypted; it should never be sent back to a browser. */
    it('never returns the stored API key to the form', function () {
        $court = Court::factory()->create(['scoreboard_api_key' => 'super-secret']);

        Livewire::test(Courts::class, ['championship' => $court->championship])
            ->call('edit', $court->id)
            ->assertSet('scoreboard_api_key', '');
    });

    it('keeps the existing key when the field is left blank', function () {
        $court = Court::factory()->create(['number' => 3, 'scoreboard_api_key' => 'super-secret']);

        Livewire::test(Courts::class, ['championship' => $court->championship])
            ->call('edit', $court->id)
            ->set('name', 'Renamed')
            ->call('save');

        expect($court->refresh()->scoreboard_api_key)->toBe('super-secret')
            ->and($court->name)->toBe('Renamed');
    });

    it('stores the API key encrypted at rest', function () {
        $court = Court::factory()->create(['scoreboard_api_key' => 'super-secret']);

        $raw = DB::table('courts')->where('id', $court->id)->value('scoreboard_api_key');

        expect($raw)->not->toBe('super-secret')
            ->and($court->refresh()->scoreboard_api_key)->toBe('super-secret');
    });

    it('refuses to delete a mat with bouts assigned', function () {
        [$category] = categoryWithAthletes(4);
        app(BracketGenerator::class)->generate($category);

        $court = Court::factory()->create(['championship_id' => $category->ageCategory->championship_id]);
        $category->bouts()->first()->update(['court_id' => $court->id]);

        Livewire::test(Courts::class, ['championship' => $court->championship])
            ->call('delete', $court->id);

        expect(Court::find($court->id))->not->toBeNull();
    });

    it('reports whether a mat answers', function () {
        $court = Court::factory()->create();

        Livewire::test(Courts::class, ['championship' => $court->championship])
            ->call('testConnection', $court->id);

        expect($this->fake->cleared)->toHaveCount(1);
    });
});
