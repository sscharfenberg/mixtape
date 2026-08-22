<?php

namespace Tests\Feature\Dashboard\ExportPresets;

use App\Models\ExportPreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The presets list (`GET /dashboard/export-presets`) and the one boolean that decides who can
 * see the way to it.
 *
 * THE READING ORDER IS THE POINT. This page and the export modal's picker draw the same rows
 * through the same scope, so the preset marked here as default is the preset that dialog opens
 * on. Two `orderBy`s written separately would drift, and the drift shows up as a modal opening
 * on something other than the marked row — with nothing failing anywhere.
 *
 * `hasExportPresets` gates only the USER MENU. The dashboard's own section is deliberately
 * ungated: the dashboard is where settings live and is exhaustive, so it is where a reader who
 * has never made a preset meets the feature at all.
 */
class ExportPresetsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get('/dashboard/export-presets')->assertRedirect('/login');
    }

    public function test_it_lists_the_readers_own_presets_default_first_then_by_name(): void
    {
        $user = User::factory()->create();
        ExportPreset::factory()->for($user)->create(['name' => 'Auto']);
        ExportPreset::factory()->for($user)->create(['name' => 'Handy']);
        ExportPreset::factory()->default()->for($user)->create(['name' => 'MacBook']);

        $this->actingAs($user)
            ->get('/dashboard/export-presets')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/ExportPresets/ExportPresetsPage')
                ->has('presets', 3)
                ->where('presets.0.name', 'MacBook')
                ->where('presets.0.isDefault', true)
                ->where('presets.1.name', 'Auto')
                ->where('presets.2.name', 'Handy'));
    }

    public function test_it_sends_the_row_shape_the_modals_picker_also_reads(): void
    {
        $user = User::factory()->create();
        ExportPreset::factory()->for($user)->create([
            'name' => 'Auto',
            'format' => 'simple',
            'encoding' => 'Windows-1252',
            'path_prefix' => '',
        ]);

        $this->actingAs($user)
            ->get('/dashboard/export-presets')
            ->assertInertia(fn (Assert $page) => $page
                ->where('presets.0.format', 'simple')
                ->where('presets.0.encoding', 'Windows-1252')
                // camelCase on the wire over a snake_case column, and '' rather than null —
                // the relative-paths case is a value, not an absence.
                ->where('presets.0.pathPrefix', ''));
    }

    public function test_it_never_lists_another_readers_presets(): void
    {
        $user = User::factory()->create();
        ExportPreset::factory()->for($user)->create(['name' => 'Mine']);
        ExportPreset::factory()->create(['name' => 'Theirs']);

        $this->actingAs($user)
            ->get('/dashboard/export-presets')
            ->assertInertia(fn (Assert $page) => $page->has('presets', 1)->where('presets.0.name', 'Mine'));
    }

    public function test_an_empty_list_is_a_real_state(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard/export-presets')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('presets', 0));
    }

    public function test_the_shared_prop_says_whether_the_user_menu_draws_its_entry(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('hasExportPresets', false));

        ExportPreset::factory()->for($user)->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('hasExportPresets', true));
    }

    public function test_the_shared_prop_is_scoped_to_the_reader(): void
    {
        ExportPreset::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('hasExportPresets', false));
    }
}
