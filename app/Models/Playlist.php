<?php

namespace App\Models;

use Database\Factories\PlaylistFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user-owned, saved playlist (data-model.md → "playlists as a first-class
 * concept"). Distinct from the ephemeral play queue, which lives client-side +
 * in `player_states`. Because `tracks` is unified, a playlist can freely mix
 * music and audiobook chapters — its rows are just `track_id`s.
 */
#[Fillable(['name', 'description', 'position'])]
class Playlist extends Model
{
    /** @use HasFactory<PlaylistFactory> */
    use HasFactory, HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Ordered entries. @return HasMany<PlaylistTrack, $this> */
    public function playlistTracks(): HasMany
    {
        return $this->hasMany(PlaylistTrack::class)->orderBy('position');
    }

    /**
     * The tracks themselves, in playlist order — convenience over the pivot.
     *
     * @return BelongsToMany<Track, $this>
     */
    public function tracks(): BelongsToMany
    {
        return $this->belongsToMany(Track::class, 'playlist_tracks')
            ->withPivot(['id', 'position'])
            ->orderBy('playlist_tracks.position');
    }
}
