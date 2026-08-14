import { usePage } from "@inertiajs/vue3";
import type { Ref } from "vue";
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { useToast } from "Composables/useToast";

/** What a share link is: where it points, and the instant it stops working. */
export type ShareLink = {
    /** The absolute URL to hand out — `/s/{uuid}` on this instance's own domain. */
    url: string;
    /** When it expires, ISO-8601 and raw, for the client to format in the reader's locale. */
    validUntil: string;
};

/**
 * What can be shared — `App\Enums\ShareSubject`, and nothing else.
 *
 * No `genre`, deliberately: a genre is a shelf rather than something somebody chose to send.
 * `playlist` is the one entry with an owner — the server
 * refuses a playlist the reader does not own, so a page can only offer it for its own.
 */
export type ShareableSubject = "song" | "album" | "artist" | "playlist" | "audiobook";

/** What {@link useShareLink} hands its caller: the in-flight flag, the link, and the two verbs. */
export type UseShareLinkReturn = {
    /** True while the mint request is in flight, so the button can refuse a second press. */
    minting: Ref<boolean>;
    /** The minted link, or null before the first press and after `dismiss()`. */
    link: Ref<ShareLink | null>;
    /** Ask the server for this subject's link. Leaves `link` null if anything goes wrong. */
    mint: (subject: ShareableSubject, id: string) => Promise<void>;
    /** Forget the link, closing whatever was showing it. */
    dismiss: () => void;
};

/**
 * Mint the link that lets someone WITHOUT an account listen to one subject
 * (docs/sharing.md) — the server half of a detail page's "share" button.
 *
 * THE LINK CANNOT BE BUILT IN THE BROWSER, which is why this exists at all rather than the
 * button composing a URL from an id it already has. A share is a ROW: the server decides
 * what the address is, when it dies, and whether the reader already has one for this subject
 * (it hands the same link back rather than minting a second — see ShareController). None of
 * that is knowable here, so the modal has nothing to say until this resolves.
 *
 * A PLAIN `fetch`, NOT AN INERTIA VISIT, for the reason `useDeleteAccount` and the queue's
 * own sync are: the reader is not navigating. A visit would re-render the detail page —
 * re-keying a hero, a table and a queue — to deliver one string, and on a page carrying an
 * open form that swap is the documented way to lose what was typed (CLAUDE.md → prefetch).
 * Inertia's visits carry the CSRF token themselves, so this is one of the few places that
 * has to send it by hand, off the shared prop.
 *
 * FAILURE IS A TOAST AND NOTHING ELSE. There is no half-state to show: either there is a
 * link to copy or there is not, so a failed mint leaves `link` null, says so, and lets the
 * reader press again. The 429 is worth its own message — the route's ceiling is low on
 * purpose, and "you have shared a lot just now" is a different thing to be told than "that
 * did not work".
 */
export const useShareLink = (): UseShareLinkReturn => {
    const { t } = useI18n();
    const page = usePage();
    const { addToast } = useToast();
    const csrfToken = computed(() => page.props.csrfToken as string);

    const minting = ref(false);
    const link = ref<ShareLink | null>(null);

    /**
     * Ask the server for this subject's share link.
     *
     * Guarded against a second press while the first is in flight — the button is disabled
     * too, but a keyboard repeat can outrun a render, and minting twice is a row this reader
     * never asked for.
     */
    const mint = async (subject: ShareableSubject, id: string): Promise<void> => {
        if (minting.value) return;

        minting.value = true;

        try {
            const response = await fetch("/shares", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                    "X-CSRF-TOKEN": csrfToken.value
                },
                body: JSON.stringify({ subject, id })
            });

            if (!response.ok) {
                addToast(
                    t(response.status === 429 ? "music.share.tooMany" : "music.share.failed"),
                    "error",
                    4000
                );

                return;
            }

            link.value = (await response.json()) as ShareLink;
        } catch {
            // Offline, or the session rotated under us. Same answer either way: nothing to
            // show, and a reader who can press again.
            addToast(t("music.share.failed"), "error", 4000);
        } finally {
            minting.value = false;
        }
    };

    /** Forget the link — what closing the modal means. */
    const dismiss = (): void => {
        link.value = null;
    };

    return { minting, link, mint, dismiss };
};
