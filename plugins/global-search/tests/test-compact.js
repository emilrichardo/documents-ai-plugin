/** No browser/build dependency: execute the shipped script against a compact root. */
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/global-search.js'), 'utf8');
let calls = 0;
let passed = 0;
for (const liveSearch of [true, false]) {
  const root = {dataset: {variant: 'compact'}, querySelector() { calls++; throw Error('compact queried results DOM'); }};
  const sandbox = {
    window: {globalSearchConfig: {liveSearch, restUrl: '/wp-json/global-search/v1/'}},
    document: {readyState: 'complete', querySelectorAll() {return [root];}},
    fetch() {calls++; throw Error('compact fetched REST');},
    setTimeout() {calls++; throw Error('compact created debounce');},
  };
  vm.runInNewContext(source, sandbox);
  assert.equal(calls, 0, 'no REST, DOM binding or debounce for compact');
  passed++;
}
console.log(`${passed} passed, 0 failed`);
