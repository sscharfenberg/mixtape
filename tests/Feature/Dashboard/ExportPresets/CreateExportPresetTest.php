<?php

namespace Tests\Feature\Dashboard\ExportPresets;

use App\Http\Requests\Dashboard\ExportPresets\StoreExportPresetRequest;
use App\Models\ExportPreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Making an export preset: the form page (`GET /dashboard/export-presets/create`) and the
 * create itself (`POST /dashboard/export-presets`).
 *
 * THREE THINGS CARRY THE WEIGHT HERE, and each of them fails silently rather than loudly.
 *
 * AN EMPTY PATH PREFIX IS A REAL VALUE — it is what the car preset holds, where the playlist
 * sits beside the music and every path is relative. `ConvertEmptyStringsToNull` runs in the
 * global middleware stack, so the empty field arrives at the request as `null` rather than as
 * `''`; without the cast in `prepareForValidation` the `string` rule rejects exactly the preset
 * this feature exists for, and the failure looks like a broken form rather than a rule.
 *
 * THE FIRST PRESET TAKES THE DEFAULT FLAG, because a reader holding one preset has no choice to
 * make. Miss it and their preset sits in the list while the export modal goes on offering the
 * config prefix — nothing errors, the feature simply appears not to work.
 *
 * `name` IS UNIQUE PER OWNER, not globally, and the message for a clash comes from
 * `lang/*\/preset.php` rather than validation.php's `custom` block — that block's `name` entry
 * belongs to the USERNAME, so without the inline messages this form answers "this username is
 * already taken", which renders and reads as plausible.
 */
class CreateExportPresetTest extends TestCase
{
    use RefreshDatabase;

    /** The shape a valid submit has, so a test that varies one field says which one. */
    private function fields(array $overrides = []): array
    {
        return array_merge([
            'name' => 'MacBook',
            'format' => 'simple',
            'encoding' => 'UTF-8',
            'path_prefix' => '/Volumes/media/music',
        ], $overrides);
    }

    public function test_guests_cannot_reach_the_form(): void
    {
        $this->get('/dashboard/export-presets/create')->assertRedirect('/login');
    }

    public function test_guests_cannot_create_a_preset(): void
    {
        $this->post('/dashboard/export-presets', $this->fields())->assertRedirect('/login');

        $this->assertDatabaseCount('export_presets', 0);
    }

    public function test_the_form_page_renders_with_the_config_prefix_to_start_from(): void
    {
        config(['mixtape.playlists.export.path_prefix' => '/mnt/music']);

        $this->actingAs(User::factory()->create())
            ->get('/dashboard/export-presets/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/ExportPresets/Preset/ExportPresetPage')
                ->where('preset', null)
                ->where('fallbackPrefix', '/mnt/music'));
    }

    public function test_the_create_route_does_not_swallow_the_word_create(): void
    {
        /*
         * `/dashboard/export-presets/create` has to keep matching the form route rather than
         * being read as a preset id by the `{preset}` routes registered after it. The uuid
         * constraint is the other half of that, and this pins the ordering.
         */
        $this->assertSame(
            '/dashboard/export-presets/create',
            route('dashboard.presets.create', absolute: false)
        );
    }

    public function test_it_creates_a_preset_and_returns_to_the_list(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/dashboard/export-presets', $this->fields([
                'name' => '  MacBook  ',
                'format' => 'extended',
                'encoding' => 'Windows-1252',
            ]))
            ->assertRedirect('/dashboard/export-presets')
            ->assertSessionHas('type', 'success');

        $preset = ExportPreset::query()->sole();

        $this->assertSame($user->id, $preset->user_id);
        $this->assertSame('MacBook', $preset->name, 'the name is trimmed before it is stored');
        $this->assertSame('extended', $preset->format);
        $this->assertSame('Windows-1252', $preset->encoding);
    }

    public function test_an_empty_path_prefix_is_stored_as_an_empty_string(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/dashboard/export-presets', $this->fields(['name' => 'Auto', 'path_prefix' => '']))
            ->assertRedirect('/dashboard/export-presets')
            ->assertSessionHasNoErrors();

        $this->assertSame('', ExportPreset::query()->sole()->path_prefix);
    }

    public function test_a_missing_path_prefix_is_also_accepted_as_empty(): void
    {
        // The field cannot be absent from the real form, but `ConvertEmptyStringsToNull` makes
        // "empty" and "absent" arrive identically, so the two have to behave identically too.
        $fields = $this->fields();
        unset($fields['path_prefix']);

        $this->actingAs(User::factory()->create())
            ->post('/dashboard/export-presets', $fields)
            ->assertSessionHasNoErrors();

        $this->assertSame('', ExportPreset::query()->sole()->path_prefix);
    }

    public function test_the_first_preset_becomes_the_default(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/dashboard/export-presets', $this->fields());

        $this->assertTrue(ExportPreset::query()->sole()->is_default);
    }

    public function test_a_later_preset_does_not_steal_the_default(): void
    {
        $user = User::factory()->create();
        $first = ExportPreset::factory()->default()->for($user)->create(['name' => 'MacBook']);

        $this->actingAs($user)->post('/dashboard/export-presets', $this->fields(['name' => 'Auto']));

        $this->assertTrue($first->fresh()->is_default);
        $this->assertFalse(ExportPreset::query()->where('name', 'Auto')->sole()->is_default);
    }

    public function test_the_name_is_unique_per_owner_only(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        ExportPreset::factory()->for($theirs)->create(['name' => 'MacBook']);

        $this->actingAs($mine)
            ->post('/dashboard/export-presets', $this->fields(['name' => 'MacBook']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('export_presets', 2);
    }

    public function test_a_duplicate_name_is_refused_with_the_forms_own_message(): void
    {
        $user = User::factory()->create();
        ExportPreset::factory()->for($user)->create(['name' => 'MacBook']);

        $this->actingAs($user)
            ->post('/dashboard/export-presets', $this->fields(['name' => 'MacBook']))
            ->assertSessionHasErrors(['name' => __('preset.validation')['name.unique']]);

        $this->assertDatabaseCount('export_presets', 1);
    }

    public function test_an_unknown_format_or_encoding_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/dashboard/export-presets', $this->fields(['format' => 'pls']))
            ->assertSessionHasErrors('format');

        $this->actingAs($user)
            ->post('/dashboard/export-presets', $this->fields(['encoding' => 'UTF-16']))
            ->assertSessionHasErrors('encoding');

        $this->assertDatabaseCount('export_presets', 0);
    }

    public function test_a_line_break_in_the_prefix_is_refused(): void
    {
        // It would split the .m3u into lines the reader never wrote — `#EXTM3U` among them —
        // because the prefix is concatenated into the file's text.
        $this->actingAs(User::factory()->create())
            ->post('/dashboard/export-presets', $this->fields(['path_prefix' => "/Volumes\r\n#EXTM3U"]))
            ->assertSessionHasErrors('path_prefix');

        $this->assertDatabaseCount('export_presets', 0);
    }

    public function test_a_field_posted_as_an_array_is_refused_rather_than_fatal(): void
    {
        // Cleaning runs BEFORE any rule, so it meets whatever was posted. Coercing there —
        // `$this->string()` throws on an array, `(string)` warns and Laravel's handler rethrows
        // — turns a hand-written body into a 500 on a route whose whole job is to refuse bad
        // input politely. Both fields are left alone for the `string` rule instead.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/dashboard/export-presets', $this->fields(['name' => ['not' => 'a string']]))
            ->assertSessionHasErrors('name');

        $this->actingAs($user)
            ->post('/dashboard/export-presets', $this->fields(['path_prefix' => ['not' => 'a string']]))
            ->assertSessionHasErrors('path_prefix');

        $this->assertDatabaseCount('export_presets', 0);
    }

    public function test_a_reader_left_without_a_default_gets_one_back_on_the_next_create(): void
    {
        /*
         * The create asks "does anybody hold the flag?" rather than "is this the first?", which
         * differs only when two creates are in flight together: both insert, both then count
         * two, and a count check promotes NEITHER — leaving presets with no default, which no
         * page reports and only a delete would ever repair. The state is reproduced directly
         * here, because two genuinely concurrent requests are not something this suite can
         * stage.
         */
        $user = User::factory()->create();
        ExportPreset::factory()->count(2)->for($user)->create(['is_default' => false]);

        $this->actingAs($user)->post('/dashboard/export-presets', $this->fields(['name' => 'Aaa first']));

        $this->assertSame(
            1,
            ExportPreset::query()->where('user_id', $user->id)->where('is_default', true)->count()
        );
    }

    public function test_the_cap_refuses_one_more(): void
    {
        $user = User::factory()->create();
        ExportPreset::factory()->count(StoreExportPresetRequest::LIMIT)->for($user)->create();

        $this->actingAs($user)
            ->post('/dashboard/export-presets', $this->fields(['name' => 'One too many']))
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('export_presets', StoreExportPresetRequest::LIMIT);
    }
}
