/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import hetc from 'hetc/hetc-bundle.mjs'

describe('MailboxThread handleMailto JSON-LD sessionStorage', () => {
	beforeEach(() => {
		sessionStorage.clear()
	})

	it('un-stages JSON-LD from sessionStorage by raw itemId and renders card', async () => {
		const itemId = 'recipe-item-123'
		const recipe = {
			'@context': 'https://schema.org',
			'@type': 'Recipe',
			name: 'Pancakes',
			description: 'Delicious pancakes',
		}
		sessionStorage.setItem(itemId, JSON.stringify({ jsonld: recipe }))

		const raw = sessionStorage.getItem(itemId)
		expect(raw).toBeDefined()
		sessionStorage.removeItem(itemId)
		expect(sessionStorage.getItem(itemId)).toBeNull()

		const parsed = JSON.parse(raw)
		const cardHtml = hetc.renderLd(parsed.jsonld)
		expect(cardHtml).toContain('Pancakes')
		expect(cardHtml).toContain('Delicious pancakes')
	})

	it('handles raw jsonld objects directly without wrapper', async () => {
		const itemId = 'event-item-456'
		const event = {
			'@context': 'https://schema.org',
			'@type': 'Place',
			name: 'Town Hall',
		}
		sessionStorage.setItem(itemId, JSON.stringify(event))

		const raw = sessionStorage.getItem(itemId)
		sessionStorage.removeItem(itemId)

		const parsed = JSON.parse(raw)
		const jsonldData = parsed?.jsonld !== undefined ? parsed.jsonld : parsed
		const cardHtml = hetc.renderLd(jsonldData)
		expect(cardHtml).toContain('Town Hall')
	})
})
