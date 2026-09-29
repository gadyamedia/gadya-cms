/*
 * Built forms, in the browser.
 *
 * Inlined once per page by the first form drawn, and safe to load twice.
 * A single-step form already works without any of this; what it adds is
 * the step-by-step form (progress, back and next, checking each step
 * before moving on), showing and hiding questions as answers change,
 * keeping what someone typed if they leave and come back, sending
 * without a page load, and counting views, starts and steps for the
 * form's own figures. The same show-and-hide rules run on the server,
 * which has the last word.
 */
(() => {
    if (window.gadyaForms) {
        window.gadyaForms.start();

        return;
    }

    const selector = '[data-cms-bform]';
    const lower = (value) => String(value ?? '').trim().toLowerCase();

    const readLogic = (element) => {
        try {
            return element?.dataset.logic ? JSON.parse(element.dataset.logic) : null;
        } catch {
            return null;
        }
    };

    /* The answers of one field, as a list of lower-case strings. */
    const answersOf = (form, key) => {
        const field = form.querySelector(`[data-cms-field="${CSS.escape(key)}"]`);

        if (!field || field.hidden) {
            return [];
        }

        const values = [];

        field.querySelectorAll('input, select, textarea').forEach((input) => {
            if (input.disabled) {
                return;
            }

            if (input.type === 'checkbox' || input.type === 'radio') {
                if (input.checked) {
                    values.push(input.type === 'checkbox' && input.value === '1' ? 'yes' : lower(input.value));
                }

                return;
            }

            if (input.tagName === 'SELECT' && input.multiple) {
                [...input.selectedOptions].forEach((option) => values.push(lower(option.value), lower(option.textContent)));

                return;
            }

            if (input.tagName === 'SELECT' && input.value !== '') {
                values.push(lower(input.value), lower(input.selectedOptions[0]?.textContent));

                return;
            }

            if (input.type !== 'file' && input.value !== '') {
                values.push(lower(input.value));
            }
        });

        /* A radio or checkbox answer also matches the words on its label. */
        field.querySelectorAll('input:checked').forEach((input) => {
            const label = field.querySelector(`label[for="${CSS.escape(input.id)}"]`);

            if (label) {
                values.push(lower(label.textContent));
            }
        });

        return values.filter((value) => value !== '');
    };

    const passes = (form, rule) => {
        const answers = answersOf(form, rule.field);
        const expected = lower(rule.value);

        switch (rule.operator) {
            case 'empty': return answers.length === 0;
            case 'not_empty': return answers.length > 0;
            case 'equals': return answers.includes(expected);
            case 'not_equals': return !answers.includes(expected);
            case 'contains': return expected !== '' && answers.some((answer) => answer.includes(expected));
            case 'not_contains': return !(expected !== '' && answers.some((answer) => answer.includes(expected)));
            case 'greater_than': return answers.length > 0 && Number(answers[0]) > Number(expected);
            case 'less_than': return answers.length > 0 && Number(answers[0]) < Number(expected);
            default: return true;
        }
    };

    const shows = (form, logic) => {
        if (!logic || !Array.isArray(logic.rules) || logic.rules.length === 0) {
            return true;
        }

        const results = logic.rules.map((rule) => passes(form, rule));
        const matched = logic.match === 'any' ? results.includes(true) : !results.includes(false);

        return logic.action === 'hide' ? !matched : matched;
    };

    const setShown = (element, shown) => {
        element.hidden = !shown;

        element.querySelectorAll('input, select, textarea').forEach((input) => {
            if (input.closest('.cms-form__trap')) {
                return;
            }

            input.disabled = !shown;
        });
    };

    /*
     * Where this visit began: the campaign in the address, the site that
     * sent them and the page they landed on - noted once per tab, on the
     * first page with a form this script sees, and posted with the form.
     * It never leaves the browser except with an enquiry, and is not an
     * identifier. The landing page is only noted when this page is the
     * first of the visit (nothing, or another site, sent them here);
     * otherwise the server's note of the first request fills it in.
     */
    const firstTouchKey = 'gadya-cms:first-touch';
    const campaignKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid'];

    const firstTouch = () => {
        let kept = null;

        try {
            kept = JSON.parse(sessionStorage.getItem(firstTouchKey) || 'null');
        } catch {
            kept = null;
        }

        if (kept && typeof kept === 'object') {
            return kept;
        }

        const noted = {};
        const params = new URLSearchParams(window.location.search);
        let referrerHost = '';

        try {
            referrerHost = document.referrer ? new URL(document.referrer).host : '';
        } catch {
            referrerHost = '';
        }

        campaignKeys.forEach((key) => {
            const value = params.get(key);

            if (value) {
                noted[key] = value.slice(0, 200);
            }
        });

        if (referrerHost !== window.location.host) {
            noted.landing_page = window.location.href.slice(0, 500);

            if (document.referrer) {
                noted.referrer = document.referrer.slice(0, 500);
            }
        }

        try {
            sessionStorage.setItem(firstTouchKey, JSON.stringify(noted));
        } catch {
            /* Private browsing: the server's note stands in. */
        }

        return noted;
    };

    const fillAttribution = (form) => {
        const noted = firstTouch();

        form.querySelectorAll('[data-cms-attribution]').forEach((input) => {
            const value = noted[input.dataset.cmsAttribution];

            if (typeof value === 'string' && value !== '') {
                input.value = value;
            }
        });
    };

    const wire = (root) => {
        if (root.dataset.cmsBformReady) {
            return;
        }

        root.dataset.cmsBformReady = '1';

        const form = root.querySelector('[data-cms-bform-form]');

        if (!form) {
            return;
        }

        const steps = [...form.querySelectorAll('[data-cms-step]')];
        const multi = steps.length > 1;
        const back = form.querySelector('[data-cms-back]');
        const next = form.querySelector('[data-cms-next]');
        const submit = form.querySelector('[data-cms-submit]');
        const summary = form.querySelector('[data-cms-summary]');
        const stepInput = form.querySelector('[data-cms-step-input]');
        const started = form.querySelector('[data-cms-started]');
        const successBox = root.querySelector('[data-cms-success]');
        const storageKey = `gadya-cms-form:${root.dataset.slug}:${root.dataset.version}`;
        const preview = root.hasAttribute('data-preview');
        let current = Number(root.dataset.startStep || 0);
        let begun = false;
        const seenSteps = new Set();

        form.noValidate = true;

        if (!preview) {
            fillAttribution(form);
        }

        /* ---------- counting ---------- */

        const count = (name, step = null) => {
            if (!root.dataset.events) {
                return;
            }

            try {
                fetch(root.dataset.events, {
                    method: 'POST',
                    keepalive: true,
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({ name, step, path: window.location.pathname, referrer: document.referrer || null }),
                }).catch(() => {});
            } catch {
                /* Counting is never worth an error. */
            }
        };

        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver((entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    count('view');
                    observer.disconnect();
                }
            }, { threshold: 0.3 });

            observer.observe(root);
        } else {
            count('view');
        }

        const begin = () => {
            if (begun) {
                return;
            }

            begun = true;

            if (started && !started.value) {
                started.value = String(Math.floor(Date.now() / 1000));
            }

            count('start');
        };

        /* ---------- show and hide ---------- */

        const visibleSteps = () => steps.filter((step) => !step.dataset.hiddenByLogic);

        const applyLogic = () => {
            /* A step whose rule hides it is skipped, and nothing in it is sent. */
            steps.forEach((step) => {
                const shown = shows(form, readLogic(step));

                if (shown) {
                    delete step.dataset.hiddenByLogic;
                } else {
                    step.dataset.hiddenByLogic = '1';
                }

                step.querySelectorAll('input, select, textarea').forEach((input) => {
                    if (!input.closest('.cms-form__trap')) {
                        input.disabled = !shown;
                    }
                });
            });

            /* In order, so a question hidden above reads as empty below. */
            form.querySelectorAll('div[data-cms-field]').forEach((field) => {
                setShown(field, !field.closest('[data-hidden-by-logic="1"]') && shows(form, readLogic(field)));
            });

            form.querySelectorAll('[data-cms-field][data-required]').forEach((field) => {
                field.querySelectorAll('input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]), select, textarea').forEach((input) => {
                    if (input.name && !input.name.endsWith('[line2]')) {
                        input.required = !field.hidden;
                    }
                });
            });
        };

        /* ---------- steps ---------- */

        const progress = () => {
            const shown = visibleSteps();
            const position = Math.max(0, shown.indexOf(steps[current]));
            const number = form.querySelector('[data-cms-step-number]');
            const total = form.querySelector('[data-cms-step-total]');
            const bar = form.querySelector('[data-cms-bar]');

            if (number) {
                number.textContent = String(position + 1);
            }

            if (total) {
                total.textContent = String(shown.length);
            }

            if (bar) {
                bar.style.transform = `scaleX(${(position + 1) / Math.max(1, shown.length)})`;
            }
        };

        const show = (index, focus = true) => {
            if (!multi) {
                return;
            }

            current = index;

            steps.forEach((step, position) => {
                step.hidden = position !== index;
            });

            const shown = visibleSteps();
            const position = shown.indexOf(steps[index]);

            back.hidden = position <= 0;
            next.hidden = position >= shown.length - 1;
            submit.hidden = position < shown.length - 1;

            if (stepInput) {
                stepInput.value = String(index);
            }

            progress();

            if (!seenSteps.has(index)) {
                seenSteps.add(index);

                if (index > 0) {
                    count('step', index);
                }
            }

            if (focus) {
                steps[index].querySelector('legend')?.focus();
            }

            save();
        };

        const neighbour = (direction) => {
            const shown = visibleSteps();
            const position = shown.indexOf(steps[current]);

            return shown[position + direction] ? steps.indexOf(shown[position + direction]) : null;
        };

        /* ---------- errors ---------- */

        const fieldOf = (key) => form.querySelector(`[data-cms-field="${CSS.escape(key)}"]`);

        const clearErrors = (scope = form) => {
            scope.querySelectorAll('[data-cms-error]').forEach((error) => {
                error.textContent = '';
                error.hidden = true;
            });
            scope.querySelectorAll('[aria-invalid="true"]').forEach((input) => input.removeAttribute('aria-invalid'));
            scope.querySelectorAll('.cms-bform__field--invalid').forEach((field) => field.classList.remove('cms-bform__field--invalid'));

            if (summary && scope === form) {
                summary.hidden = true;
            }
        };

        const showErrors = (errors) => {
            const entries = Object.entries(errors);
            let firstField = null;

            entries.forEach(([key, messages]) => {
                const field = fieldOf(key.split('.')[0]);
                const message = Array.isArray(messages) ? messages[0] : String(messages);

                if (!field) {
                    return;
                }

                firstField ??= field;
                field.classList.add('cms-bform__field--invalid');

                const error = field.querySelector('[data-cms-error]');

                if (error) {
                    error.textContent = message;
                    error.hidden = false;
                }

                field.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach((input) => input.setAttribute('aria-invalid', 'true'));
            });

            if (summary) {
                const list = summary.querySelector('ul');

                list.innerHTML = '';
                entries.forEach(([key, messages]) => {
                    const field = fieldOf(key.split('.')[0]);
                    const target = field?.querySelector('input:not([type="hidden"]), select, textarea, legend');
                    const item = document.createElement('li');
                    const link = document.createElement('a');

                    link.href = target?.id ? `#${target.id}` : '#';
                    link.textContent = Array.isArray(messages) ? messages[0] : String(messages);
                    link.addEventListener('click', (event) => {
                        event.preventDefault();
                        target?.focus();
                    });
                    item.append(link);
                    list.append(item);
                });
                summary.hidden = entries.length === 0;
            }

            if (firstField) {
                const stepIndex = steps.indexOf(firstField.closest('[data-cms-step]'));

                if (multi && stepIndex >= 0 && stepIndex !== current) {
                    show(stepIndex, false);
                }

                (firstField.querySelector('input:not([type="hidden"]):not([disabled]), select, textarea') || firstField.querySelector('legend'))?.focus();
            }
        };

        /* The browser's own checks, on what is showing in one step. */
        const checkStep = (step) => {
            clearErrors(step);

            const errors = {};

            step.querySelectorAll('[data-cms-field]').forEach((field) => {
                if (field.hidden) {
                    return;
                }

                const key = field.dataset.cmsField;
                const inputs = [...field.querySelectorAll('input:not([type="hidden"]), select, textarea')].filter((input) => !input.disabled);

                if (field.hasAttribute('data-required')) {
                    const group = inputs.filter((input) => input.type === 'checkbox' || input.type === 'radio');

                    if (group.length > 0 && !group.some((input) => input.checked)) {
                        errors[key] = [field.dataset.type === 'consent' || field.dataset.type === 'checkbox' ? 'Please tick this box to go on.' : 'Please choose an answer.'];

                        return;
                    }
                }

                const invalid = inputs.find((input) => !input.checkValidity());

                if (invalid) {
                    errors[key] = [invalid.validity.valueMissing ? 'Please answer this question.' : invalid.validationMessage];
                }
            });

            return errors;
        };

        const post = async (extra = {}) => {
            const data = new FormData(form);

            Object.entries(extra).forEach(([name, value]) => data.set(name, value));

            return fetch(form.action, {
                method: 'POST',
                body: data,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
        };

        /* The server's checks for one step, so a bad email is caught before step three. */
        const checkOnServer = async (index) => {
            if (preview) {
                return {};
            }

            try {
                const response = await post({ _validate_step: String(index) });

                if (response.status === 422) {
                    return (await response.json()).errors ?? {};
                }
            } catch {
                /* Offline: the final send checks everything anyway. */
            }

            return {};
        };

        /* ---------- keeping progress ---------- */

        const storable = (input) => input.name
            && !input.name.startsWith('_')
            && input.type !== 'file'
            && input.type !== 'password'
            && !input.closest('.cms-form__trap')
            && !input.closest('[data-cms-signature]')
            && input.name !== 'cf-turnstile-response';

        let saveTimer = null;

        const save = () => {
            if (!root.hasAttribute('data-local-progress')) {
                return;
            }

            clearTimeout(saveTimer);
            saveTimer = setTimeout(() => {
                const values = {};

                form.querySelectorAll('input, select, textarea').forEach((input) => {
                    if (!storable(input)) {
                        return;
                    }

                    if (input.type === 'checkbox' || input.type === 'radio') {
                        values[`${input.name}::${input.value}`] = input.checked;
                    } else if (input.tagName === 'SELECT' && input.multiple) {
                        values[input.name] = [...input.selectedOptions].map((option) => option.value);
                    } else {
                        values[input.name] = input.value;
                    }
                });

                try {
                    localStorage.setItem(storageKey, JSON.stringify({ at: Date.now(), step: current, values }));
                } catch {
                    /* Private browsing, or storage full: nothing to keep. */
                }
            }, 300);
        };

        const forget = () => {
            try {
                localStorage.removeItem(storageKey);
            } catch {
                /* Nothing was kept. */
            }
        };

        const restore = () => {
            if (!root.hasAttribute('data-local-progress') || (summary && !summary.hidden) || form.querySelector('input[name="_resume"]')) {
                return;
            }

            let kept = null;

            try {
                kept = JSON.parse(localStorage.getItem(storageKey) || 'null');
            } catch {
                kept = null;
            }

            /* A month-old half-filled form is more confusing than helpful. */
            if (!kept || !kept.values || Date.now() - kept.at > 30 * 86400000) {
                return;
            }

            let changed = false;

            form.querySelectorAll('input, select, textarea').forEach((input) => {
                if (!storable(input)) {
                    return;
                }

                if (input.type === 'checkbox' || input.type === 'radio') {
                    const value = kept.values[`${input.name}::${input.value}`];

                    if (typeof value === 'boolean' && value !== input.checked) {
                        input.checked = value;
                        changed = true;
                    }
                } else if (input.tagName === 'SELECT' && input.multiple && Array.isArray(kept.values[input.name])) {
                    [...input.options].forEach((option) => {
                        option.selected = kept.values[input.name].includes(option.value);
                    });
                    changed = true;
                } else if (typeof kept.values[input.name] === 'string' && kept.values[input.name] !== input.value) {
                    input.value = kept.values[input.name];
                    changed = changed || input.value !== '';
                }
            });

            if (changed) {
                form.querySelector('[data-cms-restored]')?.removeAttribute('hidden');
                current = Math.min(Number(kept.step || 0), steps.length - 1);
            }
        };

        form.querySelector('[data-cms-restart]')?.addEventListener('click', () => {
            forget();
            form.reset();
            form.querySelector('[data-cms-restored]')?.setAttribute('hidden', '');
            clearErrors();
            applyLogic();
            show(steps.indexOf(visibleSteps()[0] ?? steps[0]));
        });

        /* ---------- special inputs ---------- */

        form.querySelectorAll('[data-cms-range]').forEach((range) => {
            const output = range.parentElement.querySelector('[data-cms-output]');

            range.addEventListener('input', () => {
                if (output) {
                    output.textContent = range.value;
                }
            });
        });

        form.querySelectorAll('[data-cms-signature]').forEach((box) => {
            const canvas = box.querySelector('[data-cms-signature-pad]');
            const value = box.querySelector('[data-cms-signature-value]');
            const typed = box.querySelector('[data-cms-signature-typed]');
            const clear = box.querySelector('[data-cms-signature-clear]');
            const context = canvas?.getContext?.('2d');

            if (!canvas || !context || !window.PointerEvent) {
                return;
            }

            /* Drawing works here, so the typed fallback goes, and its name with it. */
            typed?.remove();
            canvas.hidden = false;
            clear.hidden = false;
            context.lineWidth = 2.5;
            context.lineCap = 'round';
            context.strokeStyle = getComputedStyle(root).getPropertyValue('--cms-form-ink') || '#111827';

            let drawing = false;
            const point = (event) => {
                const box = canvas.getBoundingClientRect();

                return [(event.clientX - box.left) * (canvas.width / box.width), (event.clientY - box.top) * (canvas.height / box.height)];
            };

            canvas.addEventListener('pointerdown', (event) => {
                drawing = true;
                canvas.setPointerCapture(event.pointerId);
                context.beginPath();
                context.moveTo(...point(event));
                begin();
            });
            canvas.addEventListener('pointermove', (event) => {
                if (drawing) {
                    context.lineTo(...point(event));
                    context.stroke();
                }
            });
            canvas.addEventListener('pointerup', () => {
                drawing = false;
                value.value = canvas.toDataURL('image/png');
            });
            clear.addEventListener('click', () => {
                context.clearRect(0, 0, canvas.width, canvas.height);
                value.value = '';
            });
        });

        /* ---------- sending ---------- */

        const finish = (message, redirect) => {
            forget();

            if (redirect) {
                window.location.assign(redirect);

                return;
            }

            if (successBox) {
                successBox.textContent = message;
                successBox.hidden = false;
                form.remove();
                successBox.focus();
            }
        };

        form.addEventListener('submit', async (event) => {
            if (event.submitter?.hasAttribute('data-cms-save')) {
                event.preventDefault();
                await saveForLater();

                return;
            }

            event.preventDefault();

            if (preview) {
                return;
            }

            applyLogic();

            const local = multi ? checkStep(steps[current]) : steps.reduce((all, step) => ({ ...all, ...checkStep(step) }), {});

            if (Object.keys(local).length > 0) {
                showErrors(local);

                return;
            }

            submit.disabled = true;

            try {
                const response = await post();
                const body = await response.json().catch(() => ({}));

                if (response.status === 422) {
                    showErrors(body.errors ?? {});

                    return;
                }

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                finish(body.message ?? '', body.redirect ?? null);
            } catch {
                /* The script could not send it; the browser can. */
                form.noValidate = true;
                HTMLFormElement.prototype.submit.call(form);
            } finally {
                submit.disabled = false;
            }
        });

        const saveForLater = async () => {
            const status = form.querySelector('[data-cms-later-status]');
            const email = form.querySelector('input[name="_resume_email"]');

            if (!email || !email.value || !email.checkValidity()) {
                if (status) {
                    status.textContent = 'Please give an email address to send the link to.';
                }

                email?.focus();

                return;
            }

            try {
                const data = new FormData(form);

                data.set('_save', '1');

                const response = await fetch(root.dataset.save, {
                    method: 'POST',
                    body: data,
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                const body = await response.json().catch(() => ({}));

                if (status) {
                    status.textContent = response.ok ? (body.message ?? '') : (Object.values(body.errors ?? {})[0]?.[0] ?? 'That did not work. Please try again.');
                }
            } catch {
                if (status) {
                    status.textContent = 'That did not work. Please check your connection and try again.';
                }
            }
        };

        next?.addEventListener('click', async () => {
            applyLogic();

            const local = checkStep(steps[current]);

            if (Object.keys(local).length > 0) {
                showErrors(local);

                return;
            }

            next.disabled = true;
            const remote = await checkOnServer(current);
            next.disabled = false;

            if (Object.keys(remote).length > 0) {
                showErrors(remote);

                return;
            }

            const target = neighbour(1);

            if (target !== null) {
                show(target);
            }
        });

        back?.addEventListener('click', () => {
            const target = neighbour(-1);

            if (target !== null) {
                show(target);
            }
        });

        form.addEventListener('input', (event) => {
            begin();
            applyLogic();

            if (multi) {
                progress();
            }

            const field = event.target.closest('[data-cms-field]');

            if (field?.classList.contains('cms-bform__field--invalid')) {
                clearErrors(field);
            }

            save();
        });
        form.addEventListener('change', () => {
            applyLogic();
            save();
        });

        /* ---------- start ---------- */

        restore();
        applyLogic();

        if (multi) {
            form.querySelector('[data-cms-progress]')?.removeAttribute('hidden');

            if (steps[current]?.dataset.hiddenByLogic) {
                current = steps.indexOf(visibleSteps()[0] ?? steps[0]);
            }

            show(current, false);
        }

        if (summary && !summary.hidden) {
            summary.focus();
        }

        /* Inside an iframe on another site: tell the page how tall to be. */
        if (root.hasAttribute('data-embed') && window.parent !== window && 'ResizeObserver' in window) {
            new ResizeObserver(() => {
                window.parent.postMessage({ gadyaForm: root.dataset.slug, height: document.documentElement.scrollHeight }, '*');
            }).observe(document.body);
        }
    };

    const start = () => document.querySelectorAll(selector).forEach(wire);

    window.gadyaForms = { start };

    document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', start) : start();
})();
