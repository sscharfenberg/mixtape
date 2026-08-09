<?php

namespace App\Console\Commands;

use App\Enums\TrackType;
use App\Models\Playlist;
use App\Models\PlaylistTrack;
use App\Models\Track;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

use function Laravel\Prompts\select;

/**
 * Fills a playlist with tracks taken at random from the library that is ACTUALLY THERE.
 *
 * WHY THIS EXISTS RATHER THAN A SEEDER. It was written when nothing in the UI could add a
 * track to a playlist, so the only ways to get a populated one were a seeder or this. A
 * seeder is useless on any instance whose collection is real: LibrarySeeder's tracks are
 * factory rows pointing at paths no file was ever written to, so the playlist looks right and
 * every row is silently unplayable — and running `migrate:fresh --seed` on a box that has a
 * scanned collection would throw that collection away to fix it. That seeder is switched off
 * in DatabaseSeeder for exactly this reason. This command picks from `tracks` instead, so
 * whatever `app:update` found on disk is what lands in the playlist, and pressing play really
 * plays.
 *
 * THE UI CAUGHT UP ON 2026-08-09 (PlaylistTracksController + App\Services\Playlists\
 * PlaylistAdditions), so this is no longer the only way to build a playlist. It stays useful
 * for filling a long list quickly with something arbitrary — which is what a test playlist
 * wants and what a hand-picked one is not. Note the two APPEND differently on purpose: this
 * creates entries one at a time (a dozen rows, and `$touches` does the rest), where the
 * service bulk-inserts and touches the playlist by hand because "add this artist" can be
 * hundreds of rows.
 *
 * Nothing here is destructive — it only ever APPENDS, so running it twice gives a longer
 * playlist rather than a replaced one, and it is safe against a real account's real data.
 */
class FillPlaylist extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:playlist
                            {name=Testliste : Name of the playlist — created if the account has none by that name}
                            {--user= : Owner\'s username. Asked for when the instance has more than one account}
                            {--tracks=12 : How many tracks to add}
                            {--type=music : Which kind to pick: music, audiobook, or any}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fill a playlist with random tracks from the scanned library';

    /**
     * Resolve the owner and the playlist, append the tracks, and say what happened.
     *
     * The order matters only in that everything is validated before anything is written: an
     * unknown user, an unusable `--type` or an empty library all bail before the playlist is
     * created, so a failed run leaves no half-made playlist behind.
     */
    public function handle(): int
    {
        $type = $this->trackType();
        if ($type === false) {
            $this->error(__('playlist_command.type_invalid'));

            return self::INVALID;
        }

        $count = (int) $this->option('tracks');
        if ($count < 1) {
            $this->error(__('playlist_command.tracks_invalid'));

            return self::INVALID;
        }

        $user = $this->owner();
        if ($user === null) {
            return self::FAILURE;
        }

        $tracks = $this->pick($type, $count);
        if ($tracks->isEmpty()) {
            $this->error(__('playlist_command.library_empty'));

            return self::FAILURE;
        }

        $playlist = $this->playlist($user);
        $added = $this->append($playlist, $tracks);

        $this->newLine();
        $this->info(trans_choice('playlist_command.filled', $added, [
            'count' => $added,
            'name' => $playlist->name,
            'user' => $user->name,
        ]));
        // Asked for more than the library could give — said plainly rather than silently
        // delivering a shorter list, which would read as the command having half-failed.
        if ($added < $count) {
            $this->line('  '.__('playlist_command.short', ['asked' => $count]));
        }
        $this->line('  '.route('playlists.show', $playlist->id));
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * The `--type` option as a TrackType, null for "any", or false when it is not a kind.
     *
     * A three-way return because "any" and "invalid" are different answers and both are
     * non-values; the caller checks for `false` first, so null can mean no filter.
     */
    private function trackType(): TrackType|null|false
    {
        return match (strtolower((string) $this->option('type'))) {
            'music' => TrackType::Music,
            'audiobook' => TrackType::Audiobook,
            'any' => null,
            default => false,
        };
    }

    /**
     * Whose playlist this is.
     *
     * `--user` when given; otherwise the only account on the instance, or a picker when there
     * are several. Not defaulting to "the first user" silently: this box is shared with family
     * and friends, so a command that guessed would occasionally fill a stranger's playlist.
     */
    private function owner(): ?User
    {
        $name = $this->option('user');

        if ($name !== null) {
            $user = User::query()->where('name', $name)->first();
            if ($user === null) {
                $this->error(__('playlist_command.no_such_user', ['name' => $name]));
            }

            return $user;
        }

        $names = User::query()->orderBy('name')->pluck('name', 'id');

        if ($names->isEmpty()) {
            $this->error(__('playlist_command.no_users'));

            return null;
        }

        if ($names->count() === 1) {
            return User::query()->find($names->keys()->first());
        }

        return User::query()->find(select(
            label: __('playlist_command.user_label'),
            options: $names->all(),
        ));
    }

    /**
     * `$count` random tracks of the wanted kind, or fewer when the library holds fewer.
     *
     * `inRandomOrder()` so two runs give different playlists — the point is to have something
     * varied to listen to while testing, not a reproducible fixture (that is E2ESeeder's job).
     *
     * @return Collection<int, Track>
     */
    private function pick(?TrackType $type, int $count): Collection
    {
        return Track::query()
            ->when($type !== null, fn ($query) => $query->where('type', $type))
            ->inRandomOrder()
            ->limit($count)
            ->get();
    }

    /**
     * The named playlist, created for this user if they have none by that name.
     *
     * Matched on the name because that is what the account already treats as unique — the
     * `(user_id, name)` index — so a second run tops the same list up rather than failing on
     * the constraint or making "Testliste (2)".
     */
    private function playlist(User $user): Playlist
    {
        $existing = Playlist::query()
            ->where('user_id', $user->id)
            ->where('name', $this->argument('name'))
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $last = Playlist::query()->where('user_id', $user->id)->max('position');

        return $user->playlists()->create([
            'name' => $this->argument('name'),
            'description' => __('playlist_command.description'),
            'position' => $last === null ? 0 : ((int) $last) + 1,
        ]);
    }

    /**
     * Append the tracks after whatever the playlist already holds, and return how many landed.
     *
     * In a TRANSACTION, so an interrupted run cannot leave a playlist with a gap in its
     * positions — the column is meant to be contiguous, and the reorder path renumbers the
     * whole set on that assumption.
     *
     * Created one at a time rather than through a bulk insert, deliberately: the pivot needs a
     * uuid primary key of its own and its `$touches` has to bump the playlist's `updated_at`,
     * and a bulk insert would skip both.
     *
     * @param  Collection<int, Track>  $tracks
     */
    private function append(Playlist $playlist, Collection $tracks): int
    {
        $next = (int) (PlaylistTrack::query()->where('playlist_id', $playlist->id)->max('position') ?? -1) + 1;

        return DB::transaction(function () use ($playlist, $tracks, $next): int {
            foreach ($tracks->values() as $index => $track) {
                $playlist->playlistTracks()->create([
                    'track_id' => $track->id,
                    'position' => $next + $index,
                ]);
            }

            return $tracks->count();
        });
    }
}
