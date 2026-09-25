import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

function loadTranscriptView() {
  const source = readFileSync(new URL('../public/assets/js/src/module-local-speech-to-text-conversation.js', import.meta.url), 'utf8');
  const writes = [];
  const $ = () => ({ each() { return this; } });
  const window = {
    location: { pathname: '/admin-cabinet/module-local-speech-to-text/module-local-speech-to-text/transcript', search: '?id=42', hash: '' },
    history: { state: null, replaceState(state, title, url) { writes.push(url); } },
  };
  vm.runInNewContext(source, { $, jQuery: $, window, URLSearchParams });
  return { view: window.SttTranscriptView, window, writes };
}

test('a shared transcript address carries the stage and second to hold', () => {
  const { view } = loadTranscriptView();
  assert.deepEqual({ ...view.location.read('?id=42&lstt_stage=43&lstt_t=83.9') }, { stage: '43', t: 83, leg: '' });
  assert.deepEqual({ ...view.location.read('?id=42&lstt_stage=43&lstt_t=5&lstt_leg=internal') }, { stage: '43', t: 5, leg: 'internal' });
  // A malformed moment never blocks the page itself.
  assert.deepEqual({ ...view.location.read('?id=42&lstt_stage=abc&lstt_t=5') }, { stage: '', t: 0, leg: '' });
  assert.deepEqual({ ...view.location.read('?id=42&lstt_stage=43&lstt_t=-1') }, { stage: '', t: 0, leg: '' });
  assert.deepEqual({ ...view.location.read('?id=42&lstt_stage=43') }, { stage: '', t: 0, leg: '' });
  assert.deepEqual({ ...view.location.read('?id=42&lstt_leg=other') }, { stage: '', t: 0, leg: '' });
});

test('links shared before the prefix still open on their moment and leg', () => {
  const { view } = loadTranscriptView();
  assert.deepEqual({ ...view.location.read('?id=42&stage=43&t=12&leg=internal') }, { stage: '43', t: 12, leg: 'internal' });
  // Prefixed names win when both forms are present.
  assert.deepEqual({ ...view.location.read('?id=42&stage=43&t=12&lstt_stage=44&lstt_t=3') }, { stage: '44', t: 3, leg: '' });
});

test('writing a moment uses the prefixed names, keeps the transcript id and rewrites legacy names', () => {
  const { view, writes } = loadTranscriptView();
  assert.equal(view.location.search('?id=42&lstt_stage=43&lstt_t=4', { stage: '44', t: 61.7 }), '?id=42&lstt_stage=44&lstt_t=61');
  assert.equal(view.location.search('?id=42&lstt_stage=43&lstt_t=4', { stage: '43', t: 0 }), '?id=42');
  assert.equal(view.location.search('?id=42', { leg: 'internal' }), '?id=42&lstt_leg=internal');
  assert.equal(view.location.search('?id=42&lstt_leg=internal&lstt_stage=43&lstt_t=9', { leg: 'customer', stage: '' }), '?id=42');
  // A legacy link keeps what it described and moves it to the prefixed names.
  assert.equal(view.location.search('?id=42&stage=43&t=9&leg=internal', { leg: 'internal' }), '?id=42&lstt_stage=43&lstt_t=9&lstt_leg=internal');
  assert.equal(view.location.search('?id=42&stage=43&t=9', { stage: '44', t: 2 }), '?id=42&lstt_stage=44&lstt_t=2');
  view.location.write({ stage: '43', t: 12 });
  assert.deepEqual(writes, ['/admin-cabinet/module-local-speech-to-text/module-local-speech-to-text/transcript?id=42&lstt_stage=43&lstt_t=12']);
});

test('only server-rendered transcript pages name a stage; the call-history modal view stays address-neutral', () => {
  const template = readFileSync(new URL('../App/Views/ModuleLocalSpeechToText/_transcript_stage.volt', import.meta.url), 'utf8');
  const source = readFileSync(new URL('../public/assets/js/src/module-local-speech-to-text-conversation.js', import.meta.url), 'utf8');
  assert.match(template, /class="stt-transcript-view" data-stt-stage="\{\{ stageResult\.id\|e \}\}"/);
  assert.doesNotMatch(source, /'data-stt-stage'\s*:/);
});
