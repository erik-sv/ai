/**
 * WordPress dependencies
 */
const { test, expect } = require( '@wordpress/e2e-test-utils-playwright' );

/**
 * Internal dependencies
 */
const {
	disableExperiment,
	enableExperiment,
	enableExperiments,
	visitSettingsPage,
} = require( '../../utils/helpers' );

/**
 * Opens the Content Provenance panel in the editor sidebar.
 *
 * The PluginDocumentSettingPanel may render collapsed if user preferences
 * override initialOpen. This clicks the panel header to expand it.
 *
 * @param {import('@playwright/test').Page} page Playwright page object.
 */
const openProvenancePanel = async ( page ) => {
	// Find the panel header button containing "Content Provenance".
	const panelButton = page.locator( 'button.components-panel__body-toggle', {
		hasText: 'Content Provenance',
	} );

	// Wait for the panel header to appear in the sidebar.
	await expect( panelButton ).toBeVisible( { timeout: 10000 } );

	// Check if the panel is already open by looking for the badge inside it.
	const badge = page.locator( '.content-provenance-badge' );
	const isOpen = await badge.isVisible().catch( () => false );

	if ( ! isOpen ) {
		await panelButton.click();
	}
};

test.describe( 'Content Provenance Experiment', () => {
	test( 'Can enable the content provenance experiment', async ( {
		admin,
		page,
	} ) => {
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, 'content-provenance' );

		// Verify the experiment settings section is visible after enabling.
		await expect(
			page.locator( '.ai-experiment-content-provenance-settings' )
		).toBeVisible();
	} );

	test( 'Settings page shows signing tier dropdown that toggles sections', async ( {
		admin,
		page,
	} ) => {
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, 'content-provenance' );

		// The signing tier dropdown should be visible.
		const tierSelect = page.locator( 'select[name*="signing_tier"]' );
		await expect( tierSelect ).toBeVisible();

		// Default is "local" - no tier-specific sections should be visible.
		await expect( tierSelect ).toHaveValue( 'local' );
		await expect(
			page.locator( '.c2pa-tier-connected' )
		).not.toBeVisible();
		await expect( page.locator( '.c2pa-tier-byok' ) ).not.toBeVisible();

		// Switch to "connected" - connected section should appear.
		await tierSelect.selectOption( 'connected' );
		await expect(
			page.locator( '.c2pa-tier-connected' )
		).toBeVisible();
		await expect( page.locator( '.c2pa-tier-byok' ) ).not.toBeVisible();

		// Verify connected section has expected fields.
		await expect(
			page.locator( '.c2pa-tier-connected input[type="url"]' )
		).toBeVisible();
		await expect(
			page.locator( '.c2pa-tier-connected input[type="password"]' )
		).toBeVisible();

		// Switch to "byok" - BYOK section should appear, connected should hide.
		await tierSelect.selectOption( 'byok' );
		await expect(
			page.locator( '.c2pa-tier-connected' )
		).not.toBeVisible();
		await expect( page.locator( '.c2pa-tier-byok' ) ).toBeVisible();

		// Verify BYOK section has expected fields.
		await expect(
			page.locator(
				'.c2pa-tier-byok input[placeholder*="private"]'
			)
		).toBeVisible();
		await expect(
			page.locator(
				'.c2pa-tier-byok input[placeholder*="cert"]'
			)
		).toBeVisible();

		// Switch back to "local" - both sections should hide.
		await tierSelect.selectOption( 'local' );
		await expect(
			page.locator( '.c2pa-tier-connected' )
		).not.toBeVisible();
		await expect( page.locator( '.c2pa-tier-byok' ) ).not.toBeVisible();
	} );

	test( 'Settings page shows publishing options', async ( {
		admin,
		page,
	} ) => {
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, 'content-provenance' );

		// Auto-sign checkbox should be visible.
		await expect(
			page.locator( 'input[name*="auto_sign"]' )
		).toBeVisible();

		// Show badge checkbox should be visible.
		await expect(
			page.locator( 'input[name*="show_badge"]' )
		).toBeVisible();

		// Badge position dropdown should be visible.
		await expect(
			page.locator( 'select[name*="badge_position"]' )
		).toBeVisible();
	} );

	test( 'Editor sidebar shows Content Provenance panel when experiment is enabled', async ( {
		admin,
		editor,
		page,
	} ) => {
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, 'content-provenance' );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Content Provenance',
			content: 'This post will test the content provenance sidebar panel.',
		} );

		// Open the Content Provenance panel.
		await openProvenancePanel( page );

		// The badge should be visible.
		await expect(
			page.locator( '.content-provenance-badge' )
		).toBeVisible();

		// Sign Now button should be visible.
		await expect(
			page.locator( '.content-provenance-panel__actions button', {
				hasText: 'Sign Now',
			} )
		).toBeVisible();

		// Verify button should be visible (may be disabled for unsigned posts).
		await expect(
			page.locator( '.content-provenance-panel__actions button', {
				hasText: 'Verify',
			} )
		).toBeVisible();
	} );

	test( 'Editor sidebar does not show Content Provenance panel when experiment is off', async ( {
		admin,
		editor,
		page,
	} ) => {
		await enableExperiments( admin, page );
		await disableExperiment( admin, page, 'content-provenance' );

		// Create a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Content Provenance Disabled',
			content: 'This post tests the disabled state.',
		} );

		// The Content Provenance panel should not be present.
		await expect(
			page.locator( 'button.components-panel__body-toggle', {
				hasText: 'Content Provenance',
			} )
		).not.toBeVisible();
	} );

	test( 'Editor sidebar badge shows unsigned status for new post', async ( {
		admin,
		editor,
		page,
	} ) => {
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, 'content-provenance' );

		// Create and save a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Unsigned Badge',
			content: 'This post has not been signed.',
		} );
		await editor.saveDraft();

		// Open the panel.
		await openProvenancePanel( page );

		// Badge should show unsigned status.
		const badge = page.locator( '.content-provenance-badge' );
		await expect( badge ).toBeVisible();
		await expect(
			badge.locator( '.content-provenance-badge__label' )
		).toContainText( 'Not Signed' );

		// Verify button should be disabled for unsigned posts.
		const verifyButton = page.locator(
			'.content-provenance-panel__actions button',
			{ hasText: 'Verify' }
		);
		await expect( verifyButton ).toBeDisabled();
	} );

	test( 'Can sign a post and see updated status', async ( {
		admin,
		editor,
		page,
	} ) => {
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, 'content-provenance' );

		// Create and save a new post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Signing Flow',
			content: 'Content to be signed with C2PA provenance.',
		} );
		await editor.saveDraft();

		// Open the panel and wait for badge to load.
		await openProvenancePanel( page );

		// Click Sign Now.
		const signButton = page.locator(
			'.content-provenance-panel__actions button',
			{ hasText: 'Sign Now' }
		);
		await expect( signButton ).toBeVisible();
		await signButton.click();

		// After signing, the badge should update from unsigned.
		// Wait for the signing to complete (badge text changes).
		await expect(
			page.locator( '.content-provenance-badge__label' )
		).not.toContainText( 'Not Signed', { timeout: 10000 } );

		// The Verify button should now be enabled.
		const verifyButton = page.locator(
			'.content-provenance-panel__actions button',
			{ hasText: 'Verify' }
		);
		await expect( verifyButton ).toBeEnabled( { timeout: 5000 } );
	} );

	test( 'Can verify a signed post', async ( {
		admin,
		editor,
		page,
	} ) => {
		await enableExperiments( admin, page );
		await enableExperiment( admin, page, 'content-provenance' );

		// Create, save, and sign a post.
		await admin.createNewPost( {
			postType: 'post',
			title: 'Test Verify Flow',
			content: 'Content to be verified after signing.',
		} );
		await editor.saveDraft();

		// Open the panel and sign.
		await openProvenancePanel( page );
		const signButton = page.locator(
			'.content-provenance-panel__actions button',
			{ hasText: 'Sign Now' }
		);
		await signButton.click();

		// Wait for signing to complete.
		await expect(
			page.locator( '.content-provenance-badge__label' )
		).not.toContainText( 'Not Signed', { timeout: 10000 } );

		// Now click Verify.
		const verifyButton = page.locator(
			'.content-provenance-panel__actions button',
			{ hasText: 'Verify' }
		);
		await expect( verifyButton ).toBeEnabled( { timeout: 5000 } );
		await verifyButton.click();

		// A verification result notice should appear.
		await expect(
			page.locator( '.content-provenance-panel__notice' )
		).toBeVisible( { timeout: 10000 } );
	} );
} );
