<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * How the app decides which language to answer in — `ConfigureLocale`, and the endpoint that
 * changes it.
 *
 * THE HEADER IS ATTACKER-CONTROLLED AND THE PARSER IS THE FIRST THING A STRANGER TOUCHES.
 * The browser-sniff branch runs for every visitor with no session locale, which is every first
 * request — including `/` and the guest `/s/{share}` space, the two surfaces this instance
 * deliberately exposes to the internet. A header that cannot be parsed must therefore fall back,
 * never raise: reading a weight out of `de;x` or `en;q` is an undefined array key, which Laravel
 * turns into a 500.
 *
 * The precedence itself (user → session → browser → default) is asserted here rather than in a
 * unit test because it is the middleware's whole job and it only exists in a real request.
 */
class LocaleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every shape of `Accept-Language` that has ever looked like a crash.
     *
     * @return array<string, array<int, string>>
     */
    public static function malformedHeaders(): array
    {
        return [
            'no equals after the semicolon' => ['de;x'],
            'a q with no value' => ['en;q'],
            'a bare semicolon' => ['en;'],
            'a trailing comma' => ['en,'],
            'empty' => [''],
            'only punctuation' => [',;'],
            'a weight that is not a number' => ['de;q=nonsense'],
            'nothing recognisable' => ['\\x00nonsense'],
        ];
    }

    #[DataProvider('malformedHeaders')]
    public function test_a_malformed_accept_language_never_500s(string $header): void
    {
        $this->withHeader('Accept-Language', $header)
            ->get('/')
            ->assertOk();
    }

    public function test_it_honours_the_weightiest_supported_language(): void
    {
        // English outranks German here, so the sniff must not simply take the first tag.
        $this->withHeader('Accept-Language', 'de;q=0.3,en;q=0.9')->get('/')->assertOk();
        $this->assertSame('en', app()->getLocale());
    }

    public function test_an_unsupported_language_falls_back_to_the_default(): void
    {
        $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')->get('/')->assertOk();
        $this->assertSame(config('app.locale'), app()->getLocale());
    }

    public function test_a_signed_in_readers_own_choice_beats_the_browsers(): void
    {
        // The precedence that matters most: a reader who picked English keeps it on a machine
        // whose browser asks for German.
        $user = User::factory()->create(['locale' => 'en']);

        $this->actingAs($user)->withHeader('Accept-Language', 'de')->get('/dashboard');

        $this->assertSame('en', app()->getLocale());
    }
}
