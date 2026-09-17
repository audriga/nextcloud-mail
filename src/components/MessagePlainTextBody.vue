<!--
  - SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div id="mail-content">
		<NeedsTranslationInfo
			v-if="needsTranslation"
			:is-html="false"
			@translate="$emit('translate')" />
		<MdnRequest :message="message" />
		<div id="message-container" v-html="nl2br(enhancedBody)" />
		<details v-if="signature" class="mail-signature">
			<summary v-html="nl2br(signatureSummaryAndBody.summary)" />
			<span v-html="nl2br(signatureSummaryAndBody.body)" />
		</details>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'
import MdnRequest from './MdnRequest.vue'
import NeedsTranslationInfo from './NeedsTranslationInfo.vue'
import { needsTranslation } from '../service/AiIntergrationsService.js'

const regFirstParagraph = /(.+\n\r?)+(\n\r?)+/

export default {
	name: 'MessagePlainTextBody',
	components: { MdnRequest, NeedsTranslationInfo },
	props: {
		body: {
			type: String,
			required: true,
		},

		signature: {
			type: String,
			default: () => undefined,
		},

		message: {
			required: true,
			type: Object,
		},
	},

	data() {
		return {
			needsTranslation: false,
			enabledFreePrompt: loadState('mail', 'llm_freeprompt_available', false),
		}
	},

	computed: {
		enhancedBody() {
			return this.body.replace(/(^&gt;.*\n)+/gm, (match) => {
				return `<details class="quoted-text"><summary>${t('mail', 'Quoted text')}</summary>${match}</details>`
			})
		},

		signatureSummaryAndBody() {
			const matches = this.signature.trim().match(regFirstParagraph)

			if (matches && matches[0]) {
				return {
					summary: matches[0],
					body: this.signature.substring(matches[0].length),
				}
			}

			const lines = this.signature.trim().split(/\r?\n/)
			return {
				summary: lines[0],
				body: lines.slice(1).join('\n'),
			}
		},

		signatureSummary() {
			return this.signatureSummaryAndBody.summary
		},
	},

	async mounted() {
		this.installLinkHoverHandlers()

		if (this.enabledFreePrompt && this.message) {
			this.needsTranslation = await needsTranslation(this.message.databaseId)
		}
	},
	    updated() {
        this.installLinkHoverHandlers()
    },

    beforeUnmount() {
        this.removeLinkHoverHandlers()
    },

	methods: {
		nl2br(str) {
			return str.replace(/(\r\n|\n\r|\n|\r)/g, '<br />')
		},
		installLinkHoverHandlers() {
            const container = this.$refs.messageContainer

            if (!container || container.dataset.linkHoverHandlersInstalled === 'true') {
                return
            }

            container.dataset.linkHoverHandlersInstalled = 'true'
            container.addEventListener('mouseover', this.onMessageMouseOver)
            container.addEventListener('mouseout', this.onMessageMouseOut)
        },

        removeLinkHoverHandlers() {
            const container = this.$refs.messageContainer

            if (!container) {
                return
            }

            container.removeEventListener('mouseover', this.onMessageMouseOver)
            container.removeEventListener('mouseout', this.onMessageMouseOut)
            delete container.dataset.linkHoverHandlersInstalled
        },

        onMessageMouseOver(event) {
            const link = event.target.closest?.('a[href]')

            if (!link || !this.$refs.messageContainer.contains(link)) {
                return
            }

            if (event.relatedTarget && link.contains(event.relatedTarget)) {
                return
            }

            this.$emit('link-hover', {
				href: link.href,
				rect: link.getBoundingClientRect(),
			})
        },

        onMessageMouseOut(event) {
            const link = event.target.closest?.('a[href]')

            if (!link) {
                return
            }

            if (event.relatedTarget && link.contains(event.relatedTarget)) {
                return
            }

            this.$emit('link-leave')
        },
	},
}
</script>

<style lang="scss">
.quoted-text {
	color: var(--color-text-maxcontrast);

	summary {
		cursor: pointer;
	}
}
</style>

<style lang="scss" scoped>
.message-container,
.mail-signature {
	white-space: pre-wrap;
}

.mail-signature, .quoted {
	color: var(--color-text-maxcontrast);

	summary {
		cursor: pointer;
	}
}
</style>
