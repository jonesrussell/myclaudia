# Chat Thinking Indicator Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add CSS-animated thinking indicator and streaming cursor to the dashboard chat UI.

**Architecture:** Replace the static `.loading` div with two CSS-only animations: a shimmer-text thinking bubble that appears in the message flow during the pre-stream wait, and a pulsing dot pseudo-element on assistant messages during token streaming. All changes are in `templates/dashboard.twig` (CSS + JS + HTML in one file).

**Tech Stack:** CSS `@keyframes`, vanilla JS DOM manipulation, Twig template

**Spec:** `docs/superpowers/specs/2026-03-10-chat-thinking-indicator-design.md`

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `templates/dashboard.twig` | Modify | All changes: CSS animations, JS helpers, HTML cleanup |

No new files. No backend changes. No test files (this is a frontend-only CSS/JS change in a Twig template with no existing frontend test harness).

---

## Chunk 1: CSS Animations and Cleanup

### Task 1: Add shimmer and pulse keyframes + thinking indicator styles

**Files:**
- Modify: `templates/dashboard.twig:312-319` (replace `.loading` CSS block)

- [ ] **Step 1: Remove old `.loading` CSS rules**

Replace lines 312-319 in the `{% block styles %}` section:

```css
/* REMOVE THIS: */
.loading {
    display: none;
    padding: 0.5rem 1.25rem;
    color: #888;
    font-style: italic;
    font-size: 0.85rem;
}
.loading.visible { display: block; }
```

- [ ] **Step 2: Add keyframes and thinking indicator CSS**

In the same location where `.loading` was removed, add:

```css
/* ── Thinking Indicator ── */
@keyframes shimmer {
    0% { background-position: -200% center; }
    100% { background-position: 200% center; }
}
.thinking-indicator .message-content {
    background: linear-gradient(90deg, #888 25%, #ccc 50%, #888 75%);
    background-size: 200% auto;
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
    animation: shimmer 2s linear infinite;
    font-style: italic;
}

/* ── Streaming Cursor ── */
@keyframes pulse-dot {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.2; }
}
.message.assistant.streaming .message-content::after {
    content: ' \25CF';
    animation: pulse-dot 1s ease-in-out infinite;
    font-size: 0.7em;
    color: #888;
}
```

- [ ] **Step 3: Verify CSS is syntactically valid**

Open the dashboard in a browser and confirm no rendering errors. The new styles won't visually appear yet (no JS wiring), but existing styles should be unbroken.

- [ ] **Step 4: Commit**

```bash
git add templates/dashboard.twig
git commit -m "feat: add CSS keyframes for thinking shimmer and streaming pulse"
```

---

## Chunk 2: Replace Loading System with Thinking Indicator

### Task 2: Remove loading div/JS and add thinking indicator helpers

All loading-related HTML, CSS references, and JS are removed in one atomic step, replaced by the new thinking indicator system.

**Files:**
- Modify: `templates/dashboard.twig:523` (HTML section), JS `<script>` block

- [ ] **Step 1: Remove the loading div from HTML**

Delete this line from the HTML:

```html
<div class="loading" id="loading">Claudriel is thinking...</div>
```

- [ ] **Step 2: Remove loadingEl variable declaration from JS**

Remove this line from the variable declarations near the top of the script:

```js
var loadingEl = document.getElementById('loading');
```

- [ ] **Step 3: Add showThinking() and removeThinking() helpers**

Add these two functions right after the `esc()` function (after line 588):

```js
// ── Thinking Indicator ──
function showThinking() {
    if (emptyState) emptyState.style.display = 'none';
    var div = document.createElement('div');
    div.className = 'message assistant thinking-indicator';
    var label = document.createElement('div');
    label.className = 'message-label';
    label.textContent = 'Claudriel';
    div.appendChild(label);
    var content = document.createElement('div');
    content.className = 'message-content';
    content.textContent = 'Claudriel is thinking...';
    div.appendChild(content);
    messagesEl.appendChild(div);
    messagesEl.scrollTop = messagesEl.scrollHeight;
    return div;
}

function removeThinking() {
    var el = messagesEl.querySelector('.thinking-indicator');
    if (el) el.remove();
}
```

- [ ] **Step 4: Replace loadingEl.classList.add('visible') in sendMessage()**

In the `sendMessage()` function, find:

```js
loadingEl.classList.add('visible');
```

Replace with:

```js
showThinking();
```

- [ ] **Step 5: Replace loadingEl in the error path (!result.ok)**

Find the block that handles `!result.ok`:

```js
if (!result.ok) {
    loadingEl.classList.remove('visible');
```

Replace with:

```js
if (!result.ok) {
    removeThinking();
```

- [ ] **Step 6: Remove loadingEl from the SSE stream setup**

Find (inside the `if (messageId)` block):

```js
var assistantContent = appendMessage('assistant', '');
                    loadingEl.classList.remove('visible');
```

Replace with (thinking indicator stays visible until first token, removed in Chunk 3):

```js
var assistantContent = appendMessage('assistant', '');
```

- [ ] **Step 7: Replace loadingEl in the non-streaming fallback**

Find (inside the `else` block for no messageId):

```js
loadingEl.classList.remove('visible');
```

Replace with:

```js
removeThinking();
```

- [ ] **Step 8: Replace loadingEl in the network error catch**

Find (in the `.catch()` handler):

```js
loadingEl.classList.remove('visible');
```

Replace with:

```js
removeThinking();
```

- [ ] **Step 9: Verify all loadingEl references are gone**

Search the file for `loadingEl`. There should be zero occurrences.

Run: `grep -c 'loadingEl' templates/dashboard.twig`
Expected: `0`

- [ ] **Step 10: Commit**

```bash
git add templates/dashboard.twig
git commit -m "feat: replace static loading with animated thinking indicator"
```

---

## Chunk 3: Streaming Cursor Integration

### Task 4: Add .streaming class management to SSE handlers

**Files:**
- Modify: `templates/dashboard.twig` (JS `<script>` block, SSE event handlers)

- [ ] **Step 1: Track the assistant message div and remove thinking on first token**

In the `if (messageId)` block, we need a reference to the parent `.message` div and a flag to remove the thinking indicator on the first token (not at SSE setup time, per spec).

Find:

```js
var assistantContent = appendMessage('assistant', '');
```

Replace with:

```js
var assistantContent = appendMessage('assistant', '');
var assistantMsg = assistantContent.parentElement;
assistantMsg.classList.add('streaming');
assistantMsg.style.display = 'none';
var firstToken = true;
```

The assistant message is hidden initially so the thinking bubble stays visible until the first real token arrives.

- [ ] **Step 2: Handle first token to remove thinking and reveal assistant message**

Find the `chat-token` event handler:

```js
chatSource.addEventListener('chat-token', function(e) {
    try {
        var tokenData = JSON.parse(e.data);
        assistantContent.textContent += tokenData.token || '';
```

Replace with:

```js
chatSource.addEventListener('chat-token', function(e) {
    try {
        if (firstToken) {
            removeThinking();
            assistantMsg.style.display = '';
            firstToken = false;
        }
        var tokenData = JSON.parse(e.data);
        assistantContent.textContent += tokenData.token || '';
```

- [ ] **Step 3: Remove .streaming on chat-done**

Find the `chat-done` event handler:

```js
chatSource.addEventListener('chat-done', function() {
    chatSource.close();
    chatSource = null;
    wrapCardsInContent(assistantContent);
```

Replace with:

```js
chatSource.addEventListener('chat-done', function() {
    chatSource.close();
    chatSource = null;
    assistantMsg.classList.remove('streaming');
    wrapCardsInContent(assistantContent);
```

- [ ] **Step 4: Remove .streaming on chat-error**

Find the `chat-error` event handler:

```js
chatSource.addEventListener('chat-error', function(e) {
    chatSource.close();
    chatSource = null;
```

Replace with:

```js
chatSource.addEventListener('chat-error', function(e) {
    chatSource.close();
    chatSource = null;
    assistantMsg.classList.remove('streaming');
```

- [ ] **Step 5: Remove .streaming on onerror**

Find the `onerror` handler:

```js
chatSource.onerror = function() {
    chatSource.close();
    chatSource = null;
```

Replace with:

```js
chatSource.onerror = function() {
    chatSource.close();
    chatSource = null;
    assistantMsg.classList.remove('streaming');
```

- [ ] **Step 6: Add removeThinking() to SSE error handlers**

The thinking indicator should also be removed in `chat-error` and `onerror` handlers as a safety net (in case the error occurs before any token arrives).

In the `chat-error` handler, after `assistantMsg.classList.remove('streaming');`, add:

```js
removeThinking();
```

In the `onerror` handler, after `assistantMsg.classList.remove('streaming');`, add:

```js
removeThinking();
```

`removeThinking()` is a no-op if the indicator is already gone, so this is safe.

- [ ] **Step 7: Manual test**

Open the dashboard in a browser. Send a message. Verify:
1. A shimmer-animated "Thinking..." bubble appears immediately after sending
2. When tokens start streaming, the thinking bubble disappears and a pulsing dot appears at the end of the growing text
3. When streaming completes, the pulsing dot disappears
4. If the API key is missing or invalid, the thinking bubble disappears on error

- [ ] **Step 8: Commit**

```bash
git add templates/dashboard.twig
git commit -m "feat: add pulsing dot streaming cursor to assistant messages"
```

---

## Verification Checklist

After all tasks are complete, verify:

- [ ] No occurrences of `loadingEl` in the file
- [ ] No occurrences of `id="loading"` in the file
- [ ] No `.loading` CSS rules in the file
- [ ] `@keyframes shimmer` exists in CSS
- [ ] `@keyframes pulse-dot` exists in CSS
- [ ] `.thinking-indicator` CSS exists
- [ ] `.streaming .message-content::after` CSS exists
- [ ] `showThinking()` function exists in JS
- [ ] `removeThinking()` function exists in JS
- [ ] `removeThinking()` is called in all 5 exit paths (first token, server error, non-streaming, network error, SSE error/onerror)
- [ ] `.streaming` class is added when SSE message div is created
- [ ] Thinking indicator persists until first `chat-token` (not removed at SSE setup)
- [ ] `.streaming` class is removed in `chat-done`, `chat-error`, and `onerror` handlers
