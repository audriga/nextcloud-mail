<!--
 -
 - @copyright Copyright (c) 2023, Gerke Frölje (gerke@audriga.com)
 -
 - @license GNU AGPL version 3 or any later version
 -
 - This program is free software: you can redistribute it and/or modify
 - it under the terms of the GNU Affero General Public License as
 - published by the Free Software Foundation, either version 3 of the
 - License, or (at your option) any later version.
 -
 - This program is distributed in the hope that it will be useful,
 - but WITHOUT ANY WARRANTY; without even the implied warranty of
 - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 - GNU Affero General Public License for more details.
 -
 - You should have received a copy of the GNU Affero General Public License
 - along with this program.  If not, see <https://www.gnu.org/licenses/>.
 -
 -->

<template>
	<div class="schema">
		<div v-html="html" />
		<type-renderer ref="rendererEl" :data="jsonldData"
        	:current-user="currentUser"
        />
		<div class="schema-action-bar">
			<SchemaActionBar @update-from-live-uri="updateData" />
		</div>
	</div>
</template>

<script>
import 'json-ld-web-components/type-renderer'
import { loadState } from '@nextcloud/initial-state'
import useMainStore from '../store/mainStore.js'
import SchemaActionBar from './SchemaActionBar.vue'


export default {
	name: 'Schema',
	components: {
		SchemaActionBar,
	},
	props: {
		json: {
			type: [Object, Array],
			required: false,
			default: null,
		},
		messageId: {
			type: String,
			required: false,
			default: null,
		},
	},
	data() {
		return {
			schema: '',
			html: '',
			isRequiredAppInstalled: null,
		}
	},
	computed: {
		jsonldData() {
			console.log(JSON.stringify(this.schema))
			return JSON.stringify(this.schema)
		},
		currentUser() {
            // 1. Core primary email address
            const userEmailFromCore = loadState('mail', 'prefill_email', '')

            // 2. Mail app accounts
            const store = useMainStore()
            const realAccounts = (store.getAccounts || []).filter((account) => !account.isUnified)

            // First registered account email
            const primaryMailAccountEmail = realAccounts[0]?.emailAddress ?? null

            // All registered mail account emails
            const allMailAccounts = realAccounts.map((account) => account.emailAddress)

            // console.log('userEmailFromCore:', userEmailFromCore)
            // console.log('primaryMailAccountEmail:', primaryMailAccountEmail)
            // console.log('allMailAccounts:', allMailAccounts)

            // Return whichever you need (or an object with both)
            return primaryMailAccountEmail || userEmailFromCore
        },
	},
	created() {
		// Decompose the schema object to see whether the app required
		// for button rendering is installed on the instance.
		const { isRequiredAppInstalled, ...otherProperties } = this.json

		this.schema = { ...otherProperties }
		this.isRequiredAppInstalled = { isRequiredAppInstalled }
	},
	methods: {
		async updateData(updatedValues) {
			for (const key in updatedValues) {
				if (Object.prototype.hasOwnProperty.call(this.schema, key)) {
					this.schema[key] = updatedValues[key]
				}
			}

		},
		appIsInstalled(appName) {
			return Object.prototype.hasOwnProperty.call(this.installedApps, appName)
		},
	},
}

</script>
<style scoped>
/* Default card styling */
.schema {

	/* Box surrounding the displayed card and posible actions. */
	display: flex;
	width: fit-content;
	flex-direction: column;
	margin: 50px;
	border: 2px none var(--color-border);
	border-radius: 16px;
	padding: 10px;
	align-items: left;

	box-shadow: 0px 0px 10px 0px var(--color-box-shadow);
}

.full-schema {

	/* Useful for debugging the component. */
	display: none;
	font-size: x-small;
	opacity: 0.4;
	font-weight: lighter;

}

</style>
