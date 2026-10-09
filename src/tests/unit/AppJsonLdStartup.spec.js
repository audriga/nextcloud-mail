/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { loadState } from '@nextcloud/initial-state'
import { createPinia, setActivePinia } from 'pinia'
import App from '../../App.vue'
import router from '../../router.js'
import useMainStore from '../../store/mainStore.js'

vi.mock('@nextcloud/initial-state')
vi.mock('../../router.js', () => ({
	default: {
		currentRoute: { name: null, path: '/' },
		onReady: vi.fn(),
		replace: vi.fn().mockResolvedValue(undefined),
	},
}))

describe('JSON-LD compose app startup', () => {
	afterEach(() => {
		vi.restoreAllMocks()
		vi.clearAllMocks()
	})

	it('opens the POST composer after delayed initial navigation resolves', async () => {
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
		const initialState = {
			preferences: {},
			'account-settings': [],
			accounts: [{ accountId: 27, mailboxes: [] }],
			'outbox-messages': [],
			'compose-data': composeData,
		}
		loadState.mockImplementation((app, key, fallback) => key in initialState ? initialState[key] : fallback)
		setActivePinia(createPinia())
		const mainStore = useMainStore()
		const startComposerSession = vi.spyOn(mainStore, 'startComposerSession').mockResolvedValue(undefined)
		vi.spyOn(mainStore, 'fetchCurrentUserPrincipal').mockResolvedValue(undefined)
		vi.spyOn(mainStore, 'loadCollections').mockResolvedValue(undefined)
		const readyCallbacks = []
		let routerReady = false
		router.onReady.mockImplementation((callback) => {
			if (routerReady) {
				callback()
			} else {
				readyCallbacks.push(callback)
			}
		})

		const startup = App.mounted.call({
			mainStore,
			$router: router,
			hasMailAccounts: true,
			sync: vi.fn(),
		})
		await Promise.resolve()

		expect(mainStore.getAccount(27)).toBeDefined()
		expect(startComposerSession).not.toHaveBeenCalled()
		expect(router.replace).not.toHaveBeenCalled()

		router.currentRoute = { name: 'compose', path: '/compose' }
		routerReady = true
		readyCallbacks.forEach((callback) => callback())
		await startup

		expect(startComposerSession).toHaveBeenCalledTimes(1)
		expect(startComposerSession).toHaveBeenCalledWith({ data: composeData })
		expect(router.replace).toHaveBeenCalledTimes(1)
		expect(router.replace).toHaveBeenCalledWith({
			name: 'mailbox',
			params: { mailboxId: 'priority' },
		})
	})
})
