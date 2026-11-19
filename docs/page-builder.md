# AI Page Builder — architecture, compatibility and operation

This release adds a native Elementor page workflow to Cort AI Studio. Blog Writer's generation classes, HTTP transport, settings storage and usage calculations remain shared or unchanged. No Composer/npm dependency, MCP client, new provider key, or third-party widget package is required.

## Files and responsibilities

```text
cort-ai-studio.php             Namespace-aware autoloader; version 1.2.0
src/Plugin.php                 Registers the additional module
src/Admin.php                  Shared dashboard navigation URLs
src/PageBuilder/
  Module.php                   Admin menu, assets, dashboard metrics, preview observation
  Rest.php                     Authenticated page/operation endpoints
  Service.php                  Operation lifecycle, OpenAI orchestration, approval, recovery
  Adapter.php                  Contract for future builder integrations
  PlanEngine.php               Contract for builder-specific AI plan generators/compilers
  Support.php                  Builder-independent errors and canonical fingerprints
  ElementorAdapter.php         Elementor reading, validation, rendering, saving, fingerprints
  Blueprint.php                AI schema, constrained changes, native widget compilation
  DesignPlanning.php           Optional plan-engine contract for the visual planning stage
  DesignDirection.php          Strict art-direction schema, palette/scale validation
  ElementorCapabilities.php    Installed widget and native control contracts, including Pro gates
  VisualCompiler.php           Native visual roles, compositions and responsive controls
  MediaSelection.php           Context scoring, deduplication and native placeholders
  DesignQuality.php            Deterministic checks before saving generated compositions
  PreviewBackup.php            Temporary drafts, transactions, original snapshots, restoration
  Repository.php               Per-operation journal and usage aggregates
views/page-builder.php         Page selection, instructions, review, approval, operation history
assets/page-builder.js         Browser workflow and real preview controls
assets/page-builder.css        Scoped admin layout
assets/page-design.css         Scoped focus and reduced-motion accessibility guards
tests/fixtures.php             Synthetic page plans
tests/visual-fixtures.php      Five-section cafe test content, not a production template
tests/visual.php               Native visual CSS, patterns, media and document-layout checks
tests/integration.php          Guarded disposable-WordPress integration suite
tests/compatibility.php        Missing/unsupported Elementor feature-gate checks
tests/http-preview.mjs         Real HTTP/frontend preview and approval checks
tests/local-router.php         Explicitly isolated loopback test-server fixture
tests/mock-provider.php        Synthetic provider for the HTTP test server only
docs/page-builder.md           This guide
```

The existing `src/Writer.php`, `Article.php`, `Drafts.php`, `Jobs.php`, `Images.php`, `OpenAI.php`, `Settings.php`, `Usage.php`, `Sources.php`, `Text.php`, writer view and Blog Writer JavaScript are not refactored. Settings only relabels the existing spending limit to explain that it applies to both modules. The new module reuses `Settings`, `OpenAI`, `Usage` and the existing `Images` media persistence service.

`Service` and `PreviewBackup` depend on the `Adapter` and `PlanEngine` contracts, not on Elementor PHP classes or metadata names. Elementor-specific document creation, cloning, restoration, metadata invariants and native compilation stay in `ElementorAdapter`/`Blueprint`. A future builder requires its own adapter and plan engine plus module wiring; no Divi/WPBakery/Gutenberg adapter is supplied here.

## Lifecycle

1. Select an ordinary WordPress page or choose **Create New Page**. Existing non-Elementor pages are not automatically converted. The adapter reads the saved main document, not an unreviewed autosave.
2. Read the hierarchy and a bounded outline: IDs, widget types, labels, content, media references, local/global colors, typography, spacing, responsive settings, animations and CSS identifiers. Arbitrary custom CSS and executable widget content are not sent to the model. The active kit supplies global color/font references; it is read, never edited by this module.
3. Persist an operation with a UUID, user, page, model, options, original snapshot, profile and rate/budget snapshot. Only one unfinished proposal per requesting user is allowed. Blog Writer's site-wide active slot is independent.
4. For create/add, first request a strict visual direction, then a separate bounded composition/content plan through the existing Responses API transport. Both stages are resumable and their usage is added to the same operation. Edit/content request only scoped widget-field updates. The model cannot submit raw Elementor JSON, PHP, JavaScript, CSS or file writes. Remove/reorder are deterministic and incur no text API call. Pre-1.2 in-flight jobs retain the original compiler path.
5. Resolve relevant Media Library references, compile native controls and validate scope/design before optional image charges. Discard unused image plans. Create a separate native Elementor **draft**; new pages persist their selected template through the document settings API. Generate optional images there using the existing Images API and Media Library service. A failed image leaves a native placeholder and warning; it does not discard the rest of the proposal. Recheck the final composition after images resolve.
6. Save through Elementor's document API, compare the stored tree to the proposed tree, and render it with Elementor's frontend renderer. Any discarded/changed setting causes rejection. No original page has been written at this point.
7. Open the normal WordPress frontend preview, in the iframe or a separate tab. The module observes actual Elementor output and marks the exact preview fingerprint reviewed at `wp_footer`. Merely having a preview URL does not authorize applying it. Desktop/tablet/mobile controls change iframe viewport width; this is not a claim of device/browser certification.
8. The user checks the approval box and clicks **Apply approved changes**. The server requires capabilities, REST nonce, reviewed fingerprint and explicit approval, then verifies that the preview, original, Elementor version and design system have not changed.
9. For an existing page, persist a backup before writing, lock its post/metadata rows in an InnoDB transaction, recheck editor locks and the source fingerprint, save through Elementor and verify the result. Preserve the page's title, slug, status, document settings and unrelated elements. New pages become normal editable drafts; they are never automatically published.
10. Retain the journal, usage and backup identifier. **Restore previous version** is separately approved and only succeeds if the document still matches the applied version. An interrupted apply/restore is reconciled from saved state; uncertain or conflicting states are not silently overwritten.

The browser advances generation in separate REST requests. Closing it pauses subsequent stages; return and use **Resume / Verify**. There is no cron worker or webhook in this MVP. A dispatched request whose result cannot be confirmed is not automatically repeated. Such usage is marked incomplete.

## Supported operations

| Operation | Implemented behavior |
| --- | --- |
| Create New Page | Title/slug; 1–12 planned native sections, up to four columns per section; coherent visual tokens, desktop/tablet/mobile controls, optional subtle motion and document layout. Draft only. |
| Add Section | One new section at start/end or before/after a selected top-level section. Existing elements and IDs are retained. |
| Edit Existing Section / Update Content | Change supported heading text, text-editor paragraphs, button labels, links and image references inside one selected section. Existing layout, responsive controls and other settings are retained. |
| Remove Section | Remove the selected top-level section and its children after preview and approval. Removing the last section is blocked. |
| Reorder Sections | Move a selected top-level section without rebuilding its contents. Position controls are authoritative; free text is not interpreted for this deterministic operation. |
| Preview / Apply / Restore | Real Elementor frontend preview, explicit approval, journaled original snapshot, conflict detection and restoration. |

Generated Core widgets are `heading`, `text-editor`, `button`, `image`, `icon`, `icon-box`, `image-box` and `image-carousel`, when registered. Galleries use responsive native image grids or carousels; the basic Core gallery's nonresponsive column control is not offered for generation. Service cards use native columns/containers plus widgets; FAQs use headings and paragraphs. There is no giant HTML widget and no fake testimonial generator. Missing business information must be omitted. AI output still requires editorial review.

New compositions use Flexbox when Elementor's container feature is active and classic sections/columns otherwise. Flexbox uses native `flex_gap`, gap-aware proportional widths and responsive wrapping. Classic uses a native section/column/inner-section hierarchy, proportional widths, column margins and widget spacing. Existing deeper hierarchies can be inspected and preserved, subject to validation, limits and available widgets. Third-party widgets are not generated. Pro Call to Action and Animated Headline are offered only if registered controls match explicit ID/type contracts; their real Pro rendering remains unverified. Existing registered widgets can remain unchanged only if Elementor preserves their data exactly. Missing widget plugins block writes. Dynamic fields are not editable through content updates.

## Visual generation in 1.2

`DesignDirection` defines art direction, eight palette roles, confirmed kit font IDs, a type scale, spacing, content width, image strategy, motion and section purposes. Invalid colors/scales/fonts fail; low-contrast palette foregrounds are adjusted. Font family/weight can be copied from a confirmed kit entry while local responsive sizes remain editable. The kit itself is never changed. Section and card foregrounds are checked again against their actual solid backgrounds.

The eleven bounded patterns are centered, split, background, overlay, asymmetric, content, features, cards, gallery, highlight and CTA. Their column order, proportions, content and media come from each business brief. Native settings implement backgrounds/overlays/gradients, radius, normal/hover shadows, image crops, line height, letter spacing, button states and responsive padding. Card headings have a compact role. Mobile buttons can fill their column. Carousels use three/two/one visible slides and no autoplay.

Motion is `none` or `subtle`: native entrance fades with bounded stagger, native image hover and capability-gated card transforms. Popover values are taken from the actual control contract, including the transform class trigger. The small frontend stylesheet adds focus indicators and respects `prefers-reduced-motion`; it contains no model-generated design CSS. Slides and advanced scrolling/parallax Motion Effects are detected where present but are not authored. Atomic remains read-only.

Media selection uses title, alt, description, requested subject and role. Optional automatic library searches are bounded and run against the initial brief and later component subjects. Only readable local image attachments are candidates. Attachments and canonical file URLs are deduplicated across generated components; users can explicitly allow reuse. This is metadata matching, not image recognition or pixel-level duplicate detection. Add descriptive media metadata and review the chosen photographs. Missing media uses Elementor's official placeholder, an image-widget caption where applicable and a report warning. Generated images remain optional, with at most two authorized slots.

New-page layout choices are Default, Elementor Full Width and Elementor Canvas, with an automatic option constrained by the design brief. Canvas is intended for explicitly standalone pages and hides the document title. Full Width keeps the theme header/footer. Existing pages always preserve their document template/settings through preview, application and restoration. No theme or Theme Builder setting is changed.

`DesignQuality` checks exactly one generated H1 on create, skipped heading levels, duplicate/newly colliding anchors, fragment destinations, solid text contrast, composition variety, responsive/styled element counts, distinct images and placeholders. Native controls/widgets are checked by the compiler before saving. Spacing, proportions and typography are bounded at planning/compilation. The report deliberately does not assign a visual-quality score: photographic contrast, semantic image relevance, actual wrapping and theme/browser behavior require the real preview. A saved JSON round trip alone does not prove a control affects CSS.

## Compatibility and official APIs

The runtime write gate accepts Elementor **3.21.x through 4.4.x**, only for classic/Flexbox document structures and available public methods. Releases below 3.21 or at/above 4.5 are disabled pending review. This version range is an eligibility gate, not certification of every patch release, theme or addon.

Mechanisms used:

- `Plugin::$instance->documents->get($id, false)` and `Document::get_elements_data()` to read a main document.
- `documents->create('wp-page', …)` to create a native preview document.
- `Document::set_is_built_with_elementor()` and `Document::save(['elements' => …])` to persist native elements. Elementor handles its save hooks, versioning, plain-text content and generated caches.
- `frontend->get_builder_content_for_display($id, true)` for server-side render verification and the normal WordPress frontend for the visual preview.
- `elementor/frontend/the_content` and `wp_footer` to acknowledge a rendered preview.
- `Core\Files\CSS\Post::create($id)->delete()` to invalidate generated document CSS after restoring or rolling back.

Maintenance-sensitive details: classic widget control IDs, `_elementor_data`, `_elementor_page_settings`, template/version metadata, generated cache keys, global reference formats and responsive control suffixes. These are isolated in the adapter/compiler and must be reviewed when raising the version gate. Normal writes use the document API. Exact snapshot restoration additionally restores only server-owned, whitelisted Elementor metadata and the page template/thumbnail references, then invalidates generated caches. It does not accept arbitrary metadata from the client or AI. Standard WordPress revisions alone are not used as the backup.

Atomic V4 is **detected and read-only**. Its typed props (`$$type`/`value`), versioned widgets, interactions and style variants/classes are a different data model. An Atomic or mixed Atomic/classic document is not silently converted. Enabling Atomic globally does not by itself block an entirely classic/Flexbox page. Advanced full-page/structural redesign is disabled; the available alternative is adding native sections, updating scoped content, removing/reordering, or performing the advanced redesign manually in Elementor on a staging copy.

The official Elementor MCP uses externally authorized MCP clients and WordPress AI Abilities, with its own enablement, application-password authorization and version requirements (the reviewed official setup describes WordPress 6.8+ and Elementor 4.3+). Its current focus is Atomic layouts. It is not an automatically available PHP editing service and would introduce a separate integration/authentication path. This plugin neither enables MCP nor creates application passwords. MCP/Atomic authoring is deferred rather than advertised as implemented.

Primary references reviewed for this implementation:

- [Elementor data structure](https://developers.elementor.com/docs/data-structure/), [containers](https://developers.elementor.com/docs/data-structure/container-element), [responsive data](https://developers.elementor.com/docs/data-structure/responsive-data), [global styles](https://developers.elementor.com/docs/data-structure/global-styles).
- [Document model](https://github.com/elementor/elementor/blob/main/docs/core/base/document.md) and [Document source](https://github.com/elementor/elementor/blob/main/core/base/document.php); local installed sources 3.21.5, 3.30.0 and 4.4.0 were also inspected/tested.
- [Atomic elements](https://developers.elementor.com/docs/data-structure/atomic-elements), [Atomic widgets](https://developers.elementor.com/docs/data-structure/atomic-widgets), [Atomic props](https://developers.elementor.com/docs/data-structure/atomic-prop-values), [Atomic styles](https://developers.elementor.com/docs/data-structure/atomic-styles.html).
- [Official MCP connection requirements](https://elementor.com/help/how-to-connect-elementor-to-an-ai-tool-using-mcp/) and [official MCP scope](https://elementor.com/mcp/).

## Security, persistence and costs

REST prefix: `cort-ai-studio/v1/page-builder`. Routes expose status/history, paginated page search, page inspection, operation creation/read/advance, approval, discard and restore. Every route checks page-editing capabilities plus `X-WP-Nonce`; operation access also checks owner/admin and page permissions. Uploads require `upload_files`. Existing media must be readable image attachments; the model cannot invent media IDs or fetch remote images.

The independent `{prefix}cort_ai_pb_operations` table stores dates, user/page IDs, operation, model, state/result, preview ID, leases, measured tokens, images, estimated cost, incomplete-usage flag and backup state. Backup IDs have the form `PB-{operation_id}`. The dashboard retains all article statistics and adds separate page-operation totals; the module shows the latest 15 operations. Costs use the same `Usage` rates, cache-token handling, Images usage and budget configured in Settings. The existing per-article limit also applies per page proposal. It is an estimate, not an API billing hard cap. `0` disables it.

Safety checks include bounded payloads (44 KB submitted form, 90 KB AI request, 650 native nodes, depth 18), allowed widgets/fields/styles, safe links and escaped generated text. Generated shortcodes are removed. Unrelated content is retained; malformed structures, missing widgets, Atomic structures and unconfirmed round trips fail closed. PHP logs contain operation IDs and exception classes, not API keys or raw provider responses. Instructions/profile/page data are treated as untrusted prompt context and are never executed.

Application/restoration requires InnoDB posts/postmeta tables. The journal uses leases to prevent duplicate execution; transactions and fingerprints guard document writes. Open editor locks (including the same user's other tab) and newer autosaves block application. External custom code that ignores WordPress locks, triggers irreversible external effects in save hooks, or modifies documents after a completed transaction cannot be controlled by this plugin; conflict checks prevent a later automated restore from erasing such changes.

Journals/backups and preview drafts are retained for review; no automatic retention purge is implemented. Temporary proposals are labeled in the WordPress page list and cannot be published through the normal WordPress save flow; approval accepts new pages as ordinary drafts. Discarding trashes an unapplied preview. Generated attachments remain in Media Library, including after restore/discard. Existing shared attachment alt text is not silently changed: manage it in the standard media selector. Generated attachments receive the requested alt text. Changing global kit settings, Theme Builder templates or site headers/footers is outside this release, as are Divi and WPBakery. Per-document layout selection applies only to new pages.

## Using the module

1. Install/activate Elementor and configure Cort AI Studio's existing OpenAI Settings and Website Content Profile. Review the shared spending limit for longer page requests.
2. Open **Cort AI Studio → AI Page Builder**. Create a page or search/select an existing Elementor page. Review compatibility notices and its named/ID hierarchy.
3. Choose the operation. Select the target section and insertion anchor where applicable. For a new page supply title and optional slug. Close any WordPress/Elementor editor tab for the source page and let its edit lock expire.
4. Write instructions. Choose a layout for a new page, keeping Default when its theme header/footer are desired. Optionally select/upload Media Library images or enable contextual library reuse; repeated images are disabled unless explicitly allowed. Image generation remains optional (one/two images). Select model/reasoning. No extra API key is required.
5. Click **Generate / Preview Proposal**. A successful proposal displays actual structured changes, tokens, costs and warnings.
6. Load the real preview. Inspect desktop/tablet/mobile widths. A theme must render Elementor content and call `wp_footer`; if it does not, automatic approval remains disabled. Opening the preview in a new tab also works; return and refresh operation status.
7. Check the approval box and apply. New pages remain drafts. For existing published pages, approval writes the reviewed change while retaining publication status.
8. Open **Edit in Elementor** to continue visual editing. To restore before making further edits, open the operation in history, approve restoration and click **Restore previous version**. If the page has since changed, restore is intentionally blocked: review the newer version rather than overwriting it.

## Verification

Run syntax checks with the project's PHP version and `node --check assets/admin.js` / `node --check assets/page-builder.js`.

Integration tests are destructive **only to their explicitly disposable test installation**. They create synthetic pages, attachments/users, save test settings and intercept all OpenAI requests. They must never be pointed at a real site. No real OpenAI generation or billing is performed by this suite.

Prepare a separate local WordPress installation, activate Elementor and this plugin, and create administrator `cort_test_admin`. Its config must explicitly define `WP_ENVIRONMENT_TYPE` as `local` and `CORT_AI_PB_TEST_INSTALLATION` as `true`. Place a `.cort-ai-studio-test` file in that WordPress root containing exactly `CORT_AI_STUDIO_ISOLATED_TEST`. Without these gates the CLI suite refuses to run.

```powershell
$env:CORT_AI_TEST_WP_ROOT = 'C:\path\to\disposable\wordpress'
$env:CORT_AI_TEST_JPEG = 'C:\path\to\disposable\fixture.jpg' # valid JPEG required for the complete visual/media suite
php -d memory_limit=512M tests/integration.php
```

The suite covers native classic/Flexbox round trips and rendering, draft creation, enforced approval, add/update/remove/reorder, exact backup restoration, concurrency conflicts, rollback after altered saves, locks, preview tampering, Atomic rejection, unsafe links/shortcodes/scope, idempotency, uncertain API calls, image failures/persistence/alt text, budget, nonces/capabilities and original Blog Writer orchestration/SEO/usage. Visual cases exercise all eleven patterns in both layouts, actual generated CSS/markup, responsive settings, motion toggles, controlled media matching/deduplication, missing-media placeholders, invalid directions, anchor collisions, document templates and unused-image billing. It restores the original REST bootstrap callbacks between simulated requests because Elementor's internal simulated server removes them in a single long-running CLI process.

For the separate HTTP test, copy `tests/mock-provider.php` into the disposable installation's `wp-content/mu-plugins`, and keep this plugin's `tests/fixtures.php` available. Use a dummy API key and a sufficient test budget in that installation. With the same environment variable and test markers, start PHP's development server on loopback only:

```powershell
php -d memory_limit=512M -S 127.0.0.1:8097 -t $env:CORT_AI_TEST_WP_ROOT tests/local-router.php
# In another terminal:
$env:CORT_AI_TEST_HTTP = 'http://127.0.0.1:8097'
node tests/http-preview.mjs
```

Set the disposable installation's `WP_HOME` and `WP_SITEURL` to that loopback origin. The router creates only the disposable `cort_test_admin` session and refuses non-loopback callers/non-test installations. Never deploy these fixtures as server configuration. The HTTP suite checks admin assets, nonce protection, native frontend rendering and footer acknowledgement, blocked unreviewed application, draft-only creation, original isolation, reorder, approval, backup and restoration. Browser interaction/visual QA is a separate check.

```powershell
php tests/compatibility.php absent
php tests/compatibility.php 3.20.0
php tests/compatibility.php 4.5.0
```

Recorded 1.2 validation: **29 cases passed, zero failures on each of Elementor 3.21.5, 3.30.0 and 4.4.0** (87 passing case executions), using WordPress **7.1.4**, PHP **8.2.12**, a separate temporary MariaDB database and synthetic provider responses. The HTTP preview/approval/restore workflow passed with Elementor 4.4.0, including Canvas persistence, visual reports, both stages' tokens and the scoped frontend stylesheet. Missing Elementor and versions 3.20.0/4.5.0 correctly disable writes. PHP/JavaScript syntax checks passed. Thirteen shared Blog Writer/API/media/usage files were compared by SHA-256 to 1.1.0 and are unchanged. No existing local site database or real OpenAI generation was used.

The five-section premium cafe scenario compares the same content/media references through the original compiler and the visual compiler. Its deliberately repeated media reference tests deterministic correction rather than the quality of a live model answer:

| Criterion | Original compiler | Visual compiler |
| --- | --- | --- |
| Section composition variants | One equal-column structure | Five: split, content, cards, features, CTA |
| Distinct attachments across five image slots | 1 | 5, selected by controlled metadata |
| Extra repeated image uses | 4 | 0 |
| Tablet product grid | Inherits three desktop columns | Explicit two-column setting, mobile one column |
| Local display type scale | Inherits theme/kit unless individually set | 64 / 48 / 38 px, verified in native CSS |
| Card design | Basic columns | Native padding, margin/gap, radius, shadows and optional hover |
| Solid text contrast | No deterministic check | At least 4.5:1 checked before saving |

These are structure, CSS and metadata results. Media fixtures are synthetic JPEGs with controlled metadata, not visually verified cafe photographs. The browser automation host was unavailable, so rendered screenshots, measured viewport overflow and actual photographic contrast were not reviewed. No Elementor Pro installation was available: conditional CTA/Animated Headline contracts are not a claim of tested Pro rendering. Advanced Motion Effects, Slides authoring and Atomic editing remain deferred.

Additional primary references used for 1.2: [responsive controls](https://developers.elementor.com/docs/editor-controls/responsive-control), [native box shadow group](https://developers.elementor.com/docs/editor-controls/group-control-box-shadow), [page settings/layouts](https://elementor.com/help/page-settings/), [motion effects and accessibility](https://elementor.com/help/motion-effects/), [Core/Pro capabilities](https://elementor.com/help/elementor-pro-vs-free/). The actual installed Core control definitions and emitted CSS were checked as well; documentation alone is not used to assert a native control exists.

Live OpenAI generation, site-specific themes/addons/Pro widgets, custom breakpoints, every supported-version patch and visual behavior across real browsers/devices require staging validation with the site's own configuration. They are not implied by passing mocked-provider integration tests.
