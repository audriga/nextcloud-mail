/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import logger from '../logger.js'
import { PRIORITY_INBOX_ID } from '../store/constants.js'

/**
 * @typedef {object} JsonLdComposeInitializerOptions
 * @property {object | null} composeData Request-local initial state, or null when absent.
 * @property {object} mainStore Mail's initialized Pinia store.
 * @property {object} router Mail's Vue Router instance.
 */

/**
 * Start the unsaved composer initialized by a navigational JSON-LD POST.
 *
 * @return {function(JsonLdComposeInitializerOptions): Promise<boolean>}
 */
export function createJsonLdComposeInitializer() {
	let initialized = false

	return async ({ composeData, mainStore, router }) => {
		if (initialized || router.currentRoute.name !== 'compose' || !composeData) {
			return false
		}

		if (!Number.isInteger(composeData.accountId)
			|| composeData.accountId <= 0
			|| typeof mainStore.getAccount !== 'function'
			|| !mainStore.getAccount(composeData.accountId)) {
			return false
		}

		initialized = true
		try {
			await router.replace({
				name: 'mailbox',
				params: { mailboxId: PRIORITY_INBOX_ID },
			})
		} catch (error) {
			logger.error('Could not leave the JSON-LD compose route', { error })
		}
		await mainStore.startComposerSession({ data: composeData })

		return true
	}
}
