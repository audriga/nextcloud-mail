import { Plugin } from 'ckeditor5'
import RenderLinkPreview from './StructuredEmailCommandHover.js'
import InsertHtmlCard from './StructuredEmailCommandCard.js'

export default class StructuredEmailPlugin extends Plugin {
    constructor(editor) {
        super(editor)

        this.currentHoveredUrl = null
        this.hideTimeout = null
        this.onMouseOver = null
        this.onMouseOut = null
        this.onEditorMouseLeave = null
    }

    init() {
        const editor = this.editor

        editor.commands.add(
            'RenderLinkPreview',
            new RenderLinkPreview(editor),
        )

        editor.commands.add(
            'InsertHtmlCard',
            new InsertHtmlCard(editor),
        )

        const renderLinkPreviewCommand = editor.commands.get(
            'RenderLinkPreview',
        )

        renderLinkPreviewCommand.isEnabled = true

        const viewDocument = editor.editing.view.document

        this.onMouseOver = (event, data) => {
            const link = this.findAnchor(data.target)

            if (!link || !link.hasAttribute('href')) {
                return
            }

            const domEvent = data.domEvent
            const url = link.getAttribute('href')

            if (!url) {
                return
            }

            /*
             * Ignore mouseover events caused by moving between descendants
             * of the same anchor.
             */
            const relatedTarget = domEvent?.relatedTarget

            if (relatedTarget && this.isInsideSameLink(relatedTarget, link)) {
                return
            }

            if (this.currentHoveredUrl === url) {
                return
            }

            this.currentHoveredUrl = url

            const pointer = {
                clientX: domEvent?.clientX,
                clientY: domEvent?.clientY,
            }

            console.debug(
                '[StructuredEmailPlugin] Link entered',
                {
                    url,
                    pointer,
                },
            )

            this.clearHideTimeout()

            try {
                const result = editor.execute(
                    'RenderLinkPreview',
                    url,
                    pointer,
                )

                Promise.resolve(result).catch((error) => {
                    console.error(
                        '[StructuredEmailPlugin] Link preview failed',
                        error,
                    )
                })
            } catch (error) {
                console.error(
                    '[StructuredEmailPlugin] Link preview execution failed',
                    error,
                )
            }
        }

        this.onMouseOut = (event, data) => {
            const link = this.findAnchor(data.target)

            if (!link) {
                return
            }

            const relatedTarget = data.domEvent?.relatedTarget

            /*
             * If the pointer is moving from one child of the anchor to
             * another child, the link has not actually been left.
             */
            if (relatedTarget && this.isInsideSameLink(relatedTarget, link)) {
                return
            }

            console.debug(
                '[StructuredEmailPlugin] Link left',
                {
                    url: link.getAttribute('href'),
                    relatedTarget,
                },
            )

            this.currentHoveredUrl = null

            this.scheduleHidePreview(editor)
        }

        this.onEditorMouseLeave = () => {
            console.debug(
                '[StructuredEmailPlugin] Editing area left',
            )

            this.currentHoveredUrl = null
            this.scheduleHidePreview(editor)
        }

        viewDocument.on('mouseover', this.onMouseOver)
        viewDocument.on('mouseout', this.onMouseOut)
        viewDocument.on('mouseleave', this.onEditorMouseLeave)
    }

    findAnchor(target) {
        let current = target

        while (current) {
            if (current.name === 'a') {
                return current
            }

            current = current.parent
        }

        return null
    }

    isInsideSameLink(target, link) {
        let current = target

        while (current) {
            if (current === link) {
                return true
            }

            current = current.parent
        }

        return false
    }

    scheduleHidePreview(editor) {
        this.clearHideTimeout()

        this.hideTimeout = window.setTimeout(() => {
            const command = editor.commands.get('RenderLinkPreview')

            if (command) {
                command.closePreview()
            }
        }, 500)
    }

    clearHideTimeout() {
        if (this.hideTimeout !== null) {
            window.clearTimeout(this.hideTimeout)
            this.hideTimeout = null
        }
    }

    destroy() {
        console.debug(
            '[StructuredEmailPlugin] Destroying plugin',
        )

        this.clearHideTimeout()

        const command = this.editor.commands.get('RenderLinkPreview')

        if (command) {
            command.closePreview()
        }

        /*
         * CKEditor removes listeners registered through listenTo().
         * These explicit off() calls are retained for clarity and to
         * ensure cleanup even if the listener registration changes later.
         */
        const viewDocument = this.editor.editing.view.document

        if (this.onMouseOver) {
            viewDocument.off('mouseover', this.onMouseOver)
        }

        if (this.onMouseOut) {
            viewDocument.off('mouseout', this.onMouseOut)
        }

        if (this.onEditorMouseLeave) {
            viewDocument.off('mouseleave', this.onEditorMouseLeave)
        }

        this.onMouseOver = null
        this.onMouseOut = null
        this.onEditorMouseLeave = null
        this.currentHoveredUrl = null
    }
}