import { Command } from 'ckeditor5'
import Vue from 'vue'
import { renderWidget } from '@nextcloud/vue/dist/Components/NcRichText.js'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

export default class RenderLinkPreview extends Command {
    constructor(editor) {
        super(editor)

        this.previewVm = null
        this.previewContainer = null
        this.requestId = 0
        this.destroyed = false
    }

    async execute(url, pointer = {}) {
        console.debug('[RenderLinkPreview] execute()', {
            url,
            pointer,
            editorState: this.editor?.state,
            destroyed: this.destroyed,
        })

        if (this.destroyed || this.editor?.state === 'destroyed') {
            console.warn(
                '[RenderLinkPreview] Editor is destroyed; skipping preview',
            )
            return
        }

        if (!url) {
            console.warn('[RenderLinkPreview] No URL provided')
            return
        }

        /*
         * Force-close any previous preview. A new hover must always replace
         * the previous preview, even if the old popup is currently hovered.
         */
        this.closePreview(true)

        const currentRequestId = ++this.requestId

        console.debug('[RenderLinkPreview] Starting reference request', {
            url,
            requestId: currentRequestId,
        })

        const reference = await this._resolveReference(url)

        console.debug('[RenderLinkPreview] Resolved reference', {
            url,
            requestId: currentRequestId,
            reference,
            editorState: this.editor?.state,
            destroyed: this.destroyed,
        })

        /*
         * Do not mount a popup for an old request or after the editor has
         * been closed.
         */
        if (
            this.destroyed
            || this.editor?.state === 'destroyed'
            || currentRequestId !== this.requestId
        ) {
            console.debug(
                '[RenderLinkPreview] Ignoring stale or destroyed request',
                {
                    url,
                    requestId: currentRequestId,
                    currentRequestId: this.requestId,
                    editorState: this.editor?.state,
                    destroyed: this.destroyed,
                },
            )
            return
        }

        if (!reference) {
            console.debug(
                '[RenderLinkPreview] No reference resolved',
                {
                    url,
                    requestId: currentRequestId,
                },
            )
            return
        }

        const position = this._calculatePosition(pointer)

        console.debug('[RenderLinkPreview] Opening preview', {
            url,
            requestId: currentRequestId,
            position,
        })

        this._mountPreview(reference, url, position)
    }

    _mountPreview(reference, url, position) {
        if (
            this.destroyed
            || this.editor?.state === 'destroyed'
        ) {
            console.warn(
                '[RenderLinkPreview] Cannot mount preview: editor destroyed',
            )
            return
        }

        this.closePreview(true)

        const editor = this.editor
        const command = this

        const PreviewComponent = Vue.extend({
            data() {
                return {
                    visible: true,
                    closing: false,
                    reference,
                    url,
                    editor,
                    position,
                    hideTimeout: null,
                    pointerInsidePreview: false,
                }
            },

            render(h) {
                if (!this.visible) {
                    return h('div')
                }

                return h(
                    'div',
                    {
                        class: 'render-link-preview-popover',
                        attrs: {
                            role: 'dialog',
                            'aria-label': 'Link preview',
                        },
                        style: {
                            position: 'fixed',
                            left: `${Number(this.position.left)}px`,
                            top: `${Number(this.position.top)}px`,
                            zIndex: '10000',
                            width: '320px',
                            maxWidth: 'calc(100vw - 16px)',
                            maxHeight: '420px',
                            overflow: 'visible',
                            padding: '8px',
                            background: 'var(--color-main-background)',
                            border: '1px solid var(--color-border)',
                            borderRadius: 'var(--border-radius-large)',
                            boxShadow: '0 4px 16px rgb(0 0 0 / 25%)',
                            pointerEvents: 'auto',
                        },
                    },
                    [
                        /*
                         * No hit-testing role: updatePointerState() covers
                         * this zone via the padded bounding rect instead.
                         */
                        h(
                            'div',
                            {
                                style: {
                                    position: 'absolute',
                                    left: '-8px',
                                    right: '-8px',
                                    top: '-64px',
                                    height: '64px',
                                    zIndex: '1',
                                    pointerEvents: 'none',
                                },
                            },
                        ),

                        h(
                            'button',
                            {
                                class: 'render-link-preview-close',
                                attrs: {
                                    type: 'button',
                                    'aria-label': 'Close link preview',
                                    title: 'Close link preview',
                                },
                                style: {
                                    position: 'absolute',
                                    top: '4px',
                                    right: '6px',
                                    zIndex: '3',
                                    width: '28px',
                                    height: '28px',
                                    padding: '0',
                                    border: '0',
                                    borderRadius: '50%',
                                    background: 'transparent',
                                    color: 'var(--color-text-maxcontrast)',
                                    fontSize: '24px',
                                    lineHeight: '24px',
                                    cursor: 'pointer',
                                },
                                on: {
                                    click: (event) => {
                                        event.preventDefault()
                                        event.stopPropagation()

                                        console.debug(
                                            '[RenderLinkPreview] Close button clicked',
                                        )

                                        this.close()
                                    },
                                },
                            },
                            '×',
                        ),

                        h(
                            'div',
                            {
                                ref: 'widgetContainer',
                                class: 'render-link-preview-widget',
                                style: {
                                    position: 'relative',
                                    zIndex: '2',
                                    maxHeight: '350px',
                                    overflow: 'auto',
                                    paddingTop: '20px',
                                },
                            },
                        ),

                        h(
                            'div',
                            {
                                class: 'render-link-preview-actions',
                                style: {
                                    position: 'relative',
                                    zIndex: '2',
                                    display: 'flex',
                                    justifyContent: 'flex-end',
                                    paddingTop: '8px',
                                },
                            },
                            [
                                h(
                                    'button',
                                    {
                                        class: 'button primary',
                                        attrs: {
                                            type: 'button',
                                        },
                                        style: {
                                            cursor: 'pointer',
                                        },
                                        on: {
                                            click: (event) => {
                                                event.preventDefault()
                                                event.stopPropagation()

                                                console.debug(
                                                    '[RenderLinkPreview] Insert clicked',
                                                )

                                                this.insertReference()
                                            },
                                        },
                                    },
                                    'Insert',
                                ),
                            ],
                        ),
                    ],
                )
            },

            mounted() {
                console.debug(
                    '[RenderLinkPreview] Preview component mounted',
                    {
                        url: this.url,
                        reference: this.reference,
                    },
                )

                /*
                 * Track the real pointer position instead of relying on
                 * per-element mouseenter/mouseleave, which misfires across
                 * gaps, embedded iframes, and content reflows.
                 */
                this._handlePointerMove = (event) => {
                    this.updatePointerState(event)
                }

                this._handleWindowBlur = () => {
                    console.debug(
                        '[RenderLinkPreview] Window blurred; scheduling close',
                    )

                    this.pointerInsidePreview = false
                    this.scheduleClose()
                }

                document.addEventListener(
                    'mousemove',
                    this._handlePointerMove,
                    { passive: true },
                )
                window.addEventListener('blur', this._handleWindowBlur)

                this.$nextTick(() => {
                    if (!this.visible || this.closing) {
                        console.debug(
                            '[RenderLinkPreview] Skipping widget render: popup is closed',
                        )
                        return
                    }

                    if (
                        command.destroyed
                        || this.editor?.state === 'destroyed'
                    ) {
                        console.debug(
                            '[RenderLinkPreview] Skipping widget render: editor is destroyed',
                        )
                        return
                    }

                    if (!this.$refs.widgetContainer) {
                        console.warn(
                            '[RenderLinkPreview] Widget container is missing',
                        )
                        return
                    }

                    console.debug(
                        '[RenderLinkPreview] Rendering reference widget',
                        {
                            url: this.url,
                            reference: this.reference,
                        },
                    )

                    try {
                        renderWidget(
                            this.$refs.widgetContainer,
                            this.reference,
                        )
                    } catch (error) {
                        console.error(
                            '[RenderLinkPreview] Failed to render reference widget',
                            error,
                        )
                    }
                })
            },

            beforeDestroy() {
                console.debug(
                    '[RenderLinkPreview] Preview component beforeDestroy',
                    {
                        url: this.url,
                    },
                )

                this.clearHideTimeout()

                if (this._handlePointerMove) {
                    document.removeEventListener(
                        'mousemove',
                        this._handlePointerMove,
                    )
                    this._handlePointerMove = null
                }

                if (this._handleWindowBlur) {
                    window.removeEventListener('blur', this._handleWindowBlur)
                    this._handleWindowBlur = null
                }
            },

            methods: {
                updatePointerState(event) {
                    if (!this.visible || this.closing) {
                        return
                    }

                    if (
                        !this.$el
                        || typeof this.$el.getBoundingClientRect !== 'function'
                    ) {
                        return
                    }

                    const rect = this.$el.getBoundingClientRect()

                    /*
                     * Generous padding covers the gap between the trigger
                     * point and the popup, and absorbs small pointer jitter
                     * without needing a separate hit-test element.
                     */
                    const buffer = 64

                    const withinPaddedRect = (
                        event.clientX >= rect.left - buffer
                        && event.clientX <= rect.right + buffer
                        && event.clientY >= rect.top - buffer
                        && event.clientY <= rect.bottom + buffer
                    )

                    if (withinPaddedRect) {
                        this.pointerInsidePreview = true
                        this.clearHideTimeout()
                    } else {
                        this.pointerInsidePreview = false
                        this.scheduleClose()
                    }
                },

                insertReference() {
                    console.debug(
                        '[RenderLinkPreview] Insert button clicked',
                        {
                            url: this.url,
                            reference: this.reference,
                            editorState: this.editor?.state,
                            commandDestroyed: command.destroyed,
                        },
                    )

                    const jsonLd = this.reference?.richObject?.jsonLd
                        ?? this.reference?.richObject?.jsonld
                        ?? null

                    /*
                     * Close before changing the editor model or selection.
                     */
                    this.close()

                    if (
                        command.destroyed
                        || this.editor?.state === 'destroyed'
                    ) {
                        console.warn(
                            '[RenderLinkPreview] Editor is destroyed; insertion skipped',
                        )
                        return
                    }

                    if (!jsonLd) {
                        console.warn(
                            '[RenderLinkPreview] No jsonLd found to insert',
                            {
                                reference: this.reference,
                            },
                        )
                        return
                    }

                    try {
                        console.debug(
                            '[RenderLinkPreview] Executing InsertHtmlCard',
                            {
                                jsonLd,
                            },
                        )

                        this.editor.execute(
                            'InsertHtmlCard',
                            jsonLd,
                        )
                    } catch (error) {
                        console.error(
                            '[RenderLinkPreview] InsertHtmlCard failed',
                            error,
                        )
                    }
                },

                scheduleClose() {
                    this.clearHideTimeout()

                    this.hideTimeout = window.setTimeout(() => {
                        console.debug(
                            '[RenderLinkPreview] Close timeout fired',
                            {
                                pointerInsidePreview: this.pointerInsidePreview,
                                visible: this.visible,
                            },
                        )

                        if (!this.pointerInsidePreview) {
                            command.closePreview()
                        }
                    }, 500)
                },

                clearHideTimeout() {
                    if (this.hideTimeout !== null) {
                        window.clearTimeout(this.hideTimeout)
                        this.hideTimeout = null
                    }
                },

                close() {
                    if (this.closing) {
                        return
                    }

                    console.debug(
                        '[RenderLinkPreview] Closing preview component',
                        {
                            url: this.url,
                        },
                    )

                    this.closing = true
                    this.clearHideTimeout()
                    this.visible = false
                    this.$destroy()
                },
            },
        })

        this.previewContainer = document.createElement('div')
        this.previewContainer.className = 'render-link-preview-container'
        document.body.appendChild(this.previewContainer)

        const previewVm = new PreviewComponent().$mount(
            this.previewContainer,
        )
        this.previewVm = previewVm

        previewVm.$on('hook:destroyed', () => {
            console.debug(
                '[RenderLinkPreview] Removing preview container',
                {
                    url,
                },
            )

            previewVm.$el?.remove()

            previewVm.$off()

            if (this.previewVm === previewVm) {
                this.previewVm = null
                this.previewContainer = null
            }
        })
    }

    isPointerInsidePreview() {
        return Boolean(
            this.previewVm
            && this.previewVm.pointerInsidePreview,
        )
    }

    closePreview(force = false) {
        if (
            !force
            && this.isPointerInsidePreview()
        ) {
            console.debug(
                '[RenderLinkPreview] closePreview ignored: pointer is inside popup',
            )
            return
        }

        /*
         * Invalidate pending requests. A late response must not mount a
         * popup after this close.
         */
        this.requestId++

        if (!this.previewVm) {
            return
        }

        console.debug('[RenderLinkPreview] closePreview()', {
            force,
        })

        this.previewVm.close()
    }

    destroy() {
        console.debug('[RenderLinkPreview] destroy()')

        this.destroyed = true
        this.requestId++

        /*
         * Force cleanup even if the pointer happens to be inside the popup.
         */
        this.closePreview(true)

        super.destroy()
    }

    _calculatePosition(pointer = {}) {
        const margin = 8
        const width = 320
        const estimatedHeight = 360

        let left = Number.isFinite(pointer.clientX)
            ? pointer.clientX + 12
            : window.innerWidth / 2 - width / 2

        let top = Number.isFinite(pointer.clientY)
            ? pointer.clientY + 12
            : window.innerHeight / 2 - estimatedHeight / 2

        if (left + width > window.innerWidth - margin) {
            left = window.innerWidth - width - margin
        }

        if (top + estimatedHeight > window.innerHeight - margin) {
            top = Number.isFinite(pointer.clientY)
                ? pointer.clientY - estimatedHeight - 12
                : window.innerHeight - estimatedHeight - margin
        }

        const position = {
            left: Math.max(margin, left),
            top: Math.max(margin, top),
        }

        console.debug('[RenderLinkPreview] Calculated position', {
            pointer,
            position,
        })

        return position
    }

    async _resolveReference(url) {
        if (!url) {
            console.error(
                '[RenderLinkPreview] No URL provided to resolve',
            )
            return null
        }

        try {
            const ocsUrl = generateOcsUrl('references/resolve', 2)

            console.debug(
                '[RenderLinkPreview] Resolving reference',
                {
                    ocsUrl,
                    url,
                },
            )

            const response = await axios.get(ocsUrl, {
                params: {
                    reference: url,
                },
            })

            console.debug(
                '[RenderLinkPreview] Reference API response',
                {
                    url,
                    response: response.data,
                },
            )

            const reference
                = response.data?.ocs?.data?.references?.[url] ?? null

            console.debug(
                '[RenderLinkPreview] Extracted reference',
                {
                    url,
                    reference,
                },
            )

            return reference
        } catch (error) {
            console.error(
                '[RenderLinkPreview] Failed to resolve reference',
                {
                    url,
                    error,
                },
            )

            return null
        }
    }
}