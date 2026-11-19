// End-to-end HTTP checks for the disposable local fixture router documented in docs.
// No browser/session automation and no production URL is accepted.
import assert from 'node:assert/strict';
import {randomUUID} from 'node:crypto';

const base = process.env.CORT_AI_TEST_HTTP;
if (!base || !['127.0.0.1', 'localhost', '[::1]'].includes(new URL(base).hostname)) {
    throw new Error('Set CORT_AI_TEST_HTTP to the disposable loopback WordPress test server.');
}
const login = await fetch(new URL('/__cort_test_session', base), {redirect: 'manual'});
assert.equal(login.status, 302, 'The explicitly isolated session fixture must be installed.');
const cookie = login.headers.getSetCookie().map((entry) => entry.split(';')[0]).join('; ');
assert.ok(cookie.includes('wordpress_logged_in_'), 'Test session was not created.');
const admin = await fetch(new URL('/wp-admin/admin.php?page=cort-ai-studio-page-builder', base), {headers: {Cookie: cookie}});
assert.equal(admin.status, 200);
const html = await admin.text();
assert.ok(html.includes('data-page-builder') && html.includes('page-builder.js'), 'Page Builder UI/assets missing.');
assert.ok(html.includes('name="document_layout"') && html.includes('name="reuse_media"') && html.includes('data-design-report'), 'Visual planning/layout controls missing.');
assert.ok(html.includes('data-compatibility-panel') && html.includes('data-compatible-widgets'), 'Capability inventory panel missing.');
assert.ok(!html.includes('sk-cort-ai-isolated-test-key'), 'API key appeared in the HTML.');
const config = JSON.parse(html.match(/window\.CortAIPageBuilder = (\{.*?\});/s)?.[1] || '{}');
assert.ok(config.root && config.nonce, 'Admin REST configuration missing.');

function endpoint(path) {
    const url = new URL(config.root);
    if (url.searchParams.has('rest_route')) url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/$/, '') + '/' + path);
    else url.pathname = url.pathname.replace(/\/$/, '') + '/' + path;
    return url;
}
async function request(path, method = 'GET', body, nonce = true) {
    const response = await fetch(endpoint(path), {method, headers: {Cookie: cookie, ...(nonce ? {'X-WP-Nonce': config.nonce} : {}), ...(body ? {'Content-Type': 'application/json'} : {})}, ...(body ? {body: JSON.stringify(body)} : {})});
    const data = await response.json();
    return {response, data};
}
async function ok(path, method, body) { const {response, data} = await request(path, method, body); assert.equal(response.status, 200, JSON.stringify(data)); return data; }
async function finish(row) {
    for (let i = 0; row.status === 'generating' && i < 12; ++i) row = await ok(`operations/${row.id}/advance`, 'POST');
    assert.equal(row.status, 'preview', JSON.stringify(row));
    return row;
}
async function review(row) {
    const response = await fetch(row.preview_url, {headers: {Cookie: cookie}});
    assert.equal(response.status, 200);
    const document = await response.text();
    assert.ok(document.includes(`data-elementor-id="${row.preview_id}"`), 'Normal frontend did not render native Elementor data.');
    assert.ok(document.includes('page-design.css'), 'Generated page accessibility styles were not enqueued.');
    assert.ok(document.includes('cort-ai-pb-preview'), 'Rendered preview was not acknowledged at wp_footer.');
    const loaded = await ok('operations/' + row.id);
    assert.equal(loaded.reviewed, true, 'Actual frontend preview did not enable approval.');
    return loaded;
}

assert.equal((await request('status', 'GET', undefined, false)).response.status, 403, 'Missing REST nonce accepted.');
const state = await ok('status');
assert.equal(state.elementor.supported, true);
assert.equal(state.elementor.atomic, false, 'Atomic must remain excluded.');
assert.ok(state.elementor.widget_catalog.some((widget) => widget.name === 'counter' && widget.authoring), 'Actual classic-widget contracts missing from the admin API.');
if (state.elementor.providers?.pro_elements?.detected) {
    assert.equal(state.elementor.providers.elementor_pro.detected, false, 'PRO Elements was mislabeled as official Pro.');
    assert.ok(state.elementor.widget_catalog.some((widget) => widget.name === 'form' && widget.authoring), 'Available PRO Elements Form contract missing.');
}
if (state.current) await ok(`operations/${state.current.id}/discard`, 'POST');
const options = {request_id: randomUUID(), page_id: 0, operation: 'create', title: 'HTTP native preview test', slug: 'http-native-preview-test', instructions: 'Create native test sections for a standalone landing page.', model: Object.keys(config.efforts)[0], effort: 'medium', image_count: 0, media_ids: [], document_layout: 'elementor_canvas'};
let row = await finish(await ok('operations', 'POST', options));
assert.ok(row.design_summary?.art_direction && row.design_quality?.checks, 'Visual direction/check report missing.');
assert.equal(row.usage.input_tokens, 240, 'Both visual planning and composition input usage must be recorded.');
assert.equal(row.usage.output_tokens, 440, 'Both visual planning and composition output usage must be recorded.');
assert.equal((await request(`operations/${row.id}/approve`, 'POST', {approved: true, preview_hash: row.preview_hash})).response.status, 409, 'Unreviewed proposal was applied.');
row = await review(row);
row = await ok(`operations/${row.id}/approve`, 'POST', {approved: true, preview_hash: row.preview_hash});
assert.equal(row.status, 'applied');
const pageId = row.page_id;
const original = await ok('pages/' + pageId);
assert.equal(original.status, 'draft');
assert.equal(original.editable, true);
assert.equal(original.tree.length, 2);
assert.equal(original.document_layout, 'elementor_canvas', 'Canvas template did not persist through approval.');
await new Promise((resolve) => setTimeout(resolve, 8100)); // The actual public endpoint cooldown.
row = await finish(await ok('operations', 'POST', {...options, request_id: randomUUID(), operation: 'reorder', page_id: pageId, section_id: original.tree[0].id, position: 'end'}));
assert.equal((await ok('pages/' + pageId)).fingerprint, original.fingerprint, 'Source changed before review.');
row = await review(row);
row = await ok(`operations/${row.id}/approve`, 'POST', {approved: true, preview_hash: row.preview_hash});
assert.equal(row.status, 'applied');
assert.ok(row.backup_id && row.can_restore);
assert.equal((await ok('pages/' + pageId)).tree[1].id, original.tree[0].id, 'Section move was not saved.');
row = await ok(`operations/${row.id}/restore`, 'POST', {approved: true});
assert.equal(row.status, 'restored');
assert.equal((await ok('pages/' + pageId)).tree[0].id, original.tree[0].id, 'Original order was not restored.');
console.log('PASS HTTP admin/assets, nonce protection, native frontend preview acknowledgement, mandatory review, draft creation, reorder, source isolation, approval, backup and restoration.');
console.log('No real OpenAI calls; provider responses come from the disposable fixture.');
