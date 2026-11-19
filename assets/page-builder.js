(() => {
    'use strict';
    const config = window.CortAIPageBuilder;
    const root = document.querySelector('[data-page-builder]');
    if (!config || !root) return;
    const labels = config.strings;
    const find = (name) => root.querySelector(`[data-${name}]`);
    const form = find('pb-form');
    const fieldset = find('form-fields');
    const fields = form.elements;
    const input = (name) => fields.namedItem(name);
    const frame = find('preview-frame');
    let nonce = config.nonce;
    let availability = null;
    let analysis = null;
    let operation = null;
    let busy = true;
    let loop = null;
    let mediaIds = [];
    let requestId = null;
    let pageNumber = 1;
    let pageEpoch = 0;
    let historyEpoch = 0;
    const active = () => ['generating', 'applying', 'restoring'].includes(operation?.status);
    const wait = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));
    const number = new Intl.NumberFormat(document.documentElement.lang || 'en');
    const usd = new Intl.NumberFormat(document.documentElement.lang || 'en', {style: 'currency', currency: 'USD', maximumFractionDigits: 6});
    const node = (tag, text = '') => { const el = document.createElement(tag); el.textContent = text; return el; };
    const layoutName = (value) => ({default: 'Default (theme)', elementor_header_footer: 'Elementor Full Width', elementor_canvas: 'Elementor Canvas', auto: 'Automatic', preserve: 'Keep existing layout'}[value] || 'Default (theme)');

    function error(message = '') { find('error').hidden = !message; find('error').textContent = message; }
    function endpoint(path) {
        const url = new URL(config.root, location.href);
        const [part, query] = path.split('?');
        if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/$/, '') + '/' + part);
        else url.pathname = url.pathname.replace(/\/$/, '') + '/' + part;
        if (query) for (const [key, value] of new URLSearchParams(query)) url.searchParams.set(key, value);
        return url;
    }
    async function request(path, method = 'GET', body) {
        let response;
        try {
            response = await fetch(endpoint(path), {method, credentials: 'same-origin', cache: 'no-store', headers: {'X-WP-Nonce': nonce, 'Accept': 'application/json', ...(body === undefined ? {} : {'Content-Type': 'application/json'})}, ...(body === undefined ? {} : {body: JSON.stringify(body)})});
        } catch { const err = new Error(labels.network); err.retryable = true; throw err; }
        nonce = response.headers.get('X-WP-Nonce') || nonce;
        const data = await response.json().catch(() => null);
        if (!response.ok || !data) {
            const err = new Error(['rest_cookie_invalid_nonce', 'cort_ai_pb_nonce'].includes(data?.code) ? labels.session : data?.message || labels.network);
            err.retryable = response.status >= 500 || (response.ok && !data);
            throw err;
        }
        return data;
    }
    function safeLink(el, value) {
        try { const url = new URL(value, location.href); if (!['https:', 'http:'].includes(url.protocol) || url.origin !== location.origin) return false; el.href = url.href; return true; } catch { return false; }
    }
    function lock() {
        fieldset.disabled = busy || !availability?.supported || active() || operation?.status === 'preview';
        find('generate').disabled = fieldset.disabled || (Number(input('page_id').value) > 0 && (!analysis?.editable || analysis.id !== Number(input('page_id').value)));
        find('apply').disabled = busy || !operation?.reviewed || !find('approval').checked;
        find('restore').disabled = busy || !find('restore-approval').checked;
        for (const name of ['discard', 'resume', 'refresh', 'restore', 'load-preview']) {
            if (name !== 'restore') find(name).disabled = busy || (name === 'resume' && Boolean(loop));
        }
    }
    function adapt() {
        const fresh = Number(input('page_id').value) === 0;
        for (const option of input('operation').options) option.disabled = option.value === 'redesign' || ((option.value === 'create') !== fresh);
        if (fresh) input('operation').value = 'create';
        else if (input('operation').value === 'create') input('operation').value = 'add';
        const op = input('operation').value;
        for (const el of root.querySelectorAll('[data-new-page]')) el.hidden = !fresh;
        input('title').required = fresh;
        find('section-field').hidden = fresh || op === 'add';
        find('position-field').hidden = !['add', 'reorder'].includes(op);
        find('anchor-field').hidden = find('position-field').hidden || !['before', 'after'].includes(input('position').value);
        input('instructions').required = !['remove', 'reorder'].includes(op);
        find('scope-note').textContent = ['remove', 'reorder'].includes(op) ? 'The selected section and position define the change. Instructions are optional; no OpenAI text request is made.' : ['edit', 'content'].includes(op) ? 'Only supported texts, links, buttons and images inside the selected section can change. Layout and other settings are preserved.' : 'Generates native sections, columns/containers and widgets. Advanced redesign and Atomic editing are not supported.';
        lock();
    }

    async function pages() {
        const epoch = ++pageEpoch;
        const selected = input('page_id').value;
        const result = await request(`pages?search=${encodeURIComponent(find('page-search').value)}&page=${pageNumber}`);
        if (epoch !== pageEpoch) return;
        input('page_id').replaceChildren(new Option(labels.new_page, '0'), ...result.pages.map((page) => new Option(`${page.title || '(Untitled)'} · ${page.status}`, String(page.id))));
        if ([...input('page_id').options].some((option) => option.value === selected)) input('page_id').value = selected;
        else { analysis = null; find('analysis').hidden = true; }
        find('pages-prev').disabled = pageNumber <= 1;
        find('pages-next').disabled = pageNumber >= result.total_pages;
        find('page-number').textContent = `${pageNumber} / ${Math.max(1, result.total_pages)}`;
        adapt();
    }
    function tree(nodes) {
        const list = node('ul');
        for (const item of nodes) {
            const li = node('li');
            const details = node('details');
            details.append(node('summary', `${item.label} · ${item.type} · ${item.id}`));
            if (item.text) details.append(node('p', item.text));
            if (Object.keys(item.settings).length) details.append(node('pre', JSON.stringify(item.settings, null, 2)));
            if (item.children.length) details.append(tree(item.children));
            li.append(details); list.append(li);
        }
        return list;
    }
    input('page_id').addEventListener('change', async () => {
        const id = Number(input('page_id').value);
        analysis = null; find('analysis').hidden = !id; adapt();
        if (!id) return;
        busy = true; lock(); error();
        try {
            const result = await request('pages/' + id);
            if (Number(input('page_id').value) !== id) return;
            analysis = result;
            find('page-info').textContent = `${result.title} · ${result.status} · ${result.layout} · ${layoutName(result.document_layout)}`;
            find('page-issues').textContent = result.issues.join(' ');
            find('tree').replaceChildren(tree(result.tree));
            const options = result.tree.map((item) => new Option(`${item.label} · ${item.id}`, item.id));
            input('section_id').replaceChildren(new Option(labels.select_section, ''), ...options);
            input('anchor_id').replaceChildren(new Option(labels.select_section, ''), ...result.tree.map((item) => new Option(`${item.label} · ${item.id}`, item.id)));
            if (!result.editable) error(result.issues.join(' ') || labels.read_only);
        } catch (err) { error(err.message); }
        finally { busy = false; adapt(); }
    });
    form.addEventListener('change', adapt);
    find('search').addEventListener('click', () => { pageNumber = 1; pages().catch((err) => error(err.message)); });
    find('page-search').addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); find('search').click(); } });
    for (const [name, delta] of [['pages-prev', -1], ['pages-next', 1]]) find(name).addEventListener('click', () => { pageNumber += delta; pages().catch((err) => error(err.message)); });
    input('model').addEventListener('change', () => { const value = input('effort').value; const choices = config.efforts[input('model').value]; input('effort').replaceChildren(...Object.entries(choices).map(([id, label]) => new Option(label, id))); input('effort').value = choices[value] ? value : 'medium'; });
    find('media').addEventListener('click', () => {
        if (!window.wp?.media) return;
        const picker = wp.media({title: labels.media_title, button: {text: labels.media_button}, library: {type: 'image'}, multiple: true});
        picker.on('select', () => {
            const items = picker.state().get('selection').toJSON().slice(0, 20);
            mediaIds = items.map((item) => item.id);
            find('media-list').replaceChildren(...items.map((item) => node('li', `media_${item.id} · ${item.title} · ${item.alt || ''}`)));
        });
        picker.open();
    });

    function draw() {
        if (!operation) { lock(); return; }
        find('result').hidden = false;
        find('stage').textContent = labels[operation.status] || operation.status;
        find('summary').textContent = operation.pause || operation.summary || operation.stage_label || operation.stage;
        const designReport = find('design-report');
        designReport.replaceChildren();
        designReport.hidden = !operation.design_summary && !operation.design_quality;
        if (operation.design_summary) {
            designReport.append(node('h3', 'Design direction'), node('p', operation.design_summary.art_direction));
            const palette = node('div'); palette.className = 'cort-pb-palette';
            for (const [name, color] of Object.entries(operation.design_summary.palette)) { const swatch = node('span', `${name} ${color}`); swatch.title = `${name}: ${color}`; if (/^#[A-Fa-f0-9]{6}$/.test(color)) swatch.style.borderLeftColor = color; palette.append(swatch); }
            designReport.append(palette, node('p', operation.design_summary.responsive_strategy), node('p', `Page layout: ${layoutName(operation.document_layout)}`));
        }
        if (operation.design_quality) {
            designReport.append(node('h3', 'Design checks'));
            const list = node('ul');
            const names = {heading_hierarchy: 'Heading hierarchy', unique_anchors: 'Unique section links', responsive_elements: 'Responsive layout elements', styled_components: 'Styled components', layout_variants: 'Section compositions', distinct_images: 'Distinct images', duplicate_images: 'Extra image repetitions', placeholders: 'Images to replace', minimum_solid_text_contrast: 'Minimum text contrast on solid backgrounds'};
            for (const [key, value] of Object.entries(operation.design_quality.checks || {})) list.append(node('li', `${names[key] || key.replaceAll('_', ' ')}: ${value}`));
            designReport.append(list, node('p', operation.design_quality.validation_scope));
        }
        find('resume').hidden = !active() || Boolean(loop);
        find('discard').hidden = ['applied', 'restored', 'cancelled', 'applying', 'restoring'].includes(operation.status);
        find('warnings').replaceChildren(...operation.warnings.map((warning) => node('li', warning)));
        if (operation.usage.usage_incomplete) find('warnings').append(node('li', labels.partial_usage));
        find('warnings').hidden = !find('warnings').children.length;
        find('usage').replaceChildren(...[['Input tokens', number.format(operation.usage.input_tokens)], ['Output tokens', number.format(operation.usage.output_tokens)], ['Images', number.format(operation.usage.images)], ['Estimated cost', usd.format(operation.usage.estimated_usd)]].map(([label, value]) => { const div = node('div'); div.append(node('dt', label), node('dd', value)); return div; }));
        find('changes').replaceChildren(...operation.changes.map((change) => { const li = node('li', `${change.action} · ${change.element} · ${change.field || change.label || ''}`); if (change.before !== undefined) { li.append(node('pre', `Before: ${change.before}`), node('pre', `After: ${change.after}`)); } return li; }));
        const preview = operation.status === 'preview';
        find('preview-panel').hidden = !preview;
        if (preview) {
            safeLink(find('preview-link'), operation.preview_url);
            find('approval').disabled = !operation.reviewed;
            find('review-message').textContent = operation.reviewed ? labels.review_ready : labels.review_pending;
        }
        find('edit').hidden = !operation.elementor_url || !safeLink(find('edit'), operation.elementor_url);
        find('restore-panel').hidden = !operation.can_restore;
        find('backup').textContent = operation.backup_id ? `Backup: ${operation.backup_id}` : '';
        lock();
    }
    async function drive() {
        let retries = 0;
        while (active()) {
            await wait(operation.retry_ms || 1000);
            try { operation = await request(`operations/${operation.id}/advance`, 'POST'); retries = 0; draw(); if (operation.pause) break; }
            catch (err) { if (!err.retryable || retries++ >= 3) { error(err.message); break; } await wait(retries * 1800); }
        }
    }
    function begin() {
        if (loop || !active()) return;
        error(); loop = drive().finally(() => { loop = null; draw(); refreshHistory().catch(() => {}); }); draw();
    }
    async function refreshHistory() {
        const epoch = ++historyEpoch;
        const status = await request('status');
        if (epoch !== historyEpoch) return;
        availability = status.elementor;
        const providers = availability.providers || {};
        const providerText = [providers.pro_elements?.detected ? `PRO Elements ${providers.pro_elements.version}` : '', providers.elementor_pro?.detected ? `Elementor Pro ${providers.elementor_pro.version}` : ''].filter(Boolean);
        find('availability').textContent = [availability.message, availability.version ? `Elementor ${availability.version}` : '', ...providerText].filter(Boolean).join(' · ');
        const catalog = availability.widget_catalog || [];
        const compatible = catalog.filter((widget) => widget.authoring);
        const facts = [['Elementor Version', availability.version || 'Not detected'], ['PRO Elements', providers.pro_elements?.detected ? providers.pro_elements.version || 'Detected' : 'Not detected'], ['Elementor Pro', providers.elementor_pro?.detected ? providers.elementor_pro.version || 'Detected' : 'Not detected'], ['Classic / Flexbox Support', `${availability.classic ? 'Classic supported' : 'Classic unavailable'} · ${availability.containers ? 'Flexbox supported' : 'Flexbox unavailable'}`], ['Available Widgets', `${compatible.length} for generation · ${catalog.filter((widget) => widget.registered && widget.classic).length} registered classic widgets`], ['Atomic V4', 'Excluded from generation and editing']];
        find('compatibility-facts').replaceChildren(...facts.flatMap(([label, value]) => [node('dt', label), node('dd', value)]));
        find('compatible-widgets').replaceChildren(...compatible.map((widget) => node('li', `${widget.title} (${widget.name})`)));
        find('unsupported-features').replaceChildren(...[...(availability.unsupported_features || []), ...compatible.filter((widget) => widget.limitations).map((widget) => `${widget.title}: ${widget.limitations}`)].map((text) => node('li', text)));
        find('other-widgets').replaceChildren(...catalog.filter((widget) => !widget.authoring).map((widget) => node('li', `${widget.title} (${widget.name}): ${widget.reason}${widget.fallback ? ` Alternative: ${widget.fallback}.` : ''}`)));
        find('compatibility-panel').hidden = false;
        find('history').replaceChildren(...status.history.map((item) => {
            const tr = node('tr');
            for (const [main, small] of [[item.title, item.created_at + ' UTC'], [item.operation, item.status], [item.model, `${item.usage.input_tokens} / ${item.usage.output_tokens}`], [String(item.usage.images), usd.format(item.usage.estimated_usd)]]) { const td = node('td', main); td.append(node('small', small)); tr.append(td); }
            const td = node('td', item.backup_id); const button = node('button', 'Review'); button.type = 'button'; button.className = 'button'; button.addEventListener('click', () => { if (busy || loop) return; operation = item; find('approval').checked = false; find('restore-approval').checked = false; frame.removeAttribute('src'); find('preview-scroll').hidden = true; draw(); find('result').scrollIntoView({block: 'start'}); }); td.append(button); tr.append(td); return tr;
        }));
        return status;
    }
    form.addEventListener('submit', async (event) => {
        event.preventDefault(); if (find('generate').disabled) return;
        busy = true; error(); lock();
        try {
            requestId ||= typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) => (Number(c) ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> Number(c) / 4).toString(16));
            // FormData excludes controls while the parent fieldset is disabled; read values explicitly.
            const data = Object.fromEntries(['page_id', 'operation', 'title', 'slug', 'section_id', 'anchor_id', 'position', 'instructions', 'model', 'effort', 'quality', 'size', 'image_count', 'document_layout'].map((name) => [name, input(name).value]));
            operation = await request('operations', 'POST', {...data, reuse_media: input('reuse_media').checked, allow_image_reuse: input('allow_image_reuse').checked, request_id: requestId, media_ids: mediaIds});
            requestId = null; find('approval').checked = false; find('restore-approval').checked = false;
            frame.removeAttribute('src'); find('preview-scroll').hidden = true; draw(); begin();
            find('result').scrollIntoView({block: 'start'});
        } catch (err) { error(err.message); if (!err.retryable) requestId = null; }
        finally { busy = false; draw(); }
    });
    find('resume').addEventListener('click', begin);
    find('refresh').addEventListener('click', async () => { if (!operation) return; busy = true; lock(); try { operation = await request('operations/' + operation.id); await refreshHistory(); } catch (err) { error(err.message); } finally { busy = false; draw(); } });
    find('load-preview').addEventListener('click', () => { if (!operation?.preview_url) return; const link = node('a'); if (!safeLink(link, operation.preview_url)) { error(labels.network); return; } frame.src = link.href; find('preview-scroll').hidden = false; });
    find('device').addEventListener('change', () => { frame.width = find('device').value; });
    window.addEventListener('message', async (event) => {
        if (event.origin !== location.origin || event.source !== frame.contentWindow || event.data?.type !== 'cort-ai-pb-preview' || event.data.id !== operation?.id || event.data.hash !== operation?.preview_hash) return;
        try { operation = await request('operations/' + operation.id); draw(); } catch (err) { error(err.message); }
    });
    find('approval').addEventListener('change', lock);
    find('restore-approval').addEventListener('change', lock);
    for (const [button, action] of [['apply', 'approve'], ['restore', 'restore'], ['discard', 'discard']]) find(button).addEventListener('click', async () => {
        if (busy || !operation) return;
        busy = true; error(); lock();
        try {
            operation = await request(`operations/${operation.id}/${action}`, 'POST', action === 'discard' ? undefined : {approved: true, preview_hash: operation.preview_hash});
            find('approval').checked = false; find('restore-approval').checked = false; await refreshHistory();
        } catch (err) { error(err.message); }
        finally { busy = false; draw(); }
    });
    refreshHistory().then(async (state) => { operation = state.current; if (availability.available) await pages(); draw(); }).catch((err) => error(err.message)).finally(() => { busy = false; adapt(); draw(); });
})();
