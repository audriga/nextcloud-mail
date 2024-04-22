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
		<div class="schema-action-bar">
			<SchemaActionBar @update-from-live-uri="updateData" />
		</div>
		<div class="full-schema">
			{{ json }}
		</div>
	</div>
</template>

<script>
import Jsonld2html from 'jsonld2html-cards'
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
	created() {
		// Decompose the schema object to see whether the app required
		// for button rendering is installed on the instance.
		const { isRequiredAppInstalled, ...otherProperties } = this.json

		this.schema = { ...otherProperties }
		this.isRequiredAppInstalled = { isRequiredAppInstalled }

		this.getRenderedSchema()
	},
	methods: {
		getRenderedSchema() {

			if (Array.isArray(this.json) && this.json.length === 1) {
				this.html = Jsonld2html.render(this.json[0], false)
			}

			const rendered = Jsonld2html.render(this.json, false)

			this.html = rendered

			return rendered
		},
		async updateData(updatedValues) {
			for (const key in updatedValues) {
				if (Object.prototype.hasOwnProperty.call(this.schema, key)) {
					this.schema[key] = updatedValues[key]
				}
			}

			this.getRenderedSchema()
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

.schema >>> .ld-card {

	max-width: 600px;

	/* round corners*/
	border: 2px solid var(--color-border);
	border-radius: 6px;

	/* padding in the card*/
	padding: 20px;

	background: var(--color-main-background);

}

.schema >>> .ld-card__row {

    /*Layout Settings*/

    /* declaring the card class to a flex-contatiner */
    display: flex;

    /* setting the alignment of the childs to vertical row layout*/
    flex-direction: row;

    /* the items in the container are able to wrap, works like a line break */
    flex-wrap: nowrap;

    /* align the items horizontally in the cointainer to left side (flex-start) */
    justify-content: flex-start;

    /* align the items vertically in the center */
    align-items: flex-start;

    /* positioning in a html document*/
    position: relative;

    /* maximal absoulute card width */
    max-width: 600px;

    /* maximal absolute card height */
    max-height: 150px;

	gap: 20px;

}

.schema >>> .ld-card__row .text_column {

	display: flex;

	/* setting the alignment of the childs to vertical row layout*/
	flex-direction: column;

	/* the items in the container are able to wrap, works like a line break */
	flex-wrap: nowrap;

	/* align the items horizontally in the cointainer to left side (flex-start) */
	justify-content: flex-start;

	/* align the items vertically in the center */
	align-items: flex-start;

	/* minimum height , same as the picture box*/
	min-height: 100px;
	max-height: 150px;

	flex-basis: 90%;

	/* this property is needed to make the truncating working for the child elements*/
	min-width: 0;

	margin-left: 20px

}

.schema >>> .ld-card__row .title {

	margin: 4px 0px;

	font-size: 20px;
	font-weight: bold;
	min-height: 20%;

	color: var(--color-main-text);

	/* settings for truncating single line text */
    text-overflow: ellipsis;
    overflow-x: auto;

}

.schema >>> .ld-card__row .content {

	margin-top: 4px;
	margin-bottom: 4px;

	font-size: 16px;

	color: var(--color-main-text);

	/* this is for truncating multiline texts*/
	display: -webkit-box;
	-webkit-box-orient: vertical;
	-webkit-line-clamp: 4;
	overflow: auto;

}

.schema >>> .ld-card__row .image_column {

	/* declaring the card class to a flex-contatiner */
	display: flex;

	/* align the items vertically in the center */
	align-items: center;

	/* align the items horizontally in the cointainer to center */
	justify-content: center;

	min-height: 100px;
	min-width: 100px;
	max-width: 100px;

	/* in case of bigger elements in the box, cut off the sides*/
	overflow: hidden;

}

.schema >>> .ld-card__row img {

	display: block;
	max-width: 100px;
	max-height: 100px;
	min-width: 100px;

}

.schema >>> br {
	display: none;
}

.schema >>> ld-card__header {
	display: none;
}

/* Flight Reservation Styling */
.schema >>> .tab {

display: flex;

flex-direction: row;

justify-content: flex-start;

overflow: hidden;

}

.schmea >>> .tab button {

	border-radius: 20px 20px 0px 0px;

}

.schema >>> .tab button:hover {
    background-color: #ddd;
}

.schema >>> .tab button.active {
    background-color: var(--color-main-background);
}

.schema >>> .ld-card .ld-card__row .smlCardFlightReservationTextColumn .tabcontent {
    display: none;
    padding: 3px 2px;
    flex-direction: row;

    /* the items in the container are able to wrap, works like a line break */
    flex-wrap: nowrap;

    /* align the items horizontally in the cointainer to left side (flex-start) */
    justify-content: space-evenly;

    /* align the items vertically in the center */
    align-items: center;

    /* initial/standard size of the text column (shrinkage still possible)*/
    flex-basis: 100%;

    /* minimum height , same as the picture box*/
    min-height: 150px;
    max-height: 150px;

    max-width: inherit;

}

.schema >>> .ld-card__row .smlCardFlightReservationTextColumn {

display: flex;

/* setting the alignment of the childs to vertical row layout*/
flex-direction: row;

/* the items in the container are able to wrap, works like a line break */
flex-wrap: nowrap;

/* align the items horizontally in the cointainer to left side (flex-start) */
justify-content: space-evenly;

/* align the items vertically in the center */
align-items: center;

/* initial/standard size of the text column (shrinkage still possible)*/
flex-basis: 100%;

/* minimum height , same as the picture box*/
min-height: 150px;
max-height: 150px;

/* this property is needed to make the truncating working for the child elements*/
min-width: 0;

}

.schema >>> .smlCardFlightReservationTextColumn .flightReservationFirstColumn{
    max-width: 180px;
    overflow-wrap: break-word;
    padding: 10px;
    text-align: right;
}

.schema >>> .smlCardFlightReservationTextColumn .flightReservationMidColumn{

display: flex;
flex-direction: column;

overflow-wrap: break-word;
padding: 10px;
/* align the items horizontally in the cointainer to left side (flex-start) */
justify-content: center;

/* align the items vertically in the center */
align-items: center;
}

.schema >>> .smlCardFlightReservationTextColumn .flightReservationLastColumn{
    max-width: 180px;
    overflow-wrap: break-word;
    padding: 10px;
}
</style>
