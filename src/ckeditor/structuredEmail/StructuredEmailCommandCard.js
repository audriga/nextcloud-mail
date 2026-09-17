import { Command } from 'ckeditor5'
import hetc from 'hetc/hetc-bundle.mjs'

export default class InsertHtmlCard extends Command {
	refresh() {
		this.isEnabled = true
	}

	execute(jsonLd) {
		if (!jsonLd) {
			return
		}

        let finalJsonLd
        if(typeof jsonLd === 'string'){
            finalJsonLd = JSON.parse(jsonLd)
        } else {
            finalJsonLd = jsonLd
        }

		const cardHtml = hetc.renderLd(finalJsonLd)
		const containerHtml = `<div><script type="application/ld+json">${JSON.stringify(finalJsonLd)}</script></div>${cardHtml}<br>`

		const viewFragment = this.editor.data.processor.toView(containerHtml)
		const modelFragment = this.editor.data.toModel(viewFragment)

		this.editor.model.change(() => {
			this.editor.model.insertContent(modelFragment)
		})

		this.editor.editing.view.focus()
	}
}
