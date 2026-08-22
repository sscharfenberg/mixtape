<?php

namespace Tests\Feature\Dashboard\ExportPresets;

use App\Models\ExportPreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deleting an export preset (`DELETE /dashboard/export-presets/{preset}`).
 *
 * THE HALF WORTH TESTING IS THE SUCCESSION. Deleting the preset that holds the default flag
 * must pass it on, or the reader is left with presets and no default — an export modal that has
 * quietly gone back to offering the config prefix, with nothing on any page to say why. Deleting
 * the LAST one must not try to promote anything, which is the case a naive "promote the first
 * survivor" gets wrong.
 */
class DeleteExportPresetTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_the_preset(): void
    {
        $user = User::factory()->create();
        $preset = ExportPreset::factory()->for($user)->create(['name' => 'MacBook']);

        $this->actingAs($user)
            ->from('/dashboard/export-presets')
            ->delete("/dashboard/export-presets/{$preset->id}")
            ->assertRedirect('/dashboard/export-presets')
            ->assertSessionHas('type', 'success');

        $this->assertDatabaseCount('export_presets', 0);
    }

    public function test_deleting_the_default_passes_the_flag_to_the_first_survivor(): void
    {
        $user = User::factory()->create();
        $default = ExportPreset::factory()->default()->for($user)->create(['name' => 'MacBook']);
        $auto = ExportPreset::factory()->for($user)->create(['name' => 'Auto']);
        $phone = ExportPreset::factory()->for($user)->create(['name' => 'Handy']);

        $this->actingAs($user)->delete("/dashboard/export-presets/{$default->id}");

        // Alphabetically first among the survivors, which is also the row the redrawn list
        // puts at the top — so the promotion matches what the reader is looking at.
        $this->assertTrue($auto->fresh()->is_default);
        $this->assertFalse($phone->fresh()->is_default);
    }

    public function test_deleting_a_non_default_leaves_the_default_alone(): void
    {
        $user = User::factory()->create();
        $default = ExportPreset::factory()->default()->for($user)->create(['name' => 'MacBook']);
        $other = ExportPreset::factory()->for($user)->create(['name' => 'Auto']);

        $this->actingAs($user)->delete("/dashboard/export-presets/{$other->id}");

        $this->assertTrue($default->fresh()->is_default);
    }

    public function test_deleting_the_last_preset_leaves_nothing_to_promote(): void
    {
        $user = User::factory()->create();
        $preset = ExportPreset::factory()->default()->for($user)->create();

        $this->actingAs($user)
            ->delete("/dashboard/export-presets/{$preset->id}")
            ->assertSessionHas('type', 'success');

        $this->assertDatabaseCount('export_presets', 0);
    }

    public function test_the_succession_never_crosses_accounts(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        $myDefault = ExportPreset::factory()->default()->for($mine)->create(['name' => 'MacBook']);
        $theirOnly = ExportPreset::factory()->for($theirs)->create(['name' => 'Auto']);

        $this->actingAs($mine)->delete("/dashboard/export-presets/{$myDefault->id}");

        $this->assertFalse($theirOnly->fresh()->is_default, "another account's preset is never promoted");
    }

    public function test_somebody_elses_preset_is_a_404(): void
    {
        $theirs = ExportPreset::factory()->create();

        $this->actingAs(User::factory()->create())
            ->delete("/dashboard/export-presets/{$theirs->id}")
            ->assertNotFound();

        $this->assertDatabaseCount('export_presets', 1);
    }

    public function test_guests_cannot_delete_a_preset(): void
    {
        $theirs = ExportPreset::factory()->create();

        $this->delete("/dashboard/export-presets/{$theirs->id}")->assertRedirect('/login');

        $this->assertDatabaseCount('export_presets', 1);
    }
}
