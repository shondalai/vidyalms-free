<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\WordPress\Content;

defined('ABSPATH') || exit;

/**
 * The signed-out state of the personal learner pages.
 *
 * It invites the learner to the host's own sign-in page and never renders,
 * reads or checks a credential: wp-login.php, or whatever login_url points at,
 * owns the form, its errors, two-factor steps, captcha and remember-me. The
 * return target comes from a fixed page list and is validated again before it
 * is handed to the host.
 */
final class SignInGate
{
    /** The pages that answer a guest with the gate. */
    public const PAGES = ['mylearning', 'achievements', 'preferences', 'aiactivity'];

    /** The words a site may change through the vidyalms/signin_context filter. */
    private const COPY_KEYS = ['kicker', 'title', 'lede', 'account', 'steps_title'];

    /**
     * @param string $return     The page's own fixed URL from Rewrites or a landing's url(), never request input.
     * @param string $heading    An h1 for pages whose template prints none.
     * @param string $pageKicker A kicker above that h1.
     *
     * @return array<string, mixed>
     */
    public static function context(string $page, string $return, string $heading = '', string $pageKicker = ''): array
    {
        $page = \in_array($page, self::PAGES, true) ? $page : 'mylearning';
        $return = wp_validate_redirect($return, Rewrites::myLearningUrl());
        $copy = self::copy($page);

        /**
         * Filters the words of the signed-out gate on a learner page.
         *
         * Only words are taken from the result: the URLs follow the host's own
         * login_url, register_url and lostpassword_url filters.
         *
         * @param array<string, mixed> $copy kicker, title, lede, account, steps_title and steps.
         * @param string               $page mylearning, achievements, preferences or aiactivity.
         */
        $filtered = apply_filters('vidyalms/signin_context', $copy, $page);

        return self::merge($copy, \is_array($filtered) ? $filtered : []) + [
            'page' => $page,
            'heading' => $heading,
            'page_kicker' => $pageKicker,
            'sign_in_url' => wp_login_url($return),
            'register_url' => get_option('users_can_register') ? wp_registration_url() : '',
            'reset_url' => wp_lostpassword_url(),
            'catalogue_url' => Rewrites::catalogueUrl(),
        ];
    }

    /** @param array<string, mixed> $context */
    public static function render(array $context): void
    {
        Templates::output('part-signin', $context);
    }

    /**
     * @return array{kicker: string, title: string, lede: string, account: string, steps_title: string,
     *     steps: list<array{name: string, detail: string}>}
     */
    private static function copy(string $page): array
    {
        [$title, $lede] = match ($page) {
            'achievements' => [
                __('Sign in to see your achievements', 'vidyalms'),
                __('Your certificates are kept here, each with a code anyone can check.', 'vidyalms'),
            ],
            'preferences' => [
                __('Sign in to manage your notifications', 'vidyalms'),
                __('Choose which course emails and notices reach you, and switch off the rest.', 'vidyalms'),
            ],
            'aiactivity' => [
                __('Sign in to see your AI activity', 'vidyalms'),
                __('A private record of the AI help you used in your courses.', 'vidyalms'),
            ],
            default => [
                __('Sign in to see your learning', 'vidyalms'),
                __('Your courses, your progress and your certificates are all here once you sign in.', 'vidyalms'),
            ],
        };
        // WordPress stores the name escaped; decode here, escape once at output.
        $site = trim(wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES));

        return [
            /* translators: Short label above the sign-in title. The page shows it in capitals. */
            'kicker' => __('One step away', 'vidyalms'),
            'title' => $title,
            'lede' => $lede,
            'account' => $site !== ''
                /* translators: %s: the name of this website, as its owner set it. */
                ? sprintf(__('Use the account you have on %s.', 'vidyalms'), $site)
                : __('Use the account you already have on this site.', 'vidyalms'),
            'steps_title' => __('What happens next', 'vidyalms'),
            'steps' => [
                [
                    'name' => __("Head to the site's sign-in page", 'vidyalms'),
                    /* translators: The site's own sign-in page, reached from the Sign in button. */
                    'detail' => __(
                        'It looks after your password and any extra security step the site uses.',
                        'vidyalms'
                    ),
                ],
                [
                    'name' => __('Come straight back', 'vidyalms'),
                    'detail' => __('You return to this page as soon as you are signed in.', 'vidyalms'),
                ],
                [
                    'name' => __('Pick up where you left off', 'vidyalms'),
                    'detail' => __('Everything you have started is waiting for you.', 'vidyalms'),
                ],
            ],
        ];
    }

    /**
     * Keeps the filter to words: a non-string value, an empty string or an
     * unknown key is ignored, so a filter can neither blank the gate nor add a
     * URL.
     *
     * @param array<string, mixed> $copy
     * @param array<mixed>         $filtered
     *
     * @return array<string, mixed>
     */
    private static function merge(array $copy, array $filtered): array
    {
        foreach (self::COPY_KEYS as $key) {
            if (isset($filtered[$key]) && \is_string($filtered[$key]) && trim($filtered[$key]) !== '') {
                $copy[$key] = $filtered[$key];
            }
        }

        $steps = $filtered['steps'] ?? null;

        if (!\is_array($steps) || $steps === [] || \count($steps) > 5) {
            return $copy;
        }

        $clean = [];

        foreach ($steps as $step) {
            if (
                !\is_array($step) || !\is_string($step['name'] ?? null) || !\is_string($step['detail'] ?? null)
                || trim($step['name']) === ''
            ) {
                return $copy;
            }

            $clean[] = ['name' => $step['name'], 'detail' => $step['detail']];
        }

        $copy['steps'] = $clean;

        return $copy;
    }
}
