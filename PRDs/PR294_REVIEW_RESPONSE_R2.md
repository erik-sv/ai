# PR #294 Review Response R2 - WBS

dkotter round 2 (2026-03-31), 10 new comments after our initial reply batch.

## Triage

### P0 - Bugs (functionality broken)

#### 1.1 Sign button broken
- **Comment**: 3017034049
- **Problem**: JS calls `runAbility('c2pa/sign', { post_id })` but the ability requires `text`. Even if we fix the params, the ability is a pure function that returns signed_text without saving to the post or writing meta.
- **Root cause**: No REST endpoint for signing a post by ID. The auto-sign-on-publish flow uses `sign_post()` server-side, but the manual "Sign Now" button has no equivalent server-side entry point.
- **Fix**: Add `POST /c2pa-provenance/v1/sign` REST endpoint that accepts `post_id`, calls `sign_post()`, returns status. JS calls this endpoint instead of `runAbility`.

#### 1.2 Verify button broken
- **Comment**: 3017043157
- **Problem**: JS calls the `/status` endpoint (which reads cached post meta) instead of actually verifying the cryptographic signature against current content.
- **Fix**: Add `POST /c2pa-provenance/v1/verify-post` REST endpoint that accepts `post_id`, reads saved post content, runs `extract_and_verify()`, returns verification result. JS calls this instead of `/status`.

### P1 - Code quality

#### 2.1 Use WPAI_VERSION constant
- **Comment**: 3016881742
- **Problem**: `Claim_Builder::get_plugin_version()` uses `get_plugin_data()` with `require_once` when `WPAI_VERSION` constant already exists (defined in `includes/bootstrap.php`).
- **Fix**: Replace entire `get_plugin_version()` method body with `return defined('WPAI_VERSION') ? WPAI_VERSION : '0.0.0'`.

#### 2.2 Deprecated @wordpress/edit-post import
- **Comment**: 3017012064
- **Fix**: Change `import { PluginDocumentSettingPanel } from '@wordpress/edit-post'` to `@wordpress/editor`. Deprecated since WP 6.6.

#### 2.3 JS dependency comment blocks
- **Comments**: 3017008005, 3017008988
- **Fix**: Add `/** WordPress dependencies */` and `/** Internal dependencies */` section headers per project convention.

#### 2.4 Link spacing
- **Comment**: 3017024869
- **Fix**: Add space before the "Connect a signing service" link so it doesn't bump into the preceding text.

### P2 - UI/UX

#### 3.1 Settings UI overhaul
- **Comment**: 3016995032
- **Problem**: Settings use `<details>/<summary>` collapsibles, show all tiers simultaneously, and lack alignment with other experiment settings.
- **Fix**:
  1. Remove `<details>`/`<summary>` wrappers so settings are always visible
  2. Conditionally show/hide Connected and BYOK config sections based on selected tier (inline JS or CSS class toggle)
  3. Indent/align tables with rest of settings UI

#### 3.2 Panel spacing in editor
- **Comment**: 3017028406
- **Fix**: Adjust SCSS spacing for the sidebar panel components (trust tier notice, badge, actions).

### P3 - Content

#### 4.1 Experiment documentation
- **Comment**: 3016803871
- **Problem**: We said the docs file doesn't exist in our branch, but dkotter's point is that it should. Other experiments have docs in `docs/experiments/`.
- **Fix**: Create `docs/experiments/content-provenance.md` following the established pattern (Summary, Overview, Architecture, REST API, Testing, Related Files).

## Implementation Order

1. Write tests for P0 (sign and verify REST endpoints)
2. Add REST endpoints, fix JS callers
3. Fix P1 items (WPAI_VERSION, deprecated import, comments, spacing)
4. Fix P2 items (settings UI, panel spacing)
5. Create P3 docs
6. Run full test suite
7. Single commit, do NOT push yet
