/*
 * First-party analytics beacon.
 *
 * Reports the handful of things worth counting - a tapped phone number, a
 * started booking - to this site's own endpoint. No third-party script is
 * loaded and nothing is stored in the browser, so there is no cookie to ask
 * permission for.
 */
const endpoint = () => document.body.dataset.analyticsEndpoint;

const token = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

export const track = (name, metadata = {}) => {
    const url = endpoint();

    if (!url) {
        return;
    }

    fetch(url, {
        method: 'POST',
        keepalive: true,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': token(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ name, path: window.location.pathname, metadata }),
    }).catch(() => {});
};

document.addEventListener('click', (event) => {
    const tagged = event.target.closest('[data-analytics]');

    if (tagged) {
        track(tagged.dataset.analytics, {
            label: tagged.dataset.analyticsLabel || tagged.textContent.trim().slice(0, 80),
        });

        return;
    }

    /*
     * A tapped phone number is the clearest sign of interest this site
     * gets, and it never reaches the server on its own - the browser hands
     * the call straight to the phone.
     */
    const tel = event.target.closest('a[href^="tel:"]');

    if (tel) {
        track('phone_click', { number: tel.getAttribute('href').replace('tel:', '') });
    }
});
