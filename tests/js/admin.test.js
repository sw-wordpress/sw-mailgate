/* Unit tests for assets/admin.js with a minimal DOM double (no browser needed). */
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');

const SCRIPT = path.resolve(__dirname, '../../assets/admin.js');

class FakeElement {
	constructor(tag) {
		this.tagName = tag.toUpperCase();
		this.attributes = {};
		this.children = [];
		this.listeners = {};
		this.dataset = {};
		this.className = '';
		this.textContent = '';
		this.focused = false;
	}
	setAttribute(name, value) { this.attributes[name] = String(value); }
	addEventListener(type, listener) { (this.listeners[type] ||= []).push(listener); }
	dispatch(type) {
		const event = { type, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
		(this.listeners[type] || []).forEach((listener) => listener(event));
		return event;
	}
	append(...nodes) { this.children.push(...nodes); }
	focus() { this.focused = true; }
}

class FakeDialog extends FakeElement {
	constructor() { super('dialog'); this.open = false; }
	showModal() { this.open = true; }
	close() { this.open = false; this.dispatch('close'); }
}

function helpBlock(label) {
	const details = new FakeElement('details');
	details.className = 'swmg-oauth-help';
	details.dataset.closeLabel = label;
	const summary = new FakeElement('summary');
	const content = new FakeElement('div');
	details.querySelector = (selector) => ({ summary, '.swmg-oauth-instructions': content }[selector]);
	return { details, summary, content };
}

/** Load admin.js into fresh globals and fire DOMContentLoaded. */
function load(blocks, { dialogSupported = true } = {}) {
	const documentListeners = {};
	const body = new FakeElement('body');
	global.document = {
		body,
		addEventListener(type, listener) { (documentListeners[type] ||= []).push(listener); },
		querySelectorAll(selector) {
			assert.equal(selector, '.swmg-oauth-help');
			return blocks.map((block) => block.details);
		},
		createElement(tag) { return 'dialog' === tag ? new FakeDialog() : new FakeElement(tag); },
	};
	if (dialogSupported) {
		global.HTMLDialogElement = FakeDialog;
	} else {
		delete global.HTMLDialogElement;
	}
	delete require.cache[SCRIPT];
	require(SCRIPT);
	assert.equal(documentListeners.DOMContentLoaded.length, 1);
	documentListeners.DOMContentLoaded[0]();
	return body;
}

test.afterEach(() => {
	delete global.document;
	delete global.HTMLDialogElement;
});

test('moves each setup guide into an accessible modal dialog', () => {
	const blocks = [helpBlock('Close'), helpBlock('Schließen')];
	const body = load(blocks);

	assert.equal(body.children.length, 2);
	blocks.forEach((block, index) => {
		const dialog = body.children[index];
		const [close, content] = dialog.children;
		assert.ok(dialog instanceof FakeDialog);
		assert.equal(dialog.className, 'swmg-oauth-dialog');
		assert.equal(dialog.attributes['aria-labelledby'], 'swmg-oauth-help-title');
		assert.equal(close.type, 'button');
		assert.equal(close.className, 'button swmg-oauth-close');
		assert.equal(close.textContent, block.details.dataset.closeLabel);
		assert.equal(content, block.content);
		assert.equal(block.summary.attributes['aria-haspopup'], 'dialog');
	});
});

test('summary opens the dialog instead of toggling details; close restores focus', () => {
	const block = helpBlock('Close');
	const dialog = load([block]).children[0];

	const event = block.summary.dispatch('click');
	assert.equal(event.defaultPrevented, true);
	assert.equal(dialog.open, true);

	dialog.children[0].dispatch('click');
	assert.equal(dialog.open, false);
	assert.equal(block.summary.focused, true);
});

test('keeps the inline guide when the browser lacks <dialog> support', () => {
	const block = helpBlock('Close');
	const body = load([block], { dialogSupported: false });

	assert.equal(body.children.length, 0);
	assert.deepEqual(block.summary.attributes, {});
	assert.equal(block.summary.listeners.click, undefined);
});

test('does nothing on pages without setup guides', () => {
	assert.equal(load([]).children.length, 0);
});
