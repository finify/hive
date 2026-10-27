<?php

use App\Actions\Gdpr\AnonymisePerson;
use App\Actions\Gdpr\DecideRetention;
use App\Actions\Gdpr\ExportPersonData;
use App\Demo\DemoData;
use App\Jobs\FlagRecordsPastRetention;
use App\Livewire\Admin\Retention;
use App\Livewire\Admin\Settings;
use App\Models\AiRequest;
use App\Models\Assignment;
use App\Models\CostEntry;
use App\Models\Person;
use App\Models\PersonRestricted;
use App\Models\RetentionFlag;
use App\Models\Timesheet;
use App\Models\User;
use App\Support\Settings as HiveSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

it('exports everything Hive holds about a person as a ZIP, for HR only, and logs it', function () {
    PersonRestricted::query()->updateOrCreate(['person_id' => 26], ['passport_number' => '553018274', 'emergency_contact' => 'Ama Mensah']);

    $path = app(ExportPersonData::class)->handle(persona('leah'), 26);
    $zip = new ZipArchive;
    $zip->open($path);
    $json = json_decode((string) $zip->getFromName('hive-data.json'), true);
    $zip->close();
    unlink($path);

    expect($json['person']['first_name'])->toBe('Kofi')
        ->and($json['restricted']['passport_number'])->toBe('553018274')
        ->and($json['account']['email'])->toBe('kofi@freelance.test')
        ->and($json['bookings'])->not->toBeEmpty()
        ->and($json['timesheets'])->not->toBeEmpty()
        ->and($json['travel'])->not->toBeEmpty()
        ->and(Activity::query()->where('event', 'subject-access-export')->exists())->toBeTrue();

    expect(fn () => app(ExportPersonData::class)->handle(persona('priya'), 26))->toThrow(AuthorizationException::class);

    $this->actingAs(persona('leah'))->get(route('people.export', 26))->assertOk()->assertHeader('content-type', 'application/zip');
});

it('anonymises a leaver, keeping their financial records', function () {
    $liam = Person::query()->findOrFail(21);
    $liam->update(['end_date' => '2026-10-01', 'is_active' => false]);
    $costsBefore = CostEntry::query()->sum('amount_gbp_minor');
    $sheets = Timesheet::query()->where('person_id', 21)->count();

    app(AnonymisePerson::class)->handle(persona('leah'), 21, 'Erasure request ER-0042');

    $liam->refresh();
    expect($liam->first_name)->toBe('Former')
        ->and($liam->last_name)->toBe('staff member 21')
        ->and($liam->email)->toBe('anonymised-21@invalid.hive')
        ->and($liam->anonymised_at)->not->toBeNull()
        ->and(PersonRestricted::query()->where('person_id', 21)->exists())->toBeFalse()
        ->and($liam->documents()->count())->toBe(0)
        ->and(Timesheet::query()->where('person_id', 21)->count())->toBe($sheets)
        ->and(CostEntry::query()->sum('amount_gbp_minor'))->toBe($costsBefore)
        ->and(Activity::query()->where('subject_type', Person::class)->where('subject_id', 21)->latest('id')->value('properties')->toArray()['attributes'] ?? [])->not->toHaveKey('first_name');

    $account = User::query()->where('person_id', 21)->first();
    if ($account) {
        expect($account->is_active)->toBeFalse()->and($account->email)->toBe('anonymised-21@invalid.hive');
    }
});

it('refuses to anonymise someone still working or still booked', function () {
    expect(app(AnonymisePerson::class)->blockers(10))->toContain('Only people who have left can be anonymised. Set a leaving date in the past or deactivate them first.');

    $kofi = Assignment::query()->where('person_id', 26)->whereDate('end_date', '>=', '2026-10-23')->exists();
    Person::query()->whereKey(26)->update(['is_active' => false]);
    expect($kofi)->toBeTrue()
        ->and(app(AnonymisePerson::class)->blockers(26))->toContain('They still have bookings from today on. Release them first.');

    app(AnonymisePerson::class)->handle(persona('leah'), 10, 'Request');
})->throws(ValidationException::class);

it('flags records past retention monthly, once, and acts on HR’s decision', function () {
    $old = AiRequest::query()->create(['user_id' => persona('jamie')->id, 'engine' => 'local', 'status' => 'answered', 'prompt' => 'Old question', 'response' => 'Old answer']);
    $old->forceFill(['created_at' => now()->subMonths(14)])->save();
    $leaver = Person::query()->findOrFail(21);
    $leaver->update(['end_date' => '2019-03-31', 'is_active' => false]);

    expect((new FlagRecordsPastRetention)->handle(app(DemoData::class)))->toBe(2)
        ->and((new FlagRecordsPastRetention)->handle(app(DemoData::class)))->toBe(0);

    Livewire::actingAs(persona('leah'))->test(Retention::class)
        ->assertSee('Old question')
        ->assertSee('Liam Walsh, left 31 Mar 2019');

    $decide = app(DecideRetention::class);
    $decide->handle(persona('leah'), RetentionFlag::query()->where('category', 'ai-request')->sole()->id, true);
    $decide->handle(persona('leah'), RetentionFlag::query()->where('category', 'leaver')->sole()->id, true);

    expect(AiRequest::query()->find($old->id))->toBeNull()
        ->and($leaver->refresh()->anonymised_at)->not->toBeNull()
        ->and(RetentionFlag::query()->pluck('decision')->sort()->values()->all())->toBe(['anonymised', 'deleted']);
});

it('lets an admin change limits and thresholds, which apply at once and are logged', function () {
    Livewire::actingAs(persona('sam'))->test(Settings::class)
        ->assertSet('values.hive__finance__po_limits__producer', '5000.00')
        ->set('values.hive__finance__po_limits__producer', '7500')
        ->set('values.hive__health__watch_forecast_ratio', '85')
        ->set('values.hive__ai__enabled', false)
        ->call('save')
        ->assertHasNoErrors();

    expect(config('hive.finance.po_limits.producer'))->toBe(750000)
        ->and(config('hive.health.watch_forecast_ratio'))->toBe(0.85)
        ->and(config('hive.ai.enabled'))->toBeFalse()
        ->and(Activity::query()->where('event', 'settings-changed')->latest('id')->value('description'))->toBe('Changed 3 settings');

    // A fresh boot reads them back.
    config(['hive.finance.po_limits.producer' => 1]);
    Cache::flush();
    HiveSettings::apply();
    expect(config('hive.finance.po_limits.producer'))->toBe(750000);
});

it('validates settings and keeps them read-only without settings.manage', function () {
    Livewire::actingAs(persona('sam'))->test(Settings::class)
        ->set('values.hive__working_time__min_rest_hours', '3')
        ->call('save')
        ->assertHasErrors(['values.hive__working_time__min_rest_hours']);

    Livewire::actingAs(persona('priya'))->test(Settings::class)->assertDontSee('Save settings')->assertSee('Only an admin can change them');
    expect(fn () => HiveSettings::save(persona('priya'), HiveSettings::formValues()))->toThrow(AuthorizationException::class);
});
