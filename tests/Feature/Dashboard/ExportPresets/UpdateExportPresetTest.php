<?php

namespace Tests\Feature\Dashboard\ExportPresets;

use App\Models\ExportPreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Editing an export preset: the form page (`GET …/{preset}/edit`) and the save
 * (`PUT …/{preset}`).
 *
 * TWO THINGS ARE THE POINT. Somebody else's preset answers 404 rather than 403 — the standing
 * rule for anything user-owned on an instance that is deliberately reachable from the internet,
 * since "you may not edit that" confirms the row exists. And a save may NOT move the default
 * flag: which preset the modal opens on has a route of its own, so a rename cannot change it by
 * accident and a hand-written body cannot smuggle it in.
 */
class UpdateExportPresetTest extends TestCase
{
    use RefreshDatabase;

    private function fields(array $overrides = []): array
    {
        return array_merge([
            'name' => 'MacBook',
            'format' => 'simple',
            'encoding' => 'UTF-8',
            'path_prefix' => '/Volumes/media/music',
        ], $overrides);
    }

    public function test_the_edit_page_renders_over_the_preset(): void
    {
        $user = User::factory()->create();
        $preset = ExportPreset::factory()->for($user)->create([
            'name' => 'Auto',
            'format' => 'simple',
            'encoding' => 'Windows-1252',
            'path_prefix' => '',
        ]);

        $this->actingAs($user)
            ->get("/dashboard/export-presets/{$preset->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/ExportPresets/Preset/ExportPresetPage')
                ->where('preset.id', $preset->id)
                ->where('preset.name', 'Auto')
                ->where('preset.encoding', 'Windows-1252')
                ->where('preset.pathPrefix', ''));
    }

    public function test_it_saves_the_preset_and_returns_to_the_list(): void
    {
        $user = User::factory()->create();
        $preset = ExportPreset::factory()->for($user)->create(['name' => 'MacBook']);

        $this->actingAs($user)
            ->put("/dashboard/export-presets/{$preset->id}", $this->fields([
                'name' => 'MacBook Pro',
                'format' => 'extended',
                'path_prefix' => '',
            ]))
            ->assertRedirect('/dashboard/export-presets')
            ->assertSessionHas('type', 'success');

        $preset->refresh();

        $this->assertSame('MacBook Pro', $preset->name);
        $this->assertSame('extended', $preset->format);
        $this->assertSame('', $preset->path_prefix);
    }

    public function test_saving_without_renaming_is_not_a_clash_with_itself(): void
    {
        $user = User::factory()->create();
        $preset = ExportPreset::factory()->for($user)->create(['name' => 'MacBook']);

        $this->actingAs($user)
            ->put("/dashboard/export-presets/{$preset->id}", $this->fields(['name' => 'MacBook']))
            ->assertSessionHasNoErrors();
    }

    public function test_renaming_onto_another_of_your_own_is_refused(): void
    {
        $user = User::factory()->create();
        ExportPreset::factory()->for($user)->create(['name' => 'Auto']);
        $preset = ExportPreset::factory()->for($user)->create(['name' => 'MacBook']);

        $this->actingAs($user)
            ->put("/dashboard/export-presets/{$preset->id}", $this->fields(['name' => 'Auto']))
            ->assertSessionHasErrors(['name' => __('preset.validation')['name.unique']]);

        $this->assertSame('MacBook', $preset->fresh()->name);
    }

    public function test_a_save_cannot_move_the_default_flag(): void
    {
        $user = User::factory()->create();
        $default = ExportPreset::factory()->default()->for($user)->create(['name' => 'MacBook']);
        $other = ExportPreset::factory()->for($user)->create(['name' => 'Auto']);

        $this->actingAs($user)->put("/dashboard/export-presets/{$other->id}", $this->fields([
            'name' => 'Auto',
            'is_default' => true,
        ]));

        $this->assertFalse($other->fresh()->is_default, 'is_default is not a field on this form');
        $this->assertTrue($default->fresh()->is_default);
    }

    public function test_somebody_elses_preset_is_a_404_in_both_directions(): void
    {
        $theirs = ExportPreset::factory()->create(['name' => 'Theirs']);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->get("/dashboard/export-presets/{$theirs->id}/edit")
            ->assertNotFound();

        $this->actingAs($intruder)
            ->put("/dashboard/export-presets/{$theirs->id}", $this->fields())
            ->assertNotFound();

        $this->assertSame('Theirs', $theirs->fresh()->name);
    }

    public function test_a_non_uuid_segment_never_reaches_model_binding(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard/export-presets/not-a-uuid/edit')
            ->assertNotFound();
    }
}
