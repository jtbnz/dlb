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
        isProcessing: false
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
        submitBtn: document.getElementById('submit-btn'),
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
        document.getElementById('change-icad-form').addEventListener('submit', handleChangeIcad);
        elements.submitBtn.addEventListener('click', showSubmitModal);
    }

    // Handle new callout creation
    async function handleNewCallout(e) {
        e.preventDefault();
        const icadNumber = document.getElementById('new-icad').value.trim();

        if (!icadNumber) {
            alert('Please enter an ICAD number');
            return;
        }

        try {
            const response = await fetch(`${BASE}/${SLUG}/api/callout`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ icad_number: icadNumber })
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

            // Close SSE connection before redirect
            if (state.eventSource) {
                state.eventSource.close();
                state.eventSource = null;
            }

            // Reset state
            state.callout = null;
            state.availableMembers = [];

            // Show success message then redirect
            alert('Attendance submitted successfully!');

            // Force redirect - use setTimeout to ensure alert has closed
            setTimeout(() => {
                window.location.href = `${BASE}/${SLUG}/attendance`;
            }, 100);
        } catch (error) {
            console.error('Failed to submit:', error);
            alert('Failed to submit attendance. Please try again.');
            state.callout.status = 'active';
        }
    };

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
        const statusText = elements.syncStatus.querySelector('.status-text');

        switch (status) {
            case 'connected': statusText.textContent = 'Connected'; break;
            case 'connecting': statusText.textContent = 'Connecting...'; break;
            case 'offline': statusText.textContent = 'Offline'; break;
        }
    }

    function showLoading() {
        elements.loading.style.display = 'block';
        elements.noCallout.style.display = 'none';
        elements.attendanceArea.style.display = 'none';
    }

    function showNoCallout() {
        elements.loading.style.display = 'none';
        elements.noCallout.style.display = 'block';
        elements.attendanceArea.style.display = 'none';
    }

    function showAttendanceArea() {
        elements.loading.style.display = 'none';
        elements.noCallout.style.display = 'none';
        elements.attendanceArea.style.display = 'flex';

        // Display the ICAD number (Muster-YYYY-MM-DD will show as stored)
        elements.icadNumber.textContent = state.callout.icad_number;
        elements.changeIcadBtn.style.display = 'inline-block';
        elements.submitBtn.disabled = false;

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
                                    <div class="standby-member" onclick="removeAttendance(${m.id})">
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
                <span class="name">${escapeHtml(member.name)}</span>
                <span class="rank">${escapeHtml(member.rank)}</span>
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
        const memberName = member ? member.name : 'Assigning...';

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

    init();
})();
