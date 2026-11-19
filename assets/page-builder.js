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
    let status = null;
    let statusError = '';
    let analysis = null;
    let analysisError = '';
    let operation = null;
    let current = null;
    let busy = false;
    let loop = null;
    let mediaIds = [];
    let requestId = null;
    let pageNumber = 1;
    let pageEpoch = 0;
    let analysisEpoch = 0;
    let historyEpoch = 0;
    const active = (row) => ['generating', 'applying', 'restoring'].includes(row?.status);
    const pending = (row) => active(row) || row?.status === 'preview';
    const unfinished = () => current || (pending(operation) ? operation : null);
    const needsAI = (op) => !['remove', 'reorder'].includes(op);
    const efforts = () => config.efforts[input('model').value] || {};
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
        let data;
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), method === 'GET' ? 20000 : 120000);
        try {
            response = await fetch(endpoint(path), {
                method, credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: {'X-WP-Nonce': nonce, 'Accept': 'application/json', ...(body === undefined ? {} : {'Content-Type': 'application/json'})},
                ...(body === undefined ? {} : {body: JSON.stringify(body)}),
            });
            data = await response.json().catch((err) => {
                if (controller.signal.aborted) throw err;
                return null;
            });
        } catch {
            const err = new Error(controller.signal.aborted ? labels.timeout : labels.network);
            err.retryable = true;
            throw err;
        } finally {
            window.clearTimeout(timeout);
        }
        nonce = response.headers.get('X-WP-Nonce') || nonce;
        if (!response.ok || !data) {
            const expired = ['rest_cookie_invalid_nonce', 'cort_ai_pb_nonce'].includes(data?.code);
            const message = typeof data?.message === 'string' && data.message ? data.message : labels.http_error.replace('%s', response.status);
            const err = new Error(expired ? labels.session : message);
            err.code = data?.code;
            err.retryable = response.status >= 500 || (response.ok && !data);
            throw err;
        }
        return data;
    }
    function safeLink(el, value) {
        if (typeof value !== 'string' || !value) return false;
        try { const url = new URL(value, location.href); if (!['https:', 'http:'].includes(url.protocol) || url.origin !== location.origin) return false; el.href = url.href; return true; } catch { return false; }
    }
    function generationBlocker() {
        if (busy) return labels.working;
        if (!status) return statusError || labels.loading;
        if (!status.elementor.supported) return status.elementor.message;
        const proposal = unfinished();
        if (proposal?.status === 'preview') return labels.pending_review;
        if (proposal?.status === 'generating') return labels.pending_generation;
        if (proposal) return labels.pending_write;

        const id = Number(input('page_id').value);
        const option = input('operation').selectedOptions[0];
        if (!option || option.disabled) return labels.operation_required;
        const op = option.value;
        if (id) {
            if (analysis?.id !== id) return analysisError || labels.inspecting;
            if (!analysis.editable) return analysis.issues.join(' ') || labels.read_only;
        } else if (!status.can_create) return labels.create_permission;

        if (['create', 'add'].includes(op) && !status.elementor.widget_catalog.some((widget) => widget.authoring)) return labels.widgets_required;
        if (needsAI(op)) {
            if (!status.api_key_configured) return labels.key_required;
            const levels = Object.keys(efforts());
            if (!levels.length) return labels.model_required;
            if (!levels.includes(input('effort').value)) return labels.effort_required;
        }
        if (input('title').required && !input('title').value.trim()) return labels.title_required;
        if (input('instructions').required && !input('instructions').value.trim()) return labels.instructions_required;
        if (input('section_id').required && !input('section_id').value) return labels.section_required;
        if (input('anchor_id').required && (!input('anchor_id').value || (op === 'reorder' && input('anchor_id').value === input('section_id').value))) return labels.anchor_required;
        return '';
    }

    function lock() {
        fieldset.disabled = busy;
        const blocker = generationBlocker();
        find('generate').disabled = Boolean(blocker);
        const note = find('generation-status');
        if (note.textContent !== blocker) note.textContent = blocker;
        note.hidden = !blocker;
        find('retry-status').hidden = !(statusError || (status && !status.api_key_configured && needsAI(input('operation').value)));
        find('current-proposal').hidden = !unfinished();
        find('apply').disabled = busy || operation?.status !== 'preview' || !operation.reviewed || !find('approval').checked;
        find('restore').disabled = busy || !operation?.can_restore || !find('restore-approval').checked;
        for (const name of ['discard', 'resume', 'refresh', 'load-preview']) find(name).disabled = busy || (name === 'resume' && Boolean(loop));
    }
    function adapt() {
        const fresh = Number(input('page_id').value) === 0;
        for (const option of input('operation').options) option.disabled = option.value === 'redesign' || ((option.value === 'create') !== fresh);
        if (fresh) input('operation').value = 'create';
        else if (!input('operation').selectedOptions[0] || input('operation').value === 'create') input('operation').value = 'add';
        const op = input('operation').value;
        for (const el of root.querySelectorAll('[data-new-page]')) el.hidden = !fresh;
        input('title').required = fresh;
        find('section-field').hidden = fresh || op === 'add';
        input('section_id').required = !find('section-field').hidden;
        find('position-field').hidden = !['add', 'reorder'].includes(op);
        find('anchor-field').hidden = find('position-field').hidden || !['before', 'after'].includes(input('position').value);
        input('anchor_id').required = !find('anchor-field').hidden;
        input('instructions').required = needsAI(op);
        find('scope-note').textContent = ['remove', 'reorder'].includes(op) ? 'The selected section and position define the change. Instructions are optional; no OpenAI text request is made.' : ['edit', 'content'].includes(op) ? 'Only supported texts, links, buttons and images inside the selected section can change. Layout and other settings are preserved.' : 'Generates native sections, columns/containers and widgets. Advanced redesign and Atomic editing are not supported.';
        lock();
    }

    async function loadPages(page) {
        const epoch = ++pageEpoch;
        find('page-number').textContent = labels.loading_pages;
        try {
            const result = await request(`pages?search=${encodeURIComponent(find('page-search').value)}&page=${page}`);
            if (epoch !== pageEpoch) return;
            if (!Array.isArray(result.pages) || !Number.isInteger(result.total_pages)) throw new Error(labels.http_error.replace('%s', 200));
            const selected = input('page_id').value;
            input('page_id').replaceChildren(new Option(labels.new_page, '0'), ...result.pages.map((item) => new Option(`${item.title || '(Untitled)'} · ${item.status}`, String(item.id))));
            if ([...input('page_id').options].some((option) => option.value === selected)) input('page_id').value = selected;
            else inspectSelection();
            pageNumber = page;
            find('pages-prev').disabled = pageNumber <= 1;
            find('pages-next').disabled = pageNumber >= result.total_pages;
            find('page-number').textContent = `${pageNumber} / ${Math.max(1, result.total_pages)}`;
        } catch (err) {
            if (epoch === pageEpoch) find('page-number').textContent = labels.pages_failed.replace('%s', err.message);
        }
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
    async function inspectSelection() {
        const epoch = ++analysisEpoch;
        const id = Number(input('page_id').value);
        analysis = null;
        analysisError = '';
        find('analysis').hidden = !id;
        find('page-info').textContent = id ? labels.inspecting : '';
        find('page-issues').textContent = '';
        find('tree').replaceChildren();
        input('section_id').replaceChildren();
        input('anchor_id').replaceChildren();
        adapt();
        if (!id) return;
        try {
            const result = await request('pages/' + id);
            if (epoch !== analysisEpoch) return;
            analysis = result;
            find('page-info').textContent = `${result.title} · ${result.status} · ${result.layout} · ${layoutName(result.document_layout)}`;
            find('page-issues').textContent = result.issues.join(' ');
            find('tree').replaceChildren(tree(result.tree));
            const options = result.tree.map((item) => new Option(`${item.label} · ${item.id}`, item.id));
            input('section_id').replaceChildren(new Option(labels.select_section, ''), ...options);
            input('anchor_id').replaceChildren(new Option(labels.select_section, ''), ...result.tree.map((item) => new Option(`${item.label} · ${item.id}`, item.id)));
        } catch (err) {
            if (epoch !== analysisEpoch) return;
            analysis = null;
            analysisError = err.message;
            find('page-info').textContent = '';
            find('page-issues').textContent = analysisError;
        } finally {
            if (epoch === analysisEpoch) adapt();
        }
    }
    input('page_id').addEventListener('change', inspectSelection);
    form.addEventListener('input', lock);
    form.addEventListener('change', adapt);
    find('search').addEventListener('click', () => loadPages(1));
    find('page-search').addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); find('search').click(); } });
    for (const [name, delta] of [['pages-prev', -1], ['pages-next', 1]]) find(name).addEventListener('click', () => loadPages(pageNumber + delta));
    input('model').addEventListener('change', () => {
        const value = input('effort').value;
        const choices = efforts();
        const levels = Object.keys(choices);
        input('effort').replaceChildren(...levels.map((id) => new Option(choices[id], id)));
        input('effort').value = levels.includes(value) ? value : (levels.includes('medium') ? 'medium' : (levels[0] || ''));
    });
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

    function updateOperation(row) {
        operation = row;
        if (current?.id === row.id) current = pending(row) ? row : null;
    }

    function openOperation(row) {
        if (operation?.id !== row.id) {
            find('approval').checked = false;
            find('restore-approval').checked = false;
            frame.removeAttribute('src');
            find('preview-scroll').hidden = true;
        }
        updateOperation(row);
        draw();
    }

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
        find('resume').hidden = !active(operation) || Boolean(loop);
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
        while (active(operation)) {
            const id = operation.id;
            await wait(operation.retry_ms || 1000);
            if (operation?.id !== id || !active(operation)) break;
            if (busy) continue;
            try {
                const row = await request(`operations/${id}/advance`, 'POST');
                if (operation?.id !== id || !active(operation)) break;
                updateOperation(row);
                retries = 0;
                draw();
                if (operation.pause) break;
            }
            catch (err) { if (!err.retryable || retries++ >= 3) { error(err.message); break; } await wait(retries * 1800); }
        }
    }
    function begin() {
        if (loop || !active(operation)) return;
        error(); loop = drive().finally(() => { loop = null; draw(); refreshHistory().catch(() => {}); }); draw();
    }
    async function refreshHistory() {
        const epoch = ++historyEpoch;
        const result = await request('status');
        if (epoch !== historyEpoch) return;
        if (typeof result.elementor?.supported !== 'boolean' || typeof result.api_key_configured !== 'boolean' || typeof result.can_create !== 'boolean' || !Array.isArray(result.elementor.widget_catalog) || !Array.isArray(result.history)) {
            throw new Error(labels.http_error.replace('%s', 200));
        }
        status = result;
        current = result.current;
        const availability = result.elementor;
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
        find('history').replaceChildren(...result.history.map((item) => {
            const tr = node('tr');
            for (const [main, small] of [[item.title, item.created_at + ' UTC'], [item.operation, item.status], [item.model, `${item.usage.input_tokens} / ${item.usage.output_tokens}`], [String(item.usage.images), usd.format(item.usage.estimated_usd)]]) { const td = node('td', main); td.append(node('small', small)); tr.append(td); }
            const td = node('td', item.backup_id); const button = node('button', 'Review'); button.type = 'button'; button.className = 'button'; button.addEventListener('click', () => { if (busy || loop) return; openOperation(current?.id === item.id ? current : item); find('result').scrollIntoView({block: 'start'}); }); td.append(button); tr.append(td); return tr;
        }));
        lock();
        return result;
    }

    async function loadStatus() {
        status = null;
        statusError = '';
        error();
        lock();
        try {
            const result = await refreshHistory();
            if (result?.current && !loop) openOperation(result.current);
            if (result?.elementor.available) loadPages(pageNumber);
        } catch (err) {
            status = null;
            statusError = err.message;
        } finally {
            lock();
        }
    }
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (generationBlocker() || !form.reportValidity()) { lock(); return; }
        const data = Object.fromEntries(new FormData(form));
        busy = true; error(); lock();
        try {
            requestId ||= typeof crypto.randomUUID === 'function' ? crypto.randomUUID() : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) => (Number(c) ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> Number(c) / 4).toString(16));
            const row = await request('operations', 'POST', {...data, reuse_media: input('reuse_media').checked, allow_image_reuse: input('allow_image_reuse').checked, request_id: requestId, media_ids: mediaIds});
            current = pending(row) ? row : null;
            requestId = null;
            openOperation(row);
            begin();
            find('result').scrollIntoView({block: 'start'});
        } catch (err) {
            error(err.message);
            if (!err.retryable) requestId = null;
            if (['cort_ai_pb_active', 'cort_ai_pb_key', 'cort_ai_pb_create_permission'].includes(err.code)) {
                const result = await refreshHistory().catch(() => null);
                if (result?.current) openOperation(result.current);
            }
        }
        finally { busy = false; draw(); }
    });
    find('retry-status').addEventListener('click', loadStatus);
    find('current-proposal').addEventListener('click', () => {
        const proposal = unfinished();
        if (!proposal || (loop && operation?.id !== proposal.id)) return;
        openOperation(proposal);
        find('result').scrollIntoView({block: 'start'});
    });
    find('resume').addEventListener('click', begin);
    find('refresh').addEventListener('click', async () => { if (!operation) return; busy = true; error(); lock(); try { updateOperation(await request('operations/' + operation.id)); await refreshHistory(); } catch (err) { error(err.message); } finally { busy = false; draw(); } });
    find('load-preview').addEventListener('click', () => { if (!operation?.preview_url) return; const link = node('a'); if (!safeLink(link, operation.preview_url)) { error(labels.network); return; } frame.src = link.href; find('preview-scroll').hidden = false; });
    find('device').addEventListener('change', () => { frame.width = find('device').value; });
    window.addEventListener('message', async (event) => {
        if (event.origin !== location.origin || event.source !== frame.contentWindow || event.data?.type !== 'cort-ai-pb-preview' || event.data.id !== operation?.id || event.data.hash !== operation?.preview_hash) return;
        const id = operation.id;
        try {
            const row = await request('operations/' + id);
            if (operation?.id === id && operation.status === 'preview') { updateOperation(row); draw(); }
        } catch (err) { error(err.message); }
    });
    find('approval').addEventListener('change', lock);
    find('restore-approval').addEventListener('change', lock);
    for (const [button, action] of [['apply', 'approve'], ['restore', 'restore'], ['discard', 'discard']]) find(button).addEventListener('click', async () => {
        if (busy || !operation) return;
        busy = true; error(); lock();
        try {
            updateOperation(await request(`operations/${operation.id}/${action}`, 'POST', action === 'discard' ? undefined : {approved: true, preview_hash: operation.preview_hash}));
            find('approval').checked = false; find('restore-approval').checked = false; await refreshHistory();
        } catch (err) { error(err.message); }
        finally { busy = false; draw(); }
    });
    adapt();
    loadStatus();
})();
