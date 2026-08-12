{{--
    The Open Graph / Twitter card for this page — the tags a chat window reads when somebody
    pastes a link, built from App\Support\SocialCard (see App\Services\Meta\SocialCards for
    which URLs get which card, and why there can only be three).

    IT IS SERVER-RENDERED, WHICH IS THE POINT. Unfurl crawlers do not run JavaScript, so none
    of this can live in a Vue page — and `public/robots.txt` has to let the named ones fetch
    the URL at all, or tags nobody reads is exactly what this is.

    NO `twitter:card=summary_large_image`, deliberately: the image here is almost always a
    RECORD SLEEVE, and the large card crops to roughly 2:1. A square cover survives that badly
    — the top and bottom of the artwork are simply gone — where `summary` shows it whole, small
    and beside the text. The default when there is no image at all is the same tag, and reads
    as a plain text card.
--}}
<meta name="description" content="{{ $card->description }}" />

<meta property="og:site_name" content="{{ config('app.name', 'MixTape') }}" />
<meta property="og:type" content="website" />
<meta property="og:title" content="{{ $card->title }}" />
<meta property="og:description" content="{{ $card->description }}" />
<meta property="og:url" content="{{ $card->url }}" />
<meta property="og:locale" content="{{ str_replace('-', '_', app()->getLocale()) }}" />

<meta name="twitter:card" content="summary" />
<meta name="twitter:title" content="{{ $card->title }}" />
<meta name="twitter:description" content="{{ $card->description }}" />

@if ($card->image !== null)
    {{-- `twitter:image` as well as `og:image`: X reads the OG tag as a fallback, but its own
         takes precedence, and a page carrying one and not the other is the shape that renders
         everywhere except there. --}}
    <meta property="og:image" content="{{ $card->image }}" />
    <meta name="twitter:image" content="{{ $card->image }}" />
    {{-- The accessible name a screen reader announces for the preview, which several
         platforms surface. The title already says what the picture is of. --}}
    <meta property="og:image:alt" content="{{ $card->title }}" />
@endif
