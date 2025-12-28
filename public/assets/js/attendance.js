// Attendance Entry Application
(function() {
    const SLUG = window.BRIGADE_SLUG;
    const BASE = window.BASE_PATH || '';
    let state = {
        callout: null,
        trucks: [],
        members: [],
        availableMembers: [],
        selectedMember: null,
        eventSource: null,
        isProcessing: false,
        calloutsThisYear: 0,
        lastCallout: null
    };

    // DOM Elements
    const elements = {
        loading: document.getElementById('loading'),
        noCallout: document.getElementById('no-callout'),
        attendanceArea: document.getElementById('attendance-area'),
        trucksContainer: document.getElementById('trucks-container'),
        availableMembers: document.getElementById('available-members'),
        memberCount: document.getElementById('member-count'),
        icadNumber: document.getElementById('icad-number'),
        changeIcadBtn: document.getElementById('change-icad-btn'),
        copyLastBtn: document.getElementById('copy-last-btn'),
        submitBtn: document.getElementById('submit-btn'),
        closeBtn: document.getElementById('close-btn'),
        submittedTime: document.getElementById('submitted-time'),
        syncStatus: document.getElementById('sync-status'),
        newCalloutForm: document.getElementById('new-callout-form'),
        icadModal: document.getElementById('icad-modal'),
        submitModal: document.getElementById('submit-modal')
    };

    // Initialize
    async function init() {
        showLoading();
        await loadData();
        setupEventListeners();
    }

    // Load initial data
    async function loadData() {
        try {
            // Add cache-busting parameter to prevent stale data after submission
            const response = await fetch(`${BASE}/${SLUG}/api/callout/active?_=${Date.now()}`);
            const data = await response.json();

            state.trucks = data.trucks || [];
            state.members = data.members || [];
            state.calloutsThisYear = data.callouts_this_year || 0;
            state.lastCallout = data.last_callout || null;

            if (data.callout) {
                state.callout = data.callout;
                state.availableMembers = data.callout.available_members || [];
                showAttendanceArea();
                connectSSE();
            } else {
                showNoCallout();
            }
        } catch (error) {
            console.error('Failed to load data:', error);
            showError('Failed to load data. Please refresh the page.');
        }
    }

    // Setup event listeners
    function setupEventListeners() {
        elements.newCalloutForm.addEventListener('submit', handleNewCallout);
        elements.changeIcadBtn.addEventListener('click', showIcadModal);
        elements.copyLastBtn.addEventListener('click', handleCopyLastCall);
        elements.closeBtn.addEventListener('click', handleClose);
        document.getElementById('change-icad-form').addEventListener('submit', handleChangeIcad);
        elements.submitBtn.addEventListener('click', showSubmitModal);
    }

    // Handle new callout creation
    async function handleNewCallout(e) {
        e.preventDefault();
        const icadNumber = document.getElementById('new-icad').value.trim();
        const callDateTime = document.getElementById('new-datetime').value;
        const location = document.getElementById('new-location').value.trim();
        const callType = document.getElementById('new-call-type').value.trim();

        if (!icadNumber) {
            alert('Please enter an ICAD number');
            return;
        }

        try {
            const response = await fetch(`${BASE}/${SLUG}/api/callout`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    icad_number: icadNumber,
                    call_datetime: callDateTime,
                    location: location,
                    call_type: callType
                })
            });

            const data = await response.json();

            if (data.error) {
                alert(data.error);
                return;
            }

            // Check if this ICAD was already submitted
            if (data.already_submitted) {
                const submittedDate = new Date(data.submitted_at).toLocaleString();
                alert(`This callout (${data.icad_number}) has already been submitted on ${submittedDate}.`);
                document.getElementById('new-icad').value = '';
                return;
            }

            state.callout = data.callout;
            state.availableMembers = data.callout.available_members || state.members;
            showAttendanceArea();
            connectSSE();
        } catch (error) {
            console.error('Failed to create callout:', error);
            alert('Failed to create callout. Please try again.');
        }
    }

    function showIcadModal() {
        document.getElementById('modal-icad').value = state.callout.icad_number;
        elements.icadModal.style.display = 'flex';
    }

    window.closeIcadModal = function() {
        elements.icadModal.style.display = 'none';
    };

    async function handleChangeIcad(e) {
        e.preventDefault();
        const newIcad = document.getElementById('modal-icad').value.trim();
        if (!newIcad) return;

        try {
            await fetch(`${BASE}/${SLUG}/api/callout/${state.callout.id}`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ icad_number: newIcad })
            });

            state.callout.icad_number = newIcad;
            elements.icadNumber.textContent = newIcad;
            closeIcadModal();
        } catch (error) {
            console.error('Failed to update ICAD:', error);
            alert('Failed to update ICAD number.');
        }
    }

    async function handleCopyLastCall() {
        if (!state.callout || state.callout.status !== 'active') return;

        if (!confirm('Copy attendance from the last submitted callout? This will add all members from that call to this one.')) {
            return;
        }

        elements.copyLastBtn.disabled = true;
        elements.copyLastBtn.textContent = 'Copying...';

        try {
            const response = await fetch(`${BASE}/${SLUG}/api/callout/${state.callout.id}/copy-last`, {
                method: 'POST'
            });

            const data = await response.json();

            if (data.error) {
                alert(data.error);
                return;
            }

            // Update state with new attendance
            if (data.attendance) {
                state.callout.attendance = data.attendance;
            }
            if (data.available_members) {
                state.availableMembers = data.available_members;
            }

            render();
            alert(`Copied ${data.copied} attendees from ${data.from_icad}`);
        } catch (error) {
            console.error('Failed to copy last call:', error);
            alert('Failed to copy attendance. Please try again.');
        } finally {
            elements.copyLastBtn.disabled = false;
            elements.copyLastBtn.textContent = 'Copy Last Call';
        }
    }

    function handleClose() {
        // Reset state and go back to new callout entry
        state.callout = null;
        state.availableMembers = [];
        showNoCallout();
        document.getElementById('new-icad').value = '';
    }

    function showSubmitModal() {
        elements.submitModal.style.display = 'flex';
    }

    window.closeSubmitModal = function() {
        elements.submitModal.style.display = 'none';
    };

    window.confirmSubmit = async function() {
        if (state.eventSource) {
            state.eventSource.close();
            state.eventSource = null;
        }

        state.callout.status = 'submitted';

        try {
            const response = await fetch(`${BASE}/${SLUG}/api/callout/${state.callout.id}/submit`, {
                method: 'POST'
            });

            if (!response.ok) {
                const text = await response.text();
                console.error('Server error:', text);
                alert('Failed to submit: Server error. Please try again.');
                state.callout.status = 'active';
                return;
            }

            const data = await response.json();

            if (data.error) {
                alert(data.error);
                state.callout.status = 'active';
                return;
            }

            closeSubmitModal();

            // Close SSE connection
            if (state.eventSource) {
                state.eventSource.close();
                state.eventSource = null;
            }

            // Show submitted state
            showSubmittedState();
        } catch (error) {
            console.error('Failed to submit:', error);
            alert('Failed to submit attendance. Please try again.');
            state.callout.status = 'active';
        }
    };

    function showSubmittedState() {
        // Update submit button to show submitted
        elements.submitBtn.disabled = true;
        elements.submitBtn.textContent = 'Submitted';
        elements.submitBtn.classList.remove('btn-success');
        elements.submitBtn.classList.add('btn-submitted');

        // Show submitted time
        const now = new Date();
        elements.submittedTime.textContent = `Submitted at ${now.toLocaleTimeString()}`;
        elements.submittedTime.style.display = 'inline';

        // Show close button
        elements.closeBtn.style.display = 'inline-block';

        // Hide change and copy buttons
        elements.changeIcadBtn.style.display = 'none';
        elements.copyLastBtn.style.display = 'none';

        // Disable editing
        disableEditing();

        // Update sync status
        updateSyncStatus('offline');
    }

    function connectSSE() {
        // Don't connect if already submitted
        if (state.callout.status === 'submitted') {
            updateSyncStatus('offline');
            return;
        }

        if (state.eventSource) {
            state.eventSource.close();
        }

        updateSyncStatus('connecting');
        state.eventSource = new EventSource(`${BASE}/${SLUG}/api/sse/callout/${state.callout.id}`);

        state.eventSource.addEventListener('connected', () => {
            updateSyncStatus('connected');
        });

        state.eventSource.addEventListener('update', (event) => {
            const data = JSON.parse(event.data);
            handleRemoteUpdate(data);
        });

        state.eventSource.addEventListener('submitted', () => {
            // Close connection immediately
            if (state.eventSource) {
                state.eventSource.close();
                state.eventSource = null;
            }

            // Only alert if we didn't submit it ourselves
            if (state.callout.status !== 'submitted') {
                state.callout.status = 'submitted';
                elements.submitBtn.disabled = true;
                elements.submitBtn.textContent = 'Submitted';
                disableEditing();
                alert('This callout has been submitted by another user.');
            }
        });

        state.eventSource.addEventListener('reconnect', () => {
            // Only reconnect if not submitted
            if (state.callout.status !== 'submitted') {
                setTimeout(connectSSE, 1000);
            }
        });

        state.eventSource.onerror = () => {
            updateSyncStatus('offline');
            // Only reconnect if not submitted
            if (state.callout.status !== 'submitted') {
                setTimeout(connectSSE, 3000);
            }
        };
    }

    function disableEditing() {
        document.querySelectorAll('.member-chip, .position-slot, .standby-add, .standby-member').forEach(el => {
            el.style.pointerEvents = 'none';
            el.style.opacity = '0.7';
        });
    }

    function handleRemoteUpdate(data) {
        if (data.attendance) {
            state.callout.attendance = data.attendance;
        }
        if (data.available_members) {
            state.availableMembers = data.available_members;
        }
        render();
    }

    function updateSyncStatus(status) {
        elements.syncStatus.className = 'sync-status ' + status;
        // Only show the colored dot icon, no text
        const statusText = elements.syncStatus.querySelector('.status-text');
        if (statusText) {
            statusText.style.display = 'none';
        }
    }

    function showLoading() {
        elements.loading.style.display = 'block';
        elements.noCallout.style.display = 'none';
        elements.attendanceArea.style.display = 'none';
        const historyPanel = document.getElementById('history-panel');
        if (historyPanel) historyPanel.style.display = 'none';
    }

    function showNoCallout() {
        elements.loading.style.display = 'none';
        elements.noCallout.style.display = 'block';
        elements.attendanceArea.style.display = 'none';

        // Pre-populate date/time with current NZ time
        const datetimeInput = document.getElementById('new-datetime');
        if (datetimeInput && !datetimeInput.value) {
            // Get current time in NZ timezone and format for datetime-local input
            const now = new Date();
            const nzTime = new Date(now.toLocaleString('en-US', { timeZone: 'Pacific/Auckland' }));
            const year = nzTime.getFullYear();
            const month = String(nzTime.getMonth() + 1).padStart(2, '0');
            const day = String(nzTime.getDate()).padStart(2, '0');
            const hours = String(nzTime.getHours()).padStart(2, '0');
            const minutes = String(nzTime.getMinutes()).padStart(2, '0');
            datetimeInput.value = `${year}-${month}-${day}T${hours}:${minutes}`;
        }

        // Display callouts this year count and last callout
        const countElement = document.getElementById('callouts-this-year');
        if (countElement) {
            let info = `Callouts this year: ${state.calloutsThisYear}`;
            if (state.lastCallout) {
                info += ` | Last Callout: ${state.lastCallout.icad_number}`;
            }
            countElement.textContent = info;
        }

        // Show history panel and set correct URL
        const historyPanel = document.getElementById('history-panel');
        const historyLink = document.getElementById('history-link');
        if (historyPanel) {
            historyPanel.style.display = 'block';
        }
        if (historyLink) {
            historyLink.href = `${BASE}/${SLUG}/history`;
        }
    }

    function showAttendanceArea() {
        elements.loading.style.display = 'none';
        elements.noCallout.style.display = 'none';
        elements.attendanceArea.style.display = 'flex';
        const historyPanel = document.getElementById('history-panel');
        if (historyPanel) historyPanel.style.display = 'none';

        // Display the ICAD number (Muster-YYYY-MM-DD will show as stored)
        elements.icadNumber.textContent = state.callout.icad_number;

        // Check if already submitted
        if (state.callout.status === 'submitted') {
            showSubmittedState();
        } else {
            // Show active state controls
            elements.changeIcadBtn.style.display = 'inline-block';
            elements.copyLastBtn.style.display = 'inline-block';
            elements.submitBtn.disabled = false;
            elements.submitBtn.textContent = 'Submit';
            elements.submitBtn.classList.add('btn-success');
            elements.submitBtn.classList.remove('btn-submitted');
            elements.submittedTime.style.display = 'none';
            elements.closeBtn.style.display = 'none';
        }

        render();
    }

    function showError(message) {
        elements.loading.innerHTML = `<p class="error-message">${message}</p>`;
    }

    function render() {
        renderTrucks();
        renderAvailableMembers();
    }

    // Check if this is a muster (case-insensitive, also matches "Muster-YYYY-MM-DD")
    function isMuster() {
        if (!state.callout) return false;
        const icad = state.callout.icad_number.toLowerCase();
        return icad === 'muster' || icad.startsWith('muster-');
    }

    function renderTrucks() {
        const attendance = state.callout.attendance || [];
        const attendanceMap = new Map();

        attendance.forEach(truck => {
            truck.positions && Object.values(truck.positions).forEach(pos => {
                pos.members && pos.members.forEach(member => {
                    if (!attendanceMap.has(truck.truck_id)) {
                        attendanceMap.set(truck.truck_id, new Map());
                    }
                    if (!attendanceMap.get(truck.truck_id).has(pos.position_id)) {
                        attendanceMap.get(truck.truck_id).set(pos.position_id, []);
                    }
                    attendanceMap.get(truck.truck_id).get(pos.position_id).push(member);
                });
            });
        });

        // For muster mode: only show station trucks (is_station = 1 or "1")
        let trucksToRender = state.trucks;
        if (isMuster()) {
            // Filter to only station trucks - check for both numeric 1 and string "1"
            const stations = state.trucks.filter(t => t.is_station == 1);
            trucksToRender = stations;
        }

        elements.trucksContainer.innerHTML = trucksToRender.map(truck => {
            const isStation = truck.is_station == 1;
            const truckAttendance = attendanceMap.get(truck.id) || new Map();

            let positionsHtml = '';

            if (isStation) {
                const standbyPosition = truck.positions.find(p => p.allow_multiple);
                if (standbyPosition) {
                    const standbyMembers = truckAttendance.get(standbyPosition.id) || [];

                    positionsHtml = `
                        <div class="standby-section">
                            <div class="standby-members">
                                ${standbyMembers.map(m => `
                                    <div class="standby-member"
                                         data-attendance-id="${m.id}"
                                         data-member-id="${m.member_id}"
                                         onclick="removeAttendance(${m.id})">
                                        <span>${escapeHtml(m.member_name)}</span>
                                        <span class="remove">×</span>
                                    </div>
                                `).join('')}
                                <div class="standby-add" onclick="selectForStandby(${truck.id}, ${standbyPosition.id})">
                                    + Add
                                </div>
                            </div>
                        </div>
                    `;
                }
            } else {
                positionsHtml = `
                    <div class="positions-grid">
                        ${truck.positions.map(pos => {
                            const assigned = (truckAttendance.get(pos.id) || [])[0];
                            const isFilled = !!assigned;

                            return `
                                <div class="position-slot ${isFilled ? 'filled' : ''}"
                                     ${isFilled ? `data-attendance-id="${assigned.id}" data-member-id="${assigned.member_id}"` : ''}
                                     onclick="${isFilled ? `removeAttendance(${assigned.id})` : `selectPosition(${truck.id}, ${pos.id})`}">
                                    <div class="position-name">${escapeHtml(pos.name)}</div>
                                    <div class="member-name">${isFilled ? escapeHtml(assigned.member_name) : '-'}</div>
                                </div>
                            `;
                        }).join('')}
                    </div>
                `;
            }

            return `
                <div class="truck-card">
                    <div class="truck-header-bar ${isStation ? 'station' : ''}">
                        ${escapeHtml(truck.name)}
                    </div>
                    ${positionsHtml}
                </div>
            `;
        }).join('');
    }

    function renderAvailableMembers() {
        // Update count badge
        if (elements.memberCount) {
            elements.memberCount.textContent = state.availableMembers.length;
        }

        elements.availableMembers.innerHTML = state.availableMembers.map(member => `
            <div class="member-chip ${state.selectedMember === member.id ? 'selected' : ''}"
                 data-member-id="${member.id}"
                 onclick="selectMember(${member.id})">
                <span class="name">${escapeHtml(member.display_name || member.name)}</span>
            </div>
        `).join('') || '<p class="no-data">All members assigned</p>';
    }

    window.selectMember = function(memberId) {
        if (state.selectedMember === memberId) {
            state.selectedMember = null;
        } else {
            state.selectedMember = memberId;
        }
        // Just update the visual selection, don't re-render everything
        document.querySelectorAll('.member-chip').forEach(chip => {
            const chipId = parseInt(chip.dataset.memberId);
            chip.classList.toggle('selected', chipId === state.selectedMember);
        });
    };

    window.selectPosition = async function(truckId, positionId) {
        if (!state.selectedMember) {
            elements.availableMembers.classList.add('flash');
            setTimeout(() => elements.availableMembers.classList.remove('flash'), 300);
            return;
        }

        await assignMember(state.selectedMember, truckId, positionId);
    };

    window.selectForStandby = async function(truckId, positionId) {
        if (!state.selectedMember) {
            elements.availableMembers.classList.add('flash');
            setTimeout(() => elements.availableMembers.classList.remove('flash'), 300);
            return;
        }

        await assignMember(state.selectedMember, truckId, positionId);
    };

    async function assignMember(memberId, truckId, positionId) {
        if (state.isProcessing) return;
        state.isProcessing = true;

        // Get member info for optimistic update
        const member = state.availableMembers.find(m => m.id === memberId);
        const memberName = member ? (member.display_name || member.name) : 'Assigning...';

        // Optimistic UI update - immediately show assignment
        state.availableMembers = state.availableMembers.filter(m => m.id !== memberId);
        state.selectedMember = null;
        renderAvailableMembers();

        // Show the member as assigned immediately (optimistic)
        const slot = document.querySelector(`.position-slot[onclick*="selectPosition(${truckId}, ${positionId})"]`);
        if (slot) {
            slot.classList.add('filled');
            slot.querySelector('.member-name').textContent = memberName;
            slot.style.pointerEvents = 'none'; // Prevent clicks during save
        }

        // Temporarily close SSE to prevent blocking (PHP single-threaded issue)
        if (state.eventSource) {
            state.eventSource.close();
            state.eventSource = null;
        }

        try {
            const response = await fetch(`${BASE}/${SLUG}/api/attendance`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    callout_id: state.callout.id,
                    member_id: memberId,
                    truck_id: truckId,
                    position_id: positionId
                })
            });

            const data = await response.json();

            // Update from server response
            if (data.attendance) {
                state.callout.attendance = data.attendance;
            }
            if (data.available_members) {
                state.availableMembers = data.available_members;
            }

            if (data.error && response.status !== 409) {
                alert(data.error);
            }

            render();
        } catch (error) {
            console.error('Failed to assign member:', error);
            alert('Failed to assign member. Please try again.');
            // Reload data to restore correct state
            await loadData();
        } finally {
            state.isProcessing = false;
            // Reconnect SSE after request completes
            if (state.callout && state.callout.status === 'active') {
                connectSSE();
            }
        }
    }

    window.removeAttendance = async function(attendanceId) {
        if (state.isProcessing) return;
        state.isProcessing = true;

        // Find the member being removed for optimistic update
        let removedMember = null;
        if (state.callout.attendance) {
            for (const truck of state.callout.attendance) {
                if (truck.positions) {
                    for (const pos of Object.values(truck.positions)) {
                        if (pos.members) {
                            const found = pos.members.find(m => m.id === attendanceId);
                            if (found) {
                                removedMember = found;
                                // Optimistic removal from attendance
                                pos.members = pos.members.filter(m => m.id !== attendanceId);
                                break;
                            }
                        }
                    }
                }
            }
        }

        // Optimistic UI update - render immediately
        render();

        // Temporarily close SSE to prevent blocking (PHP single-threaded issue)
        if (state.eventSource) {
            state.eventSource.close();
            state.eventSource = null;
        }

        try {
            const response = await fetch(`${BASE}/${SLUG}/api/attendance/${attendanceId}`, {
                method: 'DELETE'
            });

            const data = await response.json();

            if (data.error) {
                alert(data.error);
                // Reload on error to restore state
                await loadData();
                return;
            }

            state.callout.attendance = data.attendance;
            state.availableMembers = data.available_members;
            render();
        } catch (error) {
            console.error('Failed to remove attendance:', error);
            alert('Failed to remove. Please try again.');
            // Reload on error
            await loadData();
        } finally {
            state.isProcessing = false;
            // Reconnect SSE after request completes
            if (state.callout && state.callout.status === 'active') {
                connectSSE();
            }
        }
    };

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Add styles for flash animation
    const style = document.createElement('style');
    style.textContent = `
        .flash {
            animation: flash 0.3s ease;
        }
        @keyframes flash {
            0%, 100% { background-color: inherit; }
            50% { background-color: #fef3c7; }
        }
    `;
    document.head.appendChild(style);

    // ==========================================
    // DRAG AND DROP FUNCTIONALITY
    // ==========================================

    const dragState = {
        isDragging: false,
        draggedMember: null,
        dragSource: null, // 'available', 'position', or 'standby'
        sourceAttendanceId: null, // For dragging from filled positions
        ghostElement: null,
        startX: 0,
        startY: 0,
        dragThreshold: 10, // Pixels to move before drag starts
        hasDragStarted: false
    };

    // Create ghost element that follows cursor/finger
    function createGhost(memberName) {
        const ghost = document.createElement('div');
        ghost.className = 'drag-ghost';
        ghost.textContent = memberName;
        document.body.appendChild(ghost);
        return ghost;
    }

    // Update ghost position
    function updateGhostPosition(x, y) {
        if (dragState.ghostElement) {
            dragState.ghostElement.style.left = x + 'px';
            dragState.ghostElement.style.top = y + 'px';
        }
    }

    // Remove ghost element
    function removeGhost() {
        if (dragState.ghostElement) {
            dragState.ghostElement.remove();
            dragState.ghostElement = null;
        }
    }

    // Find drop target at position
    function getDropTargetAt(x, y) {
        // Hide ghost temporarily to get element underneath
        if (dragState.ghostElement) {
            dragState.ghostElement.style.display = 'none';
        }

        const element = document.elementFromPoint(x, y);

        if (dragState.ghostElement) {
            dragState.ghostElement.style.display = '';
        }

        if (!element) return null;

        // Check if it's a position slot (unfilled) - valid drop target
        const emptyPositionSlot = element.closest('.position-slot:not(.filled)');
        if (emptyPositionSlot) {
            return { type: 'position', element: emptyPositionSlot, filled: false };
        }

        // Check if it's a filled position slot - for swapping
        const filledPositionSlot = element.closest('.position-slot.filled');
        if (filledPositionSlot) {
            return { type: 'position', element: filledPositionSlot, filled: true };
        }

        // Check if it's a standby add button
        const standbyAdd = element.closest('.standby-add');
        if (standbyAdd) {
            return { type: 'standby', element: standbyAdd };
        }

        // Check if dropping on the available members panel (to unassign)
        const availablePanel = element.closest('.available-members') || element.closest('.members-panel');
        if (availablePanel && dragState.dragSource !== 'available') {
            return { type: 'available', element: availablePanel };
        }

        return null;
    }

    // Clear all drop target highlights
    function clearDropTargetHighlights() {
        document.querySelectorAll('.drop-target, .drop-invalid').forEach(el => {
            el.classList.remove('drop-target', 'drop-invalid');
        });
    }

    // Handle drag start (mouse or touch)
    function handleDragStart(e, memberId, memberName, source, attendanceId) {
        // Don't start drag if callout is submitted
        if (state.callout && state.callout.status === 'submitted') return;

        const touch = e.touches ? e.touches[0] : e;

        dragState.startX = touch.clientX;
        dragState.startY = touch.clientY;
        dragState.draggedMember = { id: memberId, name: memberName };
        dragState.dragSource = source || 'available';
        dragState.sourceAttendanceId = attendanceId || null;
        dragState.isDragging = true;
        dragState.hasDragStarted = false;
    }

    // Handle drag move (mouse or touch)
    function handleDragMove(e) {
        if (!dragState.isDragging || !dragState.draggedMember) return;

        const touch = e.touches ? e.touches[0] : e;
        const x = touch.clientX;
        const y = touch.clientY;

        // Check if we've moved enough to start dragging
        if (!dragState.hasDragStarted) {
            const dx = x - dragState.startX;
            const dy = y - dragState.startY;
            const distance = Math.sqrt(dx * dx + dy * dy);

            if (distance < dragState.dragThreshold) {
                return; // Not moved enough yet
            }

            // Start the drag
            dragState.hasDragStarted = true;
            dragState.ghostElement = createGhost(dragState.draggedMember.name);

            // Mark the source element as dragging
            if (dragState.dragSource === 'available') {
                const sourceChip = document.querySelector(`.member-chip[data-member-id="${dragState.draggedMember.id}"]`);
                if (sourceChip) {
                    sourceChip.classList.add('dragging');
                }
            } else if (dragState.dragSource === 'position') {
                const sourceSlot = document.querySelector(`.position-slot[data-attendance-id="${dragState.sourceAttendanceId}"]`);
                if (sourceSlot) {
                    sourceSlot.classList.add('dragging');
                }
            } else if (dragState.dragSource === 'standby') {
                const sourceStandby = document.querySelector(`.standby-member[data-attendance-id="${dragState.sourceAttendanceId}"]`);
                if (sourceStandby) {
                    sourceStandby.classList.add('dragging');
                }
            }

            // Deselect any selected member (we're dragging now)
            state.selectedMember = null;
            document.querySelectorAll('.member-chip.selected').forEach(chip => {
                chip.classList.remove('selected');
            });
        }

        // Prevent scrolling while dragging
        if (e.cancelable) {
            e.preventDefault();
        }

        // Update ghost position
        updateGhostPosition(x, y);

        // Highlight drop target
        clearDropTargetHighlights();
        const target = getDropTargetAt(x, y);
        if (target) {
            // Don't highlight the same slot we're dragging from
            if (target.type === 'position' && target.filled &&
                target.element.dataset.attendanceId === String(dragState.sourceAttendanceId)) {
                return;
            }
            target.element.classList.add('drop-target');
        }
    }

    // Handle drag end (mouse or touch)
    async function handleDragEnd(e) {
        if (!dragState.isDragging) return;

        const wasDragging = dragState.hasDragStarted;
        const draggedMember = dragState.draggedMember;
        const dragSource = dragState.dragSource;
        const sourceAttendanceId = dragState.sourceAttendanceId;

        // Get final position
        let x, y;
        if (e.changedTouches) {
            x = e.changedTouches[0].clientX;
            y = e.changedTouches[0].clientY;
        } else {
            x = e.clientX;
            y = e.clientY;
        }

        // Clean up drag state
        clearDropTargetHighlights();
        removeGhost();

        // Remove dragging class from all elements
        document.querySelectorAll('.dragging').forEach(el => {
            el.classList.remove('dragging');
        });

        // Reset drag state
        dragState.isDragging = false;
        dragState.hasDragStarted = false;
        dragState.draggedMember = null;
        dragState.dragSource = null;
        dragState.sourceAttendanceId = null;

        // If we actually dragged (not just clicked), try to drop
        if (wasDragging && draggedMember) {
            const target = getDropTargetAt(x, y);
            if (target) {
                // Handle different drop scenarios
                if (target.type === 'available' && sourceAttendanceId) {
                    // Dropping on available panel = remove from position
                    await window.removeAttendance(sourceAttendanceId);
                } else if (target.type === 'position' || target.type === 'standby') {
                    // Extract truck and position IDs from the onclick attribute
                    const onclick = target.element.getAttribute('onclick');
                    if (onclick) {
                        const match = onclick.match(/\((\d+),\s*(\d+)\)/);
                        if (match) {
                            const truckId = parseInt(match[1]);
                            const positionId = parseInt(match[2]);

                            if (dragSource === 'available') {
                                // Dragging from available list to position
                                await assignMember(draggedMember.id, truckId, positionId);
                            } else if (sourceAttendanceId) {
                                // Dragging from one position to another (move)
                                // First remove from old position, then assign to new
                                await moveMember(sourceAttendanceId, draggedMember.id, truckId, positionId);
                            }
                        }
                    }
                }
            }
        }
    }

    // Move member from one position to another
    async function moveMember(oldAttendanceId, memberId, newTruckId, newPositionId) {
        if (state.isProcessing) return;
        state.isProcessing = true;

        // Temporarily close SSE to prevent blocking
        if (state.eventSource) {
            state.eventSource.close();
            state.eventSource = null;
        }

        try {
            // First, remove from old position
            const removeResponse = await fetch(`${BASE}/${SLUG}/api/attendance/${oldAttendanceId}`, {
                method: 'DELETE'
            });

            if (!removeResponse.ok) {
                throw new Error('Failed to remove from old position');
            }

            // Then assign to new position
            const assignResponse = await fetch(`${BASE}/${SLUG}/api/attendance`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    callout_id: state.callout.id,
                    member_id: memberId,
                    truck_id: newTruckId,
                    position_id: newPositionId
                })
            });

            const data = await assignResponse.json();

            // Update from server response
            if (data.attendance) {
                state.callout.attendance = data.attendance;
            }
            if (data.available_members) {
                state.availableMembers = data.available_members;
            }

            render();
        } catch (error) {
            console.error('Failed to move member:', error);
            alert('Failed to move member. Please try again.');
            await loadData();
        } finally {
            state.isProcessing = false;
            if (state.callout && state.callout.status === 'active') {
                connectSSE();
            }
        }
    }

    // Setup drag listeners
    function setupDragListeners() {
        // Available members - use event delegation
        const availableContainer = elements.availableMembers;
        if (availableContainer) {
            // Mouse events
            availableContainer.addEventListener('mousedown', function(e) {
                const chip = e.target.closest('.member-chip');
                if (!chip) return;

                const memberId = parseInt(chip.dataset.memberId);
                const member = state.availableMembers.find(m => m.id === memberId);
                if (!member) return;

                handleDragStart(e, memberId, member.display_name || member.name, 'available');
            });

            // Touch events
            availableContainer.addEventListener('touchstart', function(e) {
                const chip = e.target.closest('.member-chip');
                if (!chip) return;

                const memberId = parseInt(chip.dataset.memberId);
                const member = state.availableMembers.find(m => m.id === memberId);
                if (!member) return;

                handleDragStart(e, memberId, member.display_name || member.name, 'available');
            }, { passive: true });
        }

        // Trucks container - for dragging from filled positions
        const trucksContainer = elements.trucksContainer;
        if (trucksContainer) {
            // Mouse events
            trucksContainer.addEventListener('mousedown', function(e) {
                // Check if clicking on a filled position slot
                const filledSlot = e.target.closest('.position-slot.filled');
                if (filledSlot) {
                    const attendanceId = filledSlot.dataset.attendanceId;
                    const memberId = parseInt(filledSlot.dataset.memberId);
                    const memberName = filledSlot.querySelector('.member-name')?.textContent || '';

                    if (attendanceId && memberId) {
                        e.preventDefault();
                        handleDragStart(e, memberId, memberName, 'position', parseInt(attendanceId));
                    }
                    return;
                }

                // Check if clicking on a standby member
                const standbyMember = e.target.closest('.standby-member');
                if (standbyMember) {
                    const attendanceId = standbyMember.dataset.attendanceId;
                    const memberId = parseInt(standbyMember.dataset.memberId);
                    const memberName = standbyMember.querySelector('span:first-child')?.textContent || '';

                    if (attendanceId && memberId) {
                        e.preventDefault();
                        handleDragStart(e, memberId, memberName, 'standby', parseInt(attendanceId));
                    }
                }
            });

            // Touch events
            trucksContainer.addEventListener('touchstart', function(e) {
                const filledSlot = e.target.closest('.position-slot.filled');
                if (filledSlot) {
                    const attendanceId = filledSlot.dataset.attendanceId;
                    const memberId = parseInt(filledSlot.dataset.memberId);
                    const memberName = filledSlot.querySelector('.member-name')?.textContent || '';

                    if (attendanceId && memberId) {
                        handleDragStart(e, memberId, memberName, 'position', parseInt(attendanceId));
                    }
                    return;
                }

                const standbyMember = e.target.closest('.standby-member');
                if (standbyMember) {
                    const attendanceId = standbyMember.dataset.attendanceId;
                    const memberId = parseInt(standbyMember.dataset.memberId);
                    const memberName = standbyMember.querySelector('span:first-child')?.textContent || '';

                    if (attendanceId && memberId) {
                        handleDragStart(e, memberId, memberName, 'standby', parseInt(attendanceId));
                    }
                }
            }, { passive: true });
        }

        // Global move and end listeners
        document.addEventListener('mousemove', handleDragMove);
        document.addEventListener('mouseup', handleDragEnd);
        document.addEventListener('touchmove', handleDragMove, { passive: false });
        document.addEventListener('touchend', handleDragEnd);
        document.addEventListener('touchcancel', handleDragEnd);
    }

    // Initialize drag and drop after DOM is ready
    setupDragListeners();

    init();
})();
