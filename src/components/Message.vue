<!--
  - SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div
		:class="[message.hasHtmlBody ? 'mail-message-body mail-message-body-html' : 'mail-message-body']"
		role="region"
		:aria-label="t('mail', 'Message body')">
		<PhishingWarning v-if="message.phishingDetails.warning" :phishing-data="message.phishingDetails.checks" />
		<div
			v-if="message.smime.isSigned && !message.smime.signatureIsValid"
			class="invalid-signature-warning">
			<LockOffIcon
				:size="20"
				fill-color="red"
				class="invalid-signature-warning__icon" />
			<p>
				{{ t('mail', 'Warning: The S/MIME signature of this message is  unverified. The sender might be impersonating someone!') }}
			</p>
		</div>
		<div v-if="Object.entries(schema).length > 0" class="message-schema">
			<Schema :json="schema" :message-id="message.messageId" />
		</div>
		<div v-if="message.scheduling.length > 0" class="message-imip">
			<Imip v-for="scheduling in message.scheduling"
				:key="scheduling.id"
				:scheduling="scheduling" />
		</div>
		<MessageHTMLBody
			v-if="message.hasHtmlBody"
			:url="htmlUrl"
			:message="message"
			:full-height="fullHeight"
			@load="$emit('load', $event)"
			@translate="$emit('translate')" />
		<MessageEncryptedBody
			v-else-if="isEncrypted || isPgpMimeEncrypted"
			:body="message.body"
			:from="from"
			:message="message" />
		<MessagePlainTextBody
			v-else
			:body="message.body"
			:signature="message.signature"
			:message="message"
			@translate="$emit('translate')" />
		<MessageAttachments :attachments="message.attachments" :envelope="envelope" />
		<div id="reply-composer" />
		<div class="reply-buttons">
			<div v-if="smartReplies.length > 0" class="reply-buttons__suggested">
				<NcPopover trigger="hover focus">
					<template #trigger>
						<NcButton
							variant="tertiary-no-background"
							:aria-label="t('mail', 'AI info')"
							class="ai-button">
							<template #icon>
								<IconInfo :size="20" />
							</template>
						</NcButton>
					</template>
					<p class="smart-replies-info">
						{{ aiInfo }}
					</p>
				</NcPopover>
				<NcAssistantButton
					v-for="(reply, index) in smartReplies"
					:key="index"
					class="reply-buttons__suggested__button"
					type="secondary"
					@click="onReply(reply)">
					{{ reply }}
				</NcAssistantButton>
			</div>
			<NcButton
				variant="primary"
				class="reply-buttons__notsuggested"
				@click="onReply('')">
				<template #icon>
					<ReplyIcon />
				</template>
				{{ replyButtonLabel }}
			</NcButton>
		</div>
		<NcPopover
            ref="referenceWidgetPopover"
            :shown="referenceWidgetVisible"
            :no-focus-trap="true"
            :triggers="[]"
            placement="bottom-start"
            @update:shown="onReferenceWidgetShownChange">
            <template #trigger>
                <span
                    class="reference-widget-anchor"
                    :style="referenceWidgetAnchorStyle" />
            </template>

            <div
                class="reference-widget-popover__inner"
                @pointerenter="onReferenceWidgetPointerEnter"
                @pointerleave="onReferenceWidgetPointerLeave">
                <button
                    type="button"
                    class="reference-widget-popover__close"
                    :aria-label="t('mail', 'Close link preview')"
                    :title="t('mail', 'Close link preview')"
                    @click.stop.prevent="closeReferenceWidget(true)">
                    ×
                </button>

                <div class="reference-widget-popover__content">
                    <NcReferenceWidget
                        v-if="reference"
                        :reference="reference" />

                    <p
                        v-else-if="referenceResolving"
                        class="reference-widget-status">
                        {{ t('mail', 'Loading link preview…') }}
                    </p>

                    <p
                        v-else
                        class="reference-widget-status">
                        {{ t('mail', 'No preview is available for this link.') }}
                    </p>
                </div>
            </div>
        </NcPopover>
	</div>
</template>

<script>
import { generateUrl, generateOcsUrl } from '@nextcloud/router'
import { NcAssistantButton, NcButton, NcPopover } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import {
    NcAssistantButton,
    NcButton,
    NcPopover,
} from '@nextcloud/vue'
import { NcReferenceWidget } from '@nextcloud/vue/dist/Components/NcRichText.js'
import { mapStores } from 'pinia'
import IconInfo from 'vue-material-design-icons/InformationOutline.vue'
import LockOffIcon from 'vue-material-design-icons/LockOffOutline.vue'
import ReplyIcon from 'vue-material-design-icons/ReplyOutline.vue'
import Imip from './Imip.vue'
import Itinerary from './Itinerary.vue'
import Schema from './Schema.vue'
import MessageAttachments from './MessageAttachments.vue'
import MessageEncryptedBody from './MessageEncryptedBody.vue'
import MessageHTMLBody from './MessageHTMLBody.vue'
import MessagePlainTextBody from './MessagePlainTextBody.vue'
import PhishingWarning from './PhishingWarning.vue'
import { isPgpgMessage } from '../crypto/pgp.js'
import useMainStore from '../store/mainStore.js'
import { html, plain } from '../util/text.js'

export default {
	name: 'Message',
	components: {
		IconInfo,
		Schema,
		MessageAttachments,
		MessageEncryptedBody,
		MessageHTMLBody,
		MessagePlainTextBody,
		PhishingWarning,
		Imip,
		LockOffIcon,
		ReplyIcon,
		NcButton,
		NcAssistantButton,
		NcPopover,
        NcReferenceWidget,
		NcPopover,
	},

	props: {
		envelope: {
			required: true,
			type: Object,
		},

		message: {
			required: true,
			type: Object,
		},

		fullHeight: {
			required: false,
			type: Boolean,
			default: false,
		},

		smartReplies: {
			required: false,
			type: Array,
			default: () => [],
		},

		replyButtonLabel: {
			required: true,
			type: String,
		},
	},
	data() {
        return {
            aiInfo: t('mail', 'Suggested replies are using AI'),

            referenceWidgetVisible: false,
            referenceWidgetHref: '',
            reference: null,
            referenceResolving: false,

            referenceWidgetAnchorRect: null,
            referenceRequestId: 0,
            referenceWidgetMouseInside: false,
            pointerInsideSourceLink: false,
            hideReferenceWidgetTimeout: null,
        }
    },

	data() {
		return {
			aiInfo: t('mail', 'Suggested replies are using AI'),
		}
	},

	computed: {
		...mapStores(useMainStore),
		from() {
			return this.message.from.length === 0 ? '?' : this.message.from[0].label || this.message.from[0].email
		},

		htmlUrl() {
			return generateUrl('/apps/mail/api/messages/{id}/html', {
				id: this.envelope.databaseId,
			})
		},

		isEncrypted() {
			return isPgpgMessage(this.message.hasHtmlBody ? html(this.message.body) : plain(this.message.body))
		},

		isPgpMimeEncrypted() {
			return this.message.isPgpMimeEncrypted
		},

		itineraries() {
			return this.message.itineraries ?? {}
		},

		hasCurrentUserPrincipalAndCollections() {
			return this.mainStore.hasCurrentUserPrincipalAndCollections
		},
		schema() {
			return this.message.schema ?? {}
		},
		        // Invisible anchor NcPopover attaches to; floating-vue handles placement/flipping/repositioning.
        referenceWidgetAnchorStyle() {
            const rect = this.referenceWidgetAnchorRect

            if (!rect) {
                return { left: '0px', top: '0px', width: '0px', height: '0px' }
            }

            return {
                left: `${rect.left}px`,
                top: `${rect.top}px`,
                width: `${Math.max(0, rect.right - rect.left)}px`,
                height: `${Math.max(0, rect.bottom - rect.top)}px`,
            }
        },
	},
	beforeUnmount() {
        this.clearHideReferenceWidgetTimeout()
        this.closeReferenceWidget(true)
    },

	methods: {
		onReply(replyBody) {
			this.$emit('reply', replyBody)
		},
	

        async onLinkHover(linkData) {
            const href = typeof linkData === 'string'
                ? linkData
                : linkData?.href

            const rect = typeof linkData === 'string'
                ? null
                : linkData?.rect

            if (!href) {
                return
            }

            this.clearHideReferenceWidgetTimeout()

            this.referenceWidgetAnchorRect = rect
            this.referenceWidgetHref = href
            this.referenceWidgetVisible = true
            this.referenceResolving = true
            this.reference = null
            this.pointerInsideSourceLink = true

            this.$nextTick(() => {
                this.$refs.referenceWidgetPopover?.$refs?.popover?.onResize?.()
            })

            const requestId = ++this.referenceRequestId

            try {
                const response = await axios.get(
                    generateOcsUrl('references/resolve', 2),
                    {
                        params: {
                            reference: href,
                        },
                    },
                )

                if (requestId !== this.referenceRequestId) {
                    return
                }

                this.reference
                    = response.data?.ocs?.data?.references?.[href] ?? null
            } catch (error) {
                if (requestId !== this.referenceRequestId) {
                    return
                }

                console.error(
                    '[Message] Failed to resolve link reference',
                    error,
                )

                this.reference = null
            } finally {
                if (requestId === this.referenceRequestId) {
                    this.referenceResolving = false
                }
            }
        },

        onLinkLeave() {
            this.pointerInsideSourceLink = false
            this.scheduleHideReferenceWidget()
        },

        onReferenceWidgetPointerEnter() {
            this.referenceWidgetMouseInside = true
            this.clearHideReferenceWidgetTimeout()
        },

        onReferenceWidgetPointerLeave() {
            this.referenceWidgetMouseInside = false
            this.scheduleHideReferenceWidget()
        },

        onReferenceWidgetShownChange(shown) {
            if (!shown) {
                this.closeReferenceWidget(true)
            }
        },

        scheduleHideReferenceWidget() {
            this.clearHideReferenceWidgetTimeout()

            this.hideReferenceWidgetTimeout = window.setTimeout(() => {
                if (
                    !this.referenceWidgetMouseInside
                    && !this.pointerInsideSourceLink
                ) {
                    this.closeReferenceWidget()
                }
            }, 500)
        },

        clearHideReferenceWidgetTimeout() {
            if (this.hideReferenceWidgetTimeout !== null) {
                window.clearTimeout(this.hideReferenceWidgetTimeout)
                this.hideReferenceWidgetTimeout = null
            }
        },

        closeReferenceWidget(force = false) {
            if (
                !force
                && (
                    this.referenceWidgetMouseInside
                    || this.pointerInsideSourceLink
                )
            ) {
                return
            }

            this.clearHideReferenceWidgetTimeout()

            this.referenceRequestId++
            this.referenceWidgetVisible = false
            this.referenceWidgetHref = ''
            this.reference = null
            this.referenceResolving = false
            this.referenceWidgetMouseInside = false
            this.pointerInsideSourceLink = false
            this.referenceWidgetAnchorRect = null
        },

	},
}
</script>

<style lang="scss" scoped>
@use '../../css/variables.scss';

.v-popover > .trigger > .action-item {
	border-radius: 22px;
	background-color: var(--color-background-darker);
}

.message-imip {
	padding: 5px 10px;
	margin-inline-start: calc(var(--default-grid-baseline) * 11);
}

@media (max-width: 600px) {
	.message-imip {
		margin-inline-start: 0;
	}
}

.invalid-signature-warning {
	display: flex;
	align-items: center;
	gap: 5px;

	border: solid 2px var(--color-border);
	border-radius: var(--border-radius-large);
	border-color: var(--color-warning);

	margin: 5px 10px;
	padding: 10px;

	&__icon {
		// Fix alignment with message
		margin-top: -5px;
	}
}

.reference-widget-anchor {
    position: fixed;
    /* Zero-size, invisible: only used by NcPopover/floating-vue to compute placement. */
    pointer-events: none;
}

.reference-widget-popover__inner {
    position: relative;
    box-sizing: border-box;
    width: 320px;
    max-width: calc(100vw - 16px);
    max-height: min(420px, calc(100vh - 16px));
    overflow: hidden;
    padding: 8px;
    background: var(--color-main-background);
    border-radius: var(--border-radius-large);
}

.reference-widget-popover__content {
    max-height: min(360px, calc(100vh - 90px));
    overflow: auto;
    pointer-events: auto;
}

.reference-widget-popover__close {
    position: absolute;
    top: 4px;
    right: 6px;
    z-index: 2;
    width: 28px;
    height: 28px;
    padding: 0;
    border: 0;
    border-radius: 50%;
    background: transparent;
    color: var(--color-text-maxcontrast);
    font-size: 24px;
    line-height: 24px;
    cursor: pointer;
    pointer-events: auto;
}

.reference-widget-popover__close:hover {
    background: var(--color-background-hover);
    color: var(--color-main-text);
}

.reference-widget-status {
    margin: 8px;
    color: var(--color-text-maxcontrast);
}

.reply-buttons {
	margin: 5px calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 3) calc(var(--default-grid-baseline) * 14);
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	justify-content: space-between;
	align-items: center;

	&__suggested {
		display: flex;
		flex-wrap: wrap;
		gap: 8px;

		&__button {
			box-sizing: border-box;

			:deep(.button-vue__text) {
				font-weight: normal;
			}
		}
	}

	&__notsuggested {
		margin-inline-start: auto;
	}

	@media (max-width: #{variables.$breakpoint-mobile}) {
        margin-inline: calc(var(--default-grid-baseline) * 3);
    }
}

@media screen and (max-width: #{variables.$breakpoint-mobile}) {
	.reply-buttons {
		display: flex;
		flex-wrap: wrap;
		gap: 5px;

		&__suggested {
			display: flex;
			flex-wrap: wrap;
			gap: 5px;
		}

		&__notsuggested {
			margin-inline-start: 0;
		}
	}
}

.smart-replies-info {
	padding: 15px;
}
</style>
