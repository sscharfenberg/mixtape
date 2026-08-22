<?php

namespace Tests\Feature\Dashboard\ExportPresets;

use App\Models\ExportPreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Choosing which preset the export modal opens on
 * (`PATCH /dashboard/export-presets/{preset}/default`).
 *
 * THE INVARIANT IS THE WHOLE TEST: at most one default per user, and never a flag reaching
 * across accounts. Production enforces it with a partial unique index; this suite runs on
 * sqlite, which has none — so these assertions are the only thing standing between a broken
 * write and a modal that opens on a preset the reader did not choose. Neither state raises an
 * error anywhere, which is why they are counted rather than eyeballed.
 */
class DefaultExportPresetTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_promotes_the_preset_and_demotes_the_previous_one(): void
    {
        $user = User::factory()->create();
        $was = ExportPreset::factory()->default()->for($user)->create(['name' => 'MacBook']);
        $now = ExportPreset::factory()->for($user)->create(['name' => 'Auto']);

        $this->actingAs($user)
            ->from('/dashboard/export-presets')
            ->patch("/dashboard/export-presets/{$now->id}/default")
            ->assertRedirect('/dashboard/export-presets')
            ->assertSessionHas('type', 'success');

        $this->assertTrue($now->fresh()->is_default);
        $this->assertFalse($was->fresh()->is_default);
    }

    public function test_exactly_one_default_survives_the_press(): void
    {
        $user = User::factory()->create();
        ExportPreset::factory()->default()->for($user)->create(['name' => 'MacBook']);
        $target = ExportPreset::factory()->for($user)->create(['name' => 'Auto']);
        ExportPreset::factory()->for($user)->create(['name' => 'Handy']);

        $this->actingAs($user)->patch("/dashboard/export-presets/{$target->id}/default");

        $this->assertSame(
            1,
            ExportPreset::query()->where('user_id', $user->id)->where('is_default', true)->count()
        );
    }

    public function test_pressing_it_twice_is_harmless(): void
    {
        $user = User::factory()->create();
        $preset = ExportPreset::factory()->default()->for($user)->create();

        $this->actingAs($user)->patch("/dashboard/export-presets/{$preset->id}/default");
        $this->actingAs($user)->patch("/dashboard/export-presets/{$preset->id}/default");

        $this->assertTrue($preset->fresh()->is_default);
        $this->assertSame(1, ExportPreset::query()->where('is_default', true)->count());
    }

    public function test_it_never_demotes_another_accounts_default(): void
    {
        $mine = User::factory()->create();
        $theirs = User::factory()->create();
        $theirDefault = ExportPreset::factory()->default()->for($theirs)->create();
        $myPreset = ExportPreset::factory()->for($mine)->create();

        $this->actingAs($mine)->patch("/dashboard/export-presets/{$myPreset->id}/default");

        $this->assertTrue($theirDefault->fresh()->is_default);
        $this->assertTrue($myPreset->fresh()->is_default);
    }

    public function test_somebody_elses_preset_is_a_404(): void
    {
        $theirs = ExportPreset::factory()->create();

        $this->actingAs(User::factory()->create())
            ->patch("/dashboard/export-presets/{$theirs->id}/default")
            ->assertNotFound();

        $this->assertFalse($theirs->fresh()->is_default);
    }
}
