{{--
    The consent runtime: reads the visitor's choice from the first-party
    cookie, wakes up <script type="text/plain" data-cms-consent> tags she
    has allowed, and drives the banner. Inline and dependency-free, so it
    works on a site that has not rebuilt its assets.
--}}
@php
    $settings = app(\Gadya\Cms\Privacy\Consent::class)->settings();
    $nonce = \Illuminate\Support\Facades\Vite::cspNonce();
@endphp
<script data-cms-consent-runtime @if ($nonce)nonce="{{ $nonce }}" @endif data-config="{{ json_encode(['cookie' => $settings['cookie'], 'days' => $settings['cookie_days'], 'banner' => $settings['banner_enabled'], 'honourGpc' => $settings['honour_gpc']]) }}">
(() => {
    const script = document.currentScript;
    const config = JSON.parse(script.dataset.config);
    const gpc = config.honourGpc && navigator.globalPrivacyControl === true;

    const read = () => {
        const entry = document.cookie.split('; ').find((cookie) => cookie.startsWith(config.cookie + '='));

        if (!entry) {
            return null;
        }

        try {
            return JSON.parse(decodeURIComponent(entry.slice(config.cookie.length + 1)));
        } catch {
            return null;
        }
    };

    const allowed = (category) => {
        if (category === 'necessary') {
            return true;
        }

        if (category === 'marketing' && gpc) {
            return false;
        }

        const choice = read();

        return choice ? choice[category] === true : !config.banner;
    };

    const activate = () => {
        document.querySelectorAll('script[type="text/plain"][data-cms-consent]').forEach((inert) => {
            if (!allowed(inert.dataset.cmsConsent)) {
                return;
            }

            const live = document.createElement('script');

            for (const { name, value } of Array.from(inert.attributes)) {
                if (!['type', 'data-cms-consent', 'data-cms-src', 'data-cms-type'].includes(name)) {
                    live.setAttribute(name, value);
                }
            }

            if (inert.dataset.cmsType) {
                live.type = inert.dataset.cmsType;
            }

            if (inert.dataset.cmsSrc) {
                live.src = inert.dataset.cmsSrc;
            }

            live.text = inert.text;
            inert.replaceWith(live);
        });
    };

    const save = (wanted) => {
        const before = { analytics: allowed('analytics'), marketing: allowed('marketing') };
        const choice = {
            v: 1,
            necessary: true,
            analytics: wanted.analytics === true,
            marketing: wanted.marketing === true && !gpc,
            gpc,
            at: new Date().toISOString(),
        };

        document.cookie = config.cookie + '=' + encodeURIComponent(JSON.stringify(choice))
            + '; Max-Age=' + (config.days * 86400) + '; Path=/; SameSite=Lax'
            + (location.protocol === 'https:' ? '; Secure' : '');

        document.dispatchEvent(new CustomEvent('gadya:consent', { detail: choice }));

        /* A tag already running cannot be unloaded; starting the page again is the only honest way to stop it. */
        if ((before.analytics && !choice.analytics) || (before.marketing && !choice.marketing)) {
            location.reload();

            return;
        }

        activate();
    };

    const banner = () => document.querySelector('[data-cms-consent-banner]');
    let opener = null;

    const close = () => {
        const element = banner();

        if (element) {
            element.hidden = true;
        }

        if (opener) {
            opener.focus();
            opener = null;
        }
    };

    const open = (trigger = null, expanded = true) => {
        const element = banner();

        if (!element) {
            return;
        }

        const choice = read();

        element.querySelectorAll('[data-cms-consent-category]').forEach((box) => {
            box.checked = choice ? choice[box.value] === true : false;
        });

        element.querySelectorAll('[data-cms-consent-gpc]').forEach((note) => {
            note.hidden = !gpc;
        });

        if (gpc) {
            element.querySelectorAll('[data-cms-consent-category="marketing"]').forEach((box) => {
                box.checked = false;
                box.disabled = true;
            });
        }

        const choices = element.querySelector('[data-cms-consent-choices]');
        const toggle = element.querySelector('[data-cms-consent-customise]');

        choices.hidden = !expanded;
        toggle.setAttribute('aria-expanded', String(expanded));
        element.hidden = false;

        if (trigger) {
            opener = trigger;
            element.querySelector('[data-cms-consent-heading]').focus();
        }
    };

    const wire = () => {
        const element = banner();

        if (element && !element.dataset.cmsWired) {
            element.dataset.cmsWired = 'true';

            element.querySelector('[data-cms-consent-accept]').addEventListener('click', () => {
                save({ analytics: true, marketing: true });
                close();
            });

            element.querySelector('[data-cms-consent-reject]').addEventListener('click', () => {
                save({ analytics: false, marketing: false });
                close();
            });

            element.querySelector('[data-cms-consent-customise]').addEventListener('click', (event) => {
                const choices = element.querySelector('[data-cms-consent-choices]');
                const expanded = choices.hidden;

                choices.hidden = !expanded;
                event.currentTarget.setAttribute('aria-expanded', String(expanded));

                if (expanded) {
                    choices.querySelector('input:not([disabled])')?.focus();
                }
            });

            element.querySelector('[data-cms-consent-choices]').addEventListener('submit', (event) => {
                event.preventDefault();

                const wanted = {};

                element.querySelectorAll('[data-cms-consent-category]').forEach((box) => {
                    wanted[box.value] = box.checked;
                });

                save(wanted);
                close();
            });

            element.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && read()) {
                    close();
                }
            });

            if (config.banner && !read()) {
                open(null, false);
            }
        }

        document.querySelectorAll('[data-cms-consent-open]').forEach((link) => {
            if (link.dataset.cmsWired) {
                return;
            }

            link.dataset.cmsWired = 'true';
            link.addEventListener('click', (event) => {
                if (!banner()) {
                    return;
                }

                event.preventDefault();
                open(link);
            });
        });

        activate();
    };

    window.gadyaConsent = { allowed, save, read, open: () => open(document.activeElement) };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wire);
    } else {
        wire();
    }
})();
</script>
