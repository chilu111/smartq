const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
const toast = document.querySelector('#toast');
let toastTimer;

function notify(message, isError = false) {
    if (!toast) return;
    toast.textContent = message;
    toast.classList.toggle('is-error', isError);
    toast.classList.add('is-visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove('is-visible'), 3200);
}

async function requestJson(url, options = {}) {
    const response = await fetch(url, {
        ...options,
        signal: options.signal ?? AbortSignal.timeout(5000),
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            ...(options.body ? { 'Content-Type': 'application/json' } : {}),
            ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
            ...options.headers,
        },
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || Object.values(data.errors || {}).flat()[0] || 'The request could not be completed.');
    return data;
}

function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
}

function createUuid() {
    if (crypto.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
        const random = Math.floor(Math.random() * 16);
        return (character === 'x' ? random : (random & 0x3) | 0x8).toString(16);
    });
}

const queueRoot = document.querySelector('[data-queue-endpoint]');
if (queueRoot) {
    const stateUrl = queueRoot.dataset.queueEndpoint;
    const callUrl = queueRoot.dataset.callEndpoint;
    const actionTemplate = queueRoot.dataset.actionTemplate;
    const servicePoint = document.querySelector('[data-service-point]');
    const callButton = document.querySelector('[data-call-next]');
    const rows = document.querySelector('[data-queue-rows]');
    const statusLabel = document.querySelector('[data-queue-status]');
    const transferDialog = document.querySelector('[data-transfer-dialog]');
    let polling = false;
    let latestEntries = [];
    let transferEntryId = null;

    function makeAction(entry, action, label, confirmText = null) {
        const button = element('button', 'row-action', label);
        button.type = 'button';
        button.dataset.action = action;
        if (!['transfer', 'move_point'].includes(action)) {
            button.addEventListener('click', () => runAction(entry, action, confirmText));
        }
        return button;
    }

    function actionsFor(entry) {
        const actions = element('div', 'row-actions');
        if (entry.status === 'called') {
            actions.append(makeAction(entry, 'start', 'Start'), makeAction(entry, 'recall', 'Recall'), makeAction(entry, 'requeue', 'Return'), makeAction(entry, 'absent', 'Absent'), makeAction(entry, 'move_point', 'Change counter'));
        } else if (entry.status === 'serving') {
            actions.append(makeAction(entry, 'complete', 'Complete', 'Complete this department stage?'), makeAction(entry, 'recall', 'Recall'));
        } else if (entry.status === 'held') {
            actions.append(makeAction(entry, 'resume', 'Resume'), makeAction(entry, 'cancel', 'Cancel', 'Cancel this queue stage?'));
        } else if (entry.status === 'absent') {
            actions.append(makeAction(entry, 'return', 'Return to queue'), makeAction(entry, 'cancel', 'Cancel', 'Cancel this queue stage?'));
        } else if (entry.status === 'waiting') {
            actions.append(makeAction(entry, 'hold', 'Hold'), makeAction(entry, 'priority', entry.priority ? 'Normal' : 'Priority'));
            actions.append(makeAction(entry, 'cancel', 'Cancel', 'Cancel this queue stage?'));
        }
        if (['waiting', 'called', 'serving', 'held', 'absent'].includes(entry.status)) {
            actions.append(makeAction(entry, 'transfer', 'Transfer'));
        }
        return actions;
    }

    function render(data) {
        latestEntries = data.entries;
        for (const name of ['waiting', 'serving', 'completed', 'held']) {
            const target = document.querySelector(`[data-count="${name}"]`);
            if (target) target.textContent = data.counts[name] ?? 0;
        }
        const activePointId = servicePoint?.value;
        const current = activePointId
            ? data.current.find((entry) => String(entry.service_point_id) === activePointId)
            : data.current[0];
        const currentTicket = document.querySelector('[data-current-ticket]');
        const currentStatus = document.querySelector('[data-current-status]');
        const currentPoint = document.querySelector('[data-current-point]');
        if (currentTicket) currentTicket.textContent = current?.ticket || '—';
        if (currentStatus) {
            currentStatus.textContent = current ? current.status : 'No active ticket';
            currentStatus.classList.toggle('is-serving', current?.status === 'serving');
        }
        if (currentPoint) currentPoint.textContent = current ? current.service_point || 'Service point not assigned' : 'Ready to serve the next patient';
        const nextLabel = document.querySelector('[data-call-next]');
        if (nextLabel) nextLabel.disabled = !servicePoint?.value;
        if (rows) {
            rows.replaceChildren();
            if (!data.entries.length) {
                const row = element('tr');
                const cell = element('td', 'empty-cell', 'No active patients in this department queue.');
                cell.colSpan = 5;
                row.append(cell);
                rows.append(row);
            } else {
                for (const entry of data.entries) {
                    const row = element('tr');
                    row.append(element('td', 'ticket-cell', entry.ticket));
                    const state = element('td');
                    state.append(element('span', `status-pill status-${entry.status}`, entry.status));
                    const wait = element('td', '', `${entry.waiting_minutes} min`);
                    const priority = element('td');
                    priority.append(entry.priority ? element('span', 'priority-label', 'Priority') : element('span', '', '—'));
                    const actionCell = element('td');
                    actionCell.append(actionsFor(entry));
                    row.append(state, wait, priority, actionCell);
                    rows.append(row);
                }
            }
        }
        const total = document.querySelector('[data-queue-total]');
        if (total) total.textContent = `${data.entries.length} ${data.entries.length === 1 ? 'entry' : 'entries'}`;
        const closePanel = document.querySelector('[data-ready-close-panel]');
        const closeRows = document.querySelector('[data-ready-close-rows]');
        if (closePanel && closeRows) {
            closeRows.replaceChildren();
            closePanel.hidden = !data.ready_to_close.length;
            for (const visit of data.ready_to_close) {
                const row = element('tr');
                row.append(element('td', 'ticket-cell', visit.ticket_number), element('td', '', 'No active queue stages'));
                const cell = element('td');
                const actions = element('div', 'row-actions');
                const complete = element('button', 'row-action', 'Complete visit');
                complete.type = 'button';
                complete.addEventListener('click', () => closeVisit(visit.id, 'complete'));
                const cancel = element('button', 'row-action', 'Cancel visit');
                cancel.type = 'button';
                cancel.addEventListener('click', () => closeVisit(visit.id, 'cancel'));
                actions.append(complete, cancel);
                cell.append(actions);
                row.append(cell);
                closeRows.append(row);
            }
        }
        if (statusLabel) {
            statusLabel.classList.remove('is-offline');
            statusLabel.lastChild.textContent = ' Live';
        }
    }

    async function refresh() {
        if (polling || document.hidden) return;
        polling = true;
        try {
            render(await requestJson(stateUrl));
        } catch {
            if (statusLabel) {
                statusLabel.classList.add('is-offline');
                statusLabel.lastChild.textContent = ' Connection lost';
            }
            const currentTicket = document.querySelector('[data-current-ticket]');
            if (currentTicket) currentTicket.textContent = '—';
        } finally {
            polling = false;
        }
    }

    async function runAction(entry, action, confirmText = null, additional = {}) {
        if (confirmText && !window.confirm(confirmText)) return;
        if (action === 'priority') {
            const reason = window.prompt('Enter the approved clinical priority reason:');
            if (!reason?.trim()) return;
            additional = { priority: entry.priority ? 0 : 1, reason: reason.trim() };
        }
        try {
            const endpoint = actionTemplate.replace('ENTRY_ID', entry.id);
            await requestJson(endpoint, { method: 'POST', body: JSON.stringify({ action, ...additional }) });
            notify(action === 'transfer' ? 'Ticket transferred to the next department.' : 'Queue updated.');
            await refresh();
        } catch (error) {
            notify(error.message, true);
        }
    }

    async function closeVisit(visitId, action) {
        const verb = action === 'complete' ? 'Complete' : 'Cancel';
        if (!window.confirm(`${verb} this entire visit?`)) return;
        const template = action === 'complete' ? queueRoot.dataset.completeVisitTemplate : queueRoot.dataset.cancelVisitTemplate;
        try {
            await requestJson(template.replace('VISIT_ID', visitId), { method: 'POST' });
            notify(`Visit ${action === 'complete' ? 'completed' : 'cancelled'}.`);
            await refresh();
        } catch (error) {
            notify(error.message, true);
        }
    }

    callButton?.addEventListener('click', async () => {
        if (!servicePoint?.value || callButton.getAttribute('aria-busy') === 'true') return;
        callButton.setAttribute('aria-busy', 'true');
        callButton.disabled = true;
        const label = callButton.innerHTML;
        callButton.textContent = 'Calling…';
        try {
            const result = await requestJson(callUrl, { method: 'POST', body: JSON.stringify({ service_point_id: servicePoint.value }) });
            notify(result.message, !result.entry);
            await refresh();
        } catch (error) {
            notify(error.message, true);
        } finally {
            callButton.innerHTML = label;
            callButton.removeAttribute('aria-busy');
            callButton.disabled = !servicePoint.value;
        }
    });

    servicePoint?.addEventListener('change', refresh);
    document.querySelector('[data-close-transfer]')?.addEventListener('click', () => transferDialog.close());
    document.querySelector('[data-confirm-transfer]')?.addEventListener('click', async () => {
        const action = transferDialog.dataset.mode;
        const value = action === 'transfer'
            ? { department_id: document.querySelector('[data-transfer-department]').value }
            : { service_point_id: document.querySelector('[data-move-point]').value };
        if (!Object.values(value)[0] || !transferEntryId) return;
        await runAction({ id: transferEntryId }, action, action === 'transfer' ? 'Transfer this ticket to the selected department?' : 'Move this ticket to the selected service point?', value);
        transferDialog.close();
    });
    rows?.addEventListener('click', (event) => {
        const button = event.target.closest('.row-action');
        if (button?.dataset.action === 'transfer' || button?.dataset.action === 'move_point') {
            const row = button.closest('tr');
            const ticket = row?.querySelector('.ticket-cell')?.textContent;
            const entry = latestEntries.find((candidate) => candidate.ticket === ticket);
            if (!entry || !transferDialog) return;
            transferEntryId = entry.id;
            transferDialog.dataset.mode = button.dataset.action;
            document.querySelector('[data-transfer-department]').closest('label').hidden = button.dataset.action !== 'transfer';
            document.querySelector('[data-move-point-field]').hidden = button.dataset.action !== 'move_point';
            transferDialog.querySelector('h2').firstChild.textContent = button.dataset.action === 'transfer' ? 'Transfer ticket ' : 'Change service point for ';
            document.querySelector('[data-transfer-ticket]').textContent = ticket;
            transferDialog.showModal();
        }
    });
    document.addEventListener('visibilitychange', refresh);
    refresh();
    setInterval(refresh, 2000);
}

const registrationForm = document.querySelector('[data-register-form]');
registrationForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = registrationForm.querySelector('[data-submit-button]');
    button.disabled = true;
    button.textContent = 'Issuing ticket…';
    try {
        const form = new FormData(registrationForm);
        const result = await requestJson(registrationForm.action, {
            method: 'POST',
            body: JSON.stringify({
                display_name: form.get('display_name'),
                service_point_id: form.get('service_point_id') || null,
                idempotency_key: form.get('idempotency_key'),
            }),
        });
        document.querySelector('[data-issued-ticket]').textContent = result.ticket;
        document.querySelector('[data-ticket-result]').hidden = false;
        registrationForm.closest('.registration-panel').hidden = true;
        notify(`Ticket ${result.ticket} issued.`);
    } catch (error) {
        notify(error.message, true);
        button.disabled = false;
        button.innerHTML = 'Issue queue ticket <span>→</span>';
    }
});

const displayRoot = document.querySelector('[data-display]');
if (displayRoot) {
    const endpoint = displayRoot.dataset.displayEndpoint;
    const audioEndpoint = displayRoot.dataset.audioEndpoint;
    const slug = displayRoot.dataset.displaySlug;
    const current = document.querySelector('[data-display-current]');
    const connection = document.querySelector('[data-display-connection]');
    const updated = document.querySelector('[data-display-updated]');
    const recentList = document.querySelector('[data-recent-list]');
    const audioButton = document.querySelector('[data-enable-audio]');
    let sourceId = sessionStorage.getItem('smartqueue-audio-source-id');
    if (!sourceId) {
        sourceId = createUuid();
        sessionStorage.setItem('smartqueue-audio-source-id', sourceId);
    }
    let polling = false;
    let lastCallKey = null;
    let previousSignature = '';
    let wasConnected = false;
    let audioEnabledHere = false;

    function updateClock() {
        const now = new Date();
        document.querySelector('[data-clock]').textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        document.querySelector('[data-date]').textContent = now.toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long' });
    }

    function updateAudioButton(data) {
        if (data) audioEnabledHere = data.audio_department_id === data.department_id && data.audio_source_id === sourceId;
        audioButton.classList.toggle('is-enabled', audioEnabledHere);
        audioButton.textContent = audioEnabledHere ? 'Voice announcements enabled here' : 'Enable voice announcements';
    }

    async function refreshDisplay() {
        if (polling || document.hidden) return;
        polling = true;
        try {
            const data = await requestJson(endpoint);
            const ticket = data.current?.ticket || null;
            const callKey = data.current ? `${data.current.ticket}:${data.current.called_at}` : null;
            const signature = JSON.stringify(data.recent);
            current.textContent = ticket || '—';
            document.querySelector('[data-display-point]').textContent = data.current?.service_point || (ticket ? 'Please proceed to the service point' : 'Please wait for your ticket to be called');
            connection.classList.remove('is-offline');
            connection.innerHTML = '<i></i> Connected';
            updated.textContent = `Updated ${new Date(data.updated_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}`;
            if (ticket && wasConnected && callKey && callKey !== lastCallKey) {
                current.classList.remove('ticket-changed');
                void current.offsetWidth;
                current.classList.add('ticket-changed');
                if (wasConnected && audioEnabledHere && 'speechSynthesis' in window) {
                    window.speechSynthesis.cancel();
                    window.speechSynthesis.speak(new SpeechSynthesisUtterance(`${ticket}. Please proceed to ${data.current.service_point || data.department}.`));
                }
            }
            lastCallKey = callKey;
            wasConnected = true;
            updateAudioButton(data);
            if (signature !== previousSignature) {
                recentList.replaceChildren();
                document.querySelector('[data-recent-count]').textContent = data.recent.length;
                if (!data.recent.length) {
                    recentList.append(element('div', 'recent-empty', 'Tickets called here will appear on this screen.'));
                } else {
                    for (const item of data.recent) {
                        const row = element('div', 'recent-row');
                        const description = element('div');
                        description.append(element('strong', '', item.ticket), element('small', '', item.service_point || data.department));
                        row.append(description, element('time', '', item.called_at ? new Date(item.called_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : ''));
                        recentList.append(row);
                    }
                }
                previousSignature = signature;
            }
        } catch {
            wasConnected = false;
            connection.classList.add('is-offline');
            connection.innerHTML = '<i></i> Connection lost';
            current.textContent = '—';
            document.querySelector('[data-display-point]').textContent = 'Waiting for a live connection';
            updated.textContent = 'Display data may be stale';
        } finally {
            polling = false;
        }
    }

    audioButton?.addEventListener('click', async () => {
        if (!('speechSynthesis' in window)) {
            notify('Voice announcements are not supported by this browser.', true);
            return;
        }
        try {
            await requestJson(audioEndpoint, { method: 'POST', body: JSON.stringify({ source_id: sourceId }) });
            audioEnabledHere = true;
            updateAudioButton();
            notify('This display is now the clinic announcement source.');
        } catch (error) {
            notify(error.message, true);
        }
    });
    document.addEventListener('visibilitychange', refreshDisplay);
    updateClock();
    refreshDisplay();
    setInterval(refreshDisplay, 1500);
    setInterval(updateClock, 15000);
}