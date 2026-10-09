/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import router from '../../router.js'

describe('compose route registration', () => {
	it('resolves the navigational POST path to the compose route', () => {
		const { route } = router.resolve('/compose')

		expect(route.name).toBe('compose')
		expect(route.matched).toHaveLength(1)
	})

	it('keeps the mailto route unchanged', () => {
		const { route } = router.resolve('/mailto')

		expect(route.name).toBe('mailto')
	})
})
