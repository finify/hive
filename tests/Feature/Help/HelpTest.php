<?php

use App\Livewire\Help\Index;
use App\Livewire\Help\Show;
use App\Support\Help;
use App\Support\Navigation;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

it('has a written guide for every section, and every guide link goes somewhere', function () {
    foreach (Help::GUIDES as $key => $guide) {
        $file = resource_path("help/{$key}.md");
        expect(is_file($file))->toBeTrue("Missing resources/help/{$key}.md");

        preg_match_all('#\]\(/help/([a-z-]+)\)#', (string) file_get_contents($file), $links);
        foreach ($links[1] as $target) {
            expect(Help::GUIDES)->toHaveKey($target);
        }

        foreach ($guide['routes'] as $pattern) {
            expect(str_contains($pattern, '*') || Route::has($pattern))->toBeTrue("{$key} names unknown route {$pattern}");
        }
    }
});

it('gives every sidebar item a tooltip from its guide', function (string $who) {
    foreach (Navigation::for(persona($who)) as $group) {
        foreach ($group['items'] as $item) {
            if ($item['route'] !== 'help') {
                expect($item['tip'])->not->toBeNull("No guide for {$item['label']}");
            }
        }
    }
})->with(['sam', 'kofi', 'jamie']);

it('shows the ? beside page titles, with the guide’s summary as its tooltip', function () {
    $this->actingAs(persona('jamie'))->get(route('leave'))
        ->assertOk()
        ->assertSee('href="'.route('help.show', 'leave').'"', false)
        ->assertSee(Help::GUIDES['leave']['summary'], false);
});

it('lists only the guides a role can use, and searches their text', function () {
    Livewire::actingAs(persona('kofi'))->test(Index::class)
        ->assertSee('Timesheets')
        ->assertSee('Freelancer invoices')
        ->assertDontSee('Payroll locks')
        ->assertDontSee('Data protection');

    Livewire::actingAs(persona('jamie'))->test(Index::class)
        ->set('search', 'TOIL')
        ->assertSee('Leave')
        ->assertDontSee('Purchase orders');

    $this->actingAs(persona('kofi'))->get(route('help.show', 'settings'))->assertNotFound();
});

it('renders a guide with a way to the page it explains', function () {
    Livewire::actingAs(persona('hannah'))->test(Show::class, ['key' => 'purchase-orders'])
        ->assertSee('Approval limits')
        ->assertSee('Open Purchase orders')
        ->assertSee('More in Finance');

    $this->actingAs(persona('hannah'))->get(route('help.show', 'getting-started'))->assertOk()->assertSee('Rules Hive follows everywhere');
});
