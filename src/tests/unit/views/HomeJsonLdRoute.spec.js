/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import Home from '../../../views/Home.vue'

describe('Home compose route separation', () => {
	it('does not run home or mailto routing when opened on the JSON-LD route', () => {
		const router = {
			replace: vi.fn(),
		}
		const mainStore = {
			getAccounts: [],
			getPreference: vi.fn().mockReturnValue('missing-mailbox'),
			getMailbox: vi.fn().mockReturnValue(undefined),
		}

		Home.created.call({
			$route: { name: 'compose', params: {}, query: {} },
			$router: router,
			mainStore,
		})

		expect(mainStore.getMailbox).toHaveBeenCalledWith('missing-mailbox')
		expect(router.replace).not.toHaveBeenCalled()
	})

	it('keeps the existing home and mailto routing branches', () => {
		const router = {
			replace: vi.fn(),
		}
		const homeStore = {
			getAccounts: [{ id: 1 }, { id: 2 }],
			getPreference: vi.fn().mockReturnValue('mailbox-1'),
			getMailbox: vi.fn().mockReturnValue({ databaseId: 'mailbox-1' }),
		}

		Home.created.call({
			$route: { name: 'home', params: {}, query: {} },
			$router: router,
			mainStore: homeStore,
		})

		expect(router.replace).toHaveBeenCalledWith({
			name: 'mailbox',
			params: { mailboxId: 'mailbox-1' },
		})

		router.replace.mockClear()
		const mailtoStore = {
			getAccounts: [{ id: 1 }],
			getPreference: vi.fn(),
			getMailboxes: vi.fn().mockReturnValue([{ id: 1, databaseId: 'mailbox-1' }]),
		}
		Home.created.call({
			$route: {
				name: 'mailto',
				params: {},
				query: { to: 'user@example.com' },
			},
			$router: router,
			mainStore: mailtoStore,
		})

		expect(router.replace).toHaveBeenCalledWith({
			name: 'message',
			params: { mailboxId: 'mailbox-1', threadId: 'mailto' },
			query: {
				to: 'user@example.com',
				cc: undefined,
				bcc: undefined,
				subject: undefined,
				body: undefined,
			},
		})
	})
})
