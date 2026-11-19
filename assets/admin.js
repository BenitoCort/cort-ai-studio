(() => {
    'use strict';

    const config = window.CortAIStudioAdmin;
    if (!config) {
        return;
    }
    const labels = config.strings;
    let nonce = config.nonce;

    function endpoint(path) {
        const url = new URL(config.root, window.location.href);
        if (url.searchParams.has('rest_route')) {
            const route = url.searchParams.get('rest_route').replace(/\/$/, '');
            url.searchParams.set('rest_route', route + '/' + path);
        } else {
            url.pathname = url.pathname.replace(/\/$/, '') + '/' + path;
        }
        return url;
    }

    async function request(path, method = 'GET', payload) {
        const options = {
            method,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'Accept': 'application/json', 'X-WP-Nonce': nonce},
        };
        if (payload !== undefined) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(payload);
        }

        let response;
        try {
            response = await fetch(endpoint(path), options);
        } catch {
            const error = new Error(labels.network);
            error.retryable = true;
            throw error;
        }
        nonce = response.headers.get('X-WP-Nonce') || nonce;
        const result = await response.json().catch(() => null);
        if (!response.ok || !result) {
            const expired = ['rest_cookie_invalid_nonce', 'cort_ai_nonce'].includes(result?.code);
            const error = new Error(expired ? labels.session : result?.message || labels.network);
            error.retryable = response.status >= 500 || (response.ok && !result);
            error.code = result?.code;
            throw error;
        }
        return result;
    }

    const model = document.getElementById('cort-model');
    const effort = document.getElementById('cort-effort');
    model?.addEventListener('change', () => {
        const previous = effort.value;
        const levels = config.efforts[model.value];
        effort.replaceChildren(...Object.entries(levels).map(([value, label]) => new Option(label, value)));
        effort.value = levels[previous] ? previous : 'medium';
    });

    const settings = document.querySelector('[data-cort-ai-settings]');
    if (settings) {
        const button = settings.querySelector('[data-test]');
        const status = settings.querySelector('[data-connection]');
        button.addEventListener('click', async () => {
            button.disabled = true;
            status.dataset.state = 'ready';
            status.textContent = labels.testing;
            try {
                const result = await request('connection', 'POST');
                status.dataset.state = 'ok';
                status.textContent = result.message;
            } catch (error) {
                status.dataset.state = 'error';
                status.textContent = error.message;
            } finally {
                button.disabled = false;
            }
        });
    }

    const writer = document.querySelector('[data-cort-ai-writer]');
    if (!writer) {
        return;
    }

    const form = writer.querySelector('[data-writer-form]');
    const fields = form.elements;
    const generate = form.querySelector('[data-generate]');
    const run = writer.querySelector('[data-run]');
    const stage = run.querySelector('[data-stage]');
    const spinner = run.querySelector('[data-spinner]');
    const resume = run.querySelector('[data-resume]');
    const stop = run.querySelector('[data-stop]');
    const runMessage = run.querySelector('[data-run-message]');
    const errorNotice = run.querySelector('[data-error]');
    const resultPanel = run.querySelector('[data-result]');
    let job = null;
    let requestId = null;
    let submitting = true;
    let stopping = false;
    let loop = null;

    const number = new Intl.NumberFormat(document.documentElement.lang || 'en');
    const usd = new Intl.NumberFormat(document.documentElement.lang || 'en', {
        style: 'currency', currency: 'USD', minimumFractionDigits: 4, maximumFractionDigits: 6,
    });
    const wait = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));

    function updateFields() {
        const custom = fields.namedItem('length').value === 'custom';
        const content = fields.namedItem('content_images').checked;
        const imagesEnabled = fields.namedItem('featured').checked || (content && Number(fields.namedItem('image_count').value) > 0);
        form.querySelector('[data-custom-field]').hidden = !custom;
        fields.namedItem('custom_words').required = custom;
        form.querySelector('[data-count-field]').hidden = !content;
        form.querySelector('[data-image-fields]').hidden = !imagesEnabled;
    }

    form.addEventListener('change', updateFields);
    updateFields();

    function make(tag, value = '', className = '') {
        const node = document.createElement(tag);
        node.textContent = value;
        if (className) {
            node.className = className;
        }
        return node;
    }

    function newRequestId() {
        const bytes = crypto.getRandomValues(new Uint8Array(16));
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
        return [hex.slice(0, 8), hex.slice(8, 12), hex.slice(12, 16), hex.slice(16, 20), hex.slice(20)].join('-');
    }

    function choices() {
        const data = Object.fromEntries(new FormData(form));
        for (const option of ['seo', 'internal', 'external', 'featured', 'content_images']) {
            data[option] = Object.hasOwn(data, option);
        }
        return {...data, request_id: requestId};
    }

    function lockForm() {
        const active = job?.status === 'running';
        generate.disabled = submitting || stopping || active || form.dataset.configured !== 'yes';
        resume.hidden = !active || Boolean(loop) || stopping;
        stop.hidden = !active || !job.can_stop;
        stop.disabled = stopping || (!loop && Boolean(job?.busy));
        if (active) {
            stop.textContent = job.draft_saved ? labels.skip_images : labels.cancel;
        }
    }

    function showError(message) {
        run.hidden = false;
        errorNotice.hidden = !message;
        errorNotice.textContent = message;
    }

    function setMessage(value) {
        runMessage.hidden = !value;
        runMessage.textContent = value;
    }

    function postLink(url, label, primary) {
        const link = make('a', label, primary ? 'button button-primary' : 'button');
        const parsed = new URL(url, window.location.href);
        if (!['http:', 'https:'].includes(parsed.protocol)) {
            return null;
        }
        link.href = parsed.href;
        if (!primary) {
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
        }
        return link;
    }

    function addDetails(items) {
        const list = make('dl', '', 'cort-ai-result-details');
        items.forEach(([label, value]) => {
            const group = make('div');
            group.append(make('dt', label), make('dd', value));
            list.append(group);
        });
        resultPanel.append(list);
    }

    function drawResult() {
        resultPanel.replaceChildren();
        if (job.post) {
            resultPanel.append(make('h3', job.post.title), make('p', labels.draft_with_status + ' · ' + job.post.status));
            const links = make('div', '', 'cort-ai-actions cort-ai-post-links');
            if (job.post.edit_url) {
                const edit = postLink(job.post.edit_url, labels.edit, true);
                if (edit) links.append(edit);
            }
            if (job.post.preview_url) {
                const preview = postLink(job.post.preview_url, labels.preview, false);
                if (preview) links.append(preview);
            }
            resultPanel.append(links);
        } else if (job.draft_saved) {
            resultPanel.append(make('p', labels.draft_unavailable));
        }

        const usage = job.usage;
        addDetails([
            [labels.tokens_in, number.format(usage.input_tokens)],
            [labels.tokens_out, number.format(usage.output_tokens)],
            [labels.images, number.format(usage.images)],
            [labels.web, number.format(usage.web_calls)],
            [labels.cost, usd.format(usage.estimated_usd)],
        ]);

        if (usage.usage_incomplete) {
            resultPanel.append(make('p', labels.usage_partial, 'description'));
        }
        if (job.warnings.length) {
            const list = make('ul', '', 'cort-ai-warnings');
            job.warnings.forEach((warning) => list.append(make('li', warning)));
            resultPanel.append(list);
        }

        if (job.post) {
            const editorial = [];
            if (job.seo?.title) editorial.push([labels.seo_title, job.seo.title]);
            if (job.seo?.description) editorial.push([labels.seo_description, job.seo.description]);
            if (job.categories.length) editorial.push([labels.category_suggestions, job.categories.join(', ')]);
            if (job.tags.length) editorial.push([labels.tag_suggestions, job.tags.join(', ')]);
            if (editorial.length) addDetails(editorial);
        }
        resultPanel.hidden = false;
    }

    function draw() {
        lockForm();
        if (!job) {
            return;
        }
        run.hidden = false;
        const active = job.status === 'running';
        spinner.hidden = !active || !loop;
        stage.textContent = active ? job.stage : (labels[job.status] || labels.failed);
        if (active) {
            resultPanel.hidden = true;
            setMessage(job.pause || (job.draft_saved ? labels.draft_saved : '') || (!loop ? labels.resume_available : ''));
        } else {
            setMessage('');
            drawResult();
        }
    }

    async function drive() {
        let retries = 0;
        while (!stopping && job?.status === 'running') {
            await wait(job.retry_ms || 150);
            if (stopping) {
                break;
            }
            try {
                job = await request('jobs/' + job.id + '/advance', 'POST');
                retries = 0;
                showError('');
                draw();
                if (job.pause) {
                    break;
                }
            } catch (error) {
                if (!error.retryable || retries >= 3) {
                    showError(error.message);
                    break;
                }
                setMessage(labels.retrying);
                retries++;
                await wait(retries * 2300);
            }
        }
    }

    function begin() {
        if (loop || !job || job.status !== 'running') {
            return;
        }
        showError('');
        loop = drive().finally(() => {
            loop = null;
            draw();
        });
        draw();
    }

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (generate.disabled) {
            return;
        }
        submitting = true;
        lockForm();
        run.hidden = false;
        resultPanel.hidden = true;
        showError('');
        setMessage('');
        spinner.hidden = false;
        stage.textContent = labels.request;
        run.scrollIntoView({block: 'nearest'});
        try {
            requestId ||= newRequestId();
            job = await request('jobs', 'POST', choices());
            requestId = null;
            draw();
            if (!job.pause) begin();
        } catch (error) {
            spinner.hidden = true;
            stage.textContent = labels.failed;
            showError(error.message);
            if (!error.retryable) requestId = null;
        } finally {
            submitting = false;
            lockForm();
        }
    });

    resume.addEventListener('click', begin);
    stop.addEventListener('click', async () => {
        stopping = true;
        lockForm();
        setMessage(labels.stop_wait);
        try {
            if (loop) await loop;
            job = await request('jobs/' + job.id + '/stop', 'POST');
            showError('');
        } catch (error) {
            showError(error.message);
        } finally {
            stopping = false;
            draw();
        }
    });

    lockForm();
    request('jobs/current').then((current) => {
        job = current.job;
        draw();
    }).catch((error) => {
        stage.textContent = labels.failed;
        showError(error.message);
    }).finally(() => {
        submitting = false;
        lockForm();
    });
})();
