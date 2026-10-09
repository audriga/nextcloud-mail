/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createJsonLdComposeInitializer } from '../../../util/initializeJsonLdCompose.js'

describe('JSON-LD compose initialization', () => {
	it('opens one unsaved composer over the priority inbox', async () => {
		const composeData = {
			accountId: 27,
			to: [],
			cc: [],
			bcc: [],
			subject: '',
			isHtml: true,
			bodyHtml: '<script type="application/ld+json">{"@type":"Recipe"}</script><table></table>',
			bodyPlain: '',
			attachments: [],
		}
		const calls = []
		const mainStore = {
			getAccount: vi.fn().mockReturnValue({ accountId: 27 }),
			startComposerSession: vi.fn().mockImplementation(async () => calls.push('composer')),
		}
		const router = {
			currentRoute: { name: 'compose' },
			replace: vi.fn().mockImplementation(async () => calls.push('replace')),
		}
		const initialize = createJsonLdComposeInitializer()

		await expect(initialize({ composeData, mainStore, router })).resolves.toBe(true)
		await expect(initialize({ composeData, mainStore, router })).resolves.toBe(false)

		expect(mainStore.startComposerSession).toHaveBeenCalledTimes(1)
		expect(mainStore.startComposerSession).toHaveBeenCalledWith({ data: composeData })
		expect(composeData).not.toHaveProperty('id')
		expect(composeData).not.toHaveProperty('draftId')
		expect(router.replace).toHaveBeenCalledTimes(1)
		expect(router.replace).toHaveBeenCalledWith({
			name: 'mailbox',
			params: { mailboxId: 'priority' },
		})
		expect(calls).toEqual(['replace', 'composer'])
	})

	it.each(['home', 'mailto'])('ignores stale POST state on the %s route', async (routeName) => {
		const composeData = { accountId: 27 }
		const mainStore = {
			getAccount: vi.fn().mockReturnValue({ accountId: 27 }),
			startComposerSession: vi.fn(),
		}
		const router = {
			currentRoute: { name: routeName },
			replace: vi.fn(),
		}
		const initialize = createJsonLdComposeInitializer()

		await expect(initialize({ composeData, mainStore, router })).resolves.toBe(false)

		expect(mainStore.startComposerSession).not.toHaveBeenCalled()
		expect(router.replace).not.toHaveBeenCalled()
	})

	it('leaves the dedicated route alone when POST state is absent', async () => {
		const mainStore = {
			getAccount: vi.fn(),
			startComposerSession: vi.fn(),
		}
		const router = {
			currentRoute: { name: 'compose' },
			replace: vi.fn(),
		}
		const initialize = createJsonLdComposeInitializer()

		await expect(initialize({ composeData: null, mainStore, router })).resolves.toBe(false)

		expect(mainStore.startComposerSession).not.toHaveBeenCalled()
		expect(router.replace).not.toHaveBeenCalled()
	})

	it('does not navigate or open a composer if the account is unavailable', async () => {
		const mainStore = {
			getAccount: vi.fn().mockReturnValue(undefined),
			startComposerSession: vi.fn(),
		}
		const router = {
			currentRoute: { name: 'compose' },
			replace: vi.fn(),
		}
		const initialize = createJsonLdComposeInitializer()

		await expect(initialize({ composeData: { accountId: 27 }, mainStore, router })).resolves.toBe(false)

		expect(mainStore.startComposerSession).not.toHaveBeenCalled()
		expect(router.replace).not.toHaveBeenCalled()
	})

	it('continues opening the composer if client-side replacement is rejected', async () => {
		const composeData = { accountId: 27 }
		const mainStore = {
			getAccount: vi.fn().mockReturnValue({ accountId: 27 }),
			startComposerSession: vi.fn(),
		}
		const router = {
			currentRoute: { name: 'compose' },
			replace: vi.fn().mockRejectedValue(new Error('Navigation aborted')),
		}
		const initialize = createJsonLdComposeInitializer()

		await expect(initialize({ composeData, mainStore, router })).resolves.toBe(true)

		expect(mainStore.startComposerSession).toHaveBeenCalledWith({ data: composeData })
	})
})
