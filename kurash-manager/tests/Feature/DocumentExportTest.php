<?php

use App\Exports\AccreditationCards;
use App\Models\Athlete;
use App\Models\User;
use App\Services\BracketGenerator;
use App\Support\QrCode;

beforeEach(function () {
    $this->viewer = User::factory()->create(['role' => 'viewer']);
});

describe('access', function () {
    it('sends guests to the login page', function () {
        [$category] = categoryWithAthletes(2);
        $championship = $category->ageCategory->championship;

        $this->get(route('exports.certificates', $championship))->assertRedirect(route('login'));
        $this->get(route('exports.accreditation', $championship))->assertRedirect(route('login'));
    });
});

describe('certificates', function () {
    beforeEach(fn () => $this->actingAs($this->viewer));

    it('produces one for every medallist once a class is decided', function () {
        [$category] = categoryWithAthletes(4);
        app(BracketGenerator::class)->generate($category);
        runTournament($category);

        $championship = $category->ageCategory->championship;

        $response = $this->get(route('exports.certificates', $championship));

        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload('Certificates-'.$championship->title.'.pdf');

        expect(strlen($response->getContent()))->toBeGreaterThan(1000);
    });

    /**
     * A championship where nothing has been fought must still hand back a
     * document. Printing an empty certificate sheet is recoverable; a 500 at
     * the moment someone needs the paperwork is not.
     */
    it('still renders when no class has been decided', function () {
        [$category] = categoryWithAthletes(4);

        $this->get(route('exports.certificates', $category->ageCategory->championship))->assertOk();
    });

    it('narrows to one weight class', function () {
        [$category] = categoryWithAthletes(4);
        app(BracketGenerator::class)->generate($category);
        runTournament($category);

        $this->get(route('exports.certificates.category', $category))
            ->assertOk()
            ->assertDownload('Certificates-'.$category->ageCategory->championship->title.' '.$category->exportName().'.pdf');
    });
});

describe('accreditation cards', function () {
    beforeEach(fn () => $this->actingAs($this->viewer));

    it('renders a card sheet for a championship', function () {
        [$category] = categoryWithAthletes(6);
        $championship = $category->ageCategory->championship;

        $response = $this->get(route('exports.accreditation', $championship));

        $response->assertOk()->assertHeader('content-type', 'application/pdf');

        expect(strlen($response->getContent()))->toBeGreaterThan(1000);
    });

    it('renders a single card for one athlete', function () {
        [$category] = categoryWithAthletes(2);
        $athlete = $category->athletes()->first();

        $this->get(route('exports.accreditation.athlete', $athlete))
            ->assertOk()
            ->assertDownload('Accreditation-'.$athlete->fullname.'.pdf');
    });

    it('renders for a championship with nobody registered', function () {
        [$category] = categoryWithAthletes(0);

        $this->get(route('exports.accreditation', $category->ageCategory->championship))->assertOk();
    });

    /**
     * Zone 3 is the field of play. An athlete who has not been given it
     * explicitly must not receive it by default.
     */
    it('grants the competitor zones by default and honours an explicit list', function () {
        [$category] = categoryWithAthletes(1);

        $athlete = $category->athletes()->first();
        $cards = AccreditationCards::forAthlete($athlete);

        expect($cards->data()['cards'][0]['areas'])->toBe([1, 2, 4]);

        $athlete->update(['accreditation_areas' => [1, 3, 5]]);

        $cards = AccreditationCards::forAthlete($athlete->refresh());

        expect($cards->data()['cards'][0]['areas'])->toBe([1, 3, 5]);
    });
});

describe('the QR code', function () {
    it('encodes the federation ID as inline SVG', function () {
        $svg = QrCode::svg('IKA000412');

        expect($svg)->toContain('<svg')
            ->and($svg)->toContain('<rect')
            ->and($svg)->toContain('viewBox="0 0 21 21');
    });

    it('hands back a data URI an img tag can use', function () {
        expect(QrCode::dataUri('IKA000412'))->toStartWith('data:image/svg+xml;base64,');
    });

    /**
     * A card without a QR code still gets someone through a door. A card that
     * throws on the way to the printer does not.
     */
    it('returns null rather than throwing on a payload it cannot encode', function () {
        expect(QrCode::svg(str_repeat('x', 8000)))->toBeNull();
    });
});
