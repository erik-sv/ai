# PR #294 Review Response — WBS

dkotter review (2026-03-30), CHANGES_REQUESTED. 21 line comments + top-level review.

## Already Addressed

| # | Comment | Resolution |
|---|---------|------------|
| Top | COSE_Sign1 verification missing | `COSE_Sign1_Verifier` + `JUMBF_Reader` + full crypto in `verify_jumbf()` |
| Top | Custom C2PA approximation | Implementation now spec-compliant: JUMBF, COSE, CBOR, ES256, exclusions, NFC |
| 1-2 | Remove docs/content-provenance.md | File never existed in our branch |
| 3 | @since should be x.x.x | All @since tags already use x.x.x |
| 4 | C2PA_Sign constructor unnecessary | No constructor defined; framework handles via ability_class |
| 5 | plain text vs wp_kses_post | Already using sanitize_text_field |
| 6 | C2PA_Verify constructor unnecessary | No constructor defined |
| 7 | Schema properties for metadata | Already defined: title, url, author, post_id |
| 8 | Use enum for signing tier | Already using enum: local, connected, byok |
| 14 | get_bloginfo always exists | No function_exists guard in current code |
| 15 | API key in page source | Already masked (all but last 4 chars replaced with *) |
| 16 | settingsUrl fix | Already set to admin_url('admin.php?page=ai') |
| 18 | JS disabled-state string | Already matches dkotter's suggestion |
| 20 | Encrypt API key at rest | encrypt_value/decrypt_value already implemented (AES-256-CBC) |
| 21 | BYOK path traversal | validate_file_path with realpath + ABSPATH containment |

## Remaining Work

### 1.1 Remove duplicate @since change notes
- **Files**: Connected_Signer, Local_Signer, BYOK_Signer, Signing_Interface, Unicode_Embedder, C2PA_Manifest_Builder, Content_Provenance
- **Action**: `@since x.x.x Returns JUMBF...` → `@since x.x.x` (no release yet, change notes are noise)
- **Effort**: Trivial

### 1.2 Remove function_exists('get_plugin_data') guard
- **File**: Claim_Builder.php line 387
- **Action**: Remove the guard; WP 7.0 minimum guarantees availability
- **Effort**: Trivial

### 1.3 Claim_Builder version — reply only
- **Comment**: "Should this version be dynamic?"
- **Answer**: Already dynamic via get_plugin_data(). Reply on PR explaining this.
- **Effort**: None (PR comment)

### 2.1 JS: Clean up apiFetch calls
- **File**: index.js
- **Action**: Use `path` instead of `url` + remove manual nonce header (apiFetch middleware handles it)
- **Effort**: Small

### 2.2 JS: Move inline styles to SCSS
- **File**: index.js → index.scss
- **Action**: Extract all inline `style` props to CSS classes. Rename style.scss → index.scss per project convention. Update import.
- **Effort**: Medium

### 2.3 JS: Inline styles inventory
- TrustTierNotice: `style={{ marginBottom: '12px' }}`
- Error Notice: `style={{ marginTop: '8px' }}`
- Verify Notice: `style={{ marginTop: '8px' }}`
- ShieldBadge: `style={{ background: cfg.fill, '--badge-color': cfg.color }}` (dynamic — keep as inline or use CSS custom properties)
- Badge label: `style={{ color: cfg.color }}` (dynamic — same)

Dynamic styles (badge color/fill) stay inline. Fixed spacing styles move to SCSS.
