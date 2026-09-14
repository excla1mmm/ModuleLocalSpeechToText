import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

function loadNavigation() {
  const source = readFileSync(new URL('../public/assets/js/src/module-local-speech-to-text-cdr.js', import.meta.url), 'utf8');
  let clickHandler = null;
  let detailRequests = 0;
  const openedTabs = [];

  function chain(state = {}) {
    state.attrs ||= {};
    state.data ||= {};
    return {
      length: state.length ?? 1,
      0: state,
      data(name, value) { if (value === undefined) return state.data[name]; state.data[name] = value; return this; },
      attr(name, value) { if (value === undefined) return state.attrs[name] || ''; state.attrs[name] = String(value); return this; },
      closest() { return this; }, find() { return chain(); }, get() { return null; },
      remove() { return this; }, removeAttr() { return this; }, addClass() { return this; }, removeClass() { return this; },
      text() { return this; }, empty() { return this; }, append() { return this; }, prepend() { return this; },
      modal() { return this; }, popup() { return this; }, one() { return this; },
      on(event, selector, handler) { if (event.startsWith('click.')) clickHandler = handler; return this; },
      DataTable() { return dataTable; },
    };
  }

  const table = chain();
  const modal = chain({ data: {
    'label-button': 'Open transcript', 'label-load-error': 'Load error',
    'label-not-found': 'Not found', 'label-empty': 'Empty',
  } });
  const rowData = ['14-09-2026 20:41:42', '201', '8943573457', '00:42', []];
  const dataTable = { row() { return { data() { return rowData; } }; } };
  const $ = (input) => {
    if (typeof input === 'function') { input(); return chain(); }
    if (input === '#cdr-table') return table;
    if (input === '#stt-cdr-transcript-modal') return modal;
    if (input && typeof input === 'object') return chain(input);
    return chain();
  };
  $.fn = { DataTable: { isDataTable() { return true; } } };
  $.ajax = () => {
    detailRequests += 1;
    const request = { done() { return request; }, fail() { return request; }, always() { return request; }, abort() {} };
    return request;
  };
  const window = {
    open(url, target) {
      const tab = { url, target, opener: window, focused: false, focus() { this.focused = true; } };
      openedTabs.push(tab);
      return tab;
    },
  };
  const context = {
    $, window, document: { querySelectorAll() { return []; } },
    globalRootUrl: '/admin-cabinet/', setTimeout() {},
  };
  vm.runInNewContext(source, context);
  return { clickHandler, detailRequests: () => detailRequests, openedTabs };
}

test('regular click keeps the modal while Ctrl or Command click focuses the transcript card', () => {
  const { clickHandler, detailRequests, openedTabs } = loadNavigation();
  assert.equal(typeof clickHandler, 'function');
  const button = {
    attrs: {
      'data-call-id': 'linked-call-id',
      href: '/admin-cabinet/module-local-speech-to-text/module-local-speech-to-text/transcript?id=42',
    },
  };
  const event = (modifiers = {}) => ({
    prevented: false, stopped: false,
    preventDefault() { this.prevented = true; }, stopPropagation() { this.stopped = true; },
    ...modifiers,
  });

  const regular = event();
  clickHandler.call(button, regular);
  assert.equal(detailRequests(), 1);
  assert.equal(regular.prevented, true);

  for (const modified of [event({ ctrlKey: true }), event({ metaKey: true })]) {
    clickHandler.call(button, modified);
    assert.equal(modified.prevented, true);
    assert.equal(modified.stopped, true);
  }
  assert.equal(detailRequests(), 1);
  assert.equal(openedTabs.length, 2);
  openedTabs.forEach((tab) => {
    assert.equal(tab.url, button.attrs.href);
    assert.equal(tab.target, '_blank');
    assert.equal(tab.opener, null);
    assert.equal(tab.focused, true);
  });
});
