<?php
ob_start();
?>
<div class="admin-page">
    <div class="page-header">
        <h1>Trucks & Positions</h1>
        <button class="btn btn-primary" onclick="showAddTruckModal()">Add Truck</button>
    </div>

    <p class="drag-hint" style="color: var(--gray-500); font-size: 0.875rem; margin-bottom: 1rem;">
        Drag trucks to reorder them
    </p>

    <div id="trucks-list" class="trucks-admin-list">
        <!-- Trucks loaded via JS -->
    </div>
</div>

<!-- Add/Edit Truck Modal -->
<div id="truck-modal" class="modal" style="display:none;">
    <div class="modal-content">
        <h2 id="truck-modal-title">Add Truck</h2>
        <form id="truck-form">
            <input type="hidden" id="truck-id">
            <div class="form-group">
                <label for="truck-name">Name</label>
                <input type="text" id="truck-name" required placeholder="e.g., Pump 1, Tanker">
            </div>
            <div class="form-group" id="truck-options-group">
                <label>
                    <input type="checkbox" id="truck-is-station"> This is the Station (for standby personnel)
                </label>
            </div>
            <div class="form-group" id="template-group">
                <label for="truck-template">Position Template</label>
                <select id="truck-template">
                    <option value="full">Full Crew (OIC, DR, 1, 2, 3, 4)</option>
                    <option value="medium">Medium (OIC, DR, 1, 2)</option>
                    <option value="light">Light (OIC, DR)</option>
                </select>
            </div>
            <div class="modal-buttons">
                <button type="button" class="btn" onclick="closeTruckModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Add Position Modal -->
<div id="position-modal" class="modal" style="display:none;">
    <div class="modal-content">
        <h2>Add Position</h2>
        <form id="position-form">
            <input type="hidden" id="position-truck-id">
            <div class="form-group">
                <label for="position-name">Position Name</label>
                <input type="text" id="position-name" required placeholder="e.g., OIC, DR, 1">
            </div>
            <div class="form-group">
                <label>
                    <input type="checkbox" id="position-allow-multiple"> Allow multiple members (for standby)
                </label>
            </div>
            <div class="modal-buttons">
                <button type="button" class="btn" onclick="closePositionModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Add</button>
            </div>
        </form>
    </div>
</div>

<script>
const SLUG = '<?= $slug ?>';
let trucks = [];

async function loadTrucks() {
    const response = await fetch(`/${SLUG}/admin/api/trucks`);
    const data = await response.json();
    trucks = data.trucks;
    renderTrucks();
}

function renderTrucks() {
    const container = document.getElementById('trucks-list');

    container.innerHTML = trucks.map(t => `
        <div class="truck-card" data-id="${t.id}" draggable="true">
            <div class="truck-header">
                <span class="drag-handle" style="cursor: grab; padding-right: 0.5rem; color: var(--gray-400);">&#9776;</span>
                <h3 style="flex: 1;">${escapeHtml(t.name)} ${t.is_station ? '<span class="badge">Station</span>' : ''}</h3>
                <div class="truck-actions">
                    <button class="btn-small" onclick="editTruck(${t.id})">Edit</button>
                    <button class="btn-small btn-danger" onclick="deleteTruck(${t.id})">Delete</button>
                </div>
            </div>
            <div class="positions-list">
                <h4>Positions:</h4>
                ${t.positions.map(p => `
                    <div class="position-item">
                        <span>${escapeHtml(p.name)} ${p.allow_multiple ? '<span class="badge">Multiple</span>' : ''}</span>
                        <button class="btn-tiny btn-danger" onclick="deletePosition(${p.id})">×</button>
                    </div>
                `).join('')}
                <button class="btn-small" onclick="showAddPositionModal(${t.id})">+ Add Position</button>
            </div>
        </div>
    `).join('');

    // Setup drag and drop
    setupDragAndDrop();
}

function setupDragAndDrop() {
    const container = document.getElementById('trucks-list');
    const cards = container.querySelectorAll('.truck-card');
    let draggedItem = null;

    cards.forEach(card => {
        card.addEventListener('dragstart', function(e) {
            draggedItem = this;
            this.style.opacity = '0.4';
            e.dataTransfer.effectAllowed = 'move';
        });

        card.addEventListener('dragend', function() {
            this.style.opacity = '1';
            cards.forEach(c => c.classList.remove('drag-over'));
            draggedItem = null;
        });

        card.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
        });

        card.addEventListener('dragenter', function(e) {
            e.preventDefault();
            if (this !== draggedItem) {
                this.classList.add('drag-over');
            }
        });

        card.addEventListener('dragleave', function() {
            this.classList.remove('drag-over');
        });

        card.addEventListener('drop', async function(e) {
            e.preventDefault();
            this.classList.remove('drag-over');

            if (draggedItem && this !== draggedItem) {
                const allCards = [...container.querySelectorAll('.truck-card')];
                const draggedIdx = allCards.indexOf(draggedItem);
                const droppedIdx = allCards.indexOf(this);

                if (draggedIdx < droppedIdx) {
                    this.parentNode.insertBefore(draggedItem, this.nextSibling);
                } else {
                    this.parentNode.insertBefore(draggedItem, this);
                }

                // Save new order
                await saveOrder();
            }
        });
    });
}

async function saveOrder() {
    const container = document.getElementById('trucks-list');
    const cards = container.querySelectorAll('.truck-card');
    const order = [...cards].map(card => parseInt(card.dataset.id));

    await fetch(`/${SLUG}/admin/api/trucks/reorder`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ order })
    });

    // Reload to ensure sync
    loadTrucks();
}

function showAddTruckModal() {
    document.getElementById('truck-modal-title').textContent = 'Add Truck';
    document.getElementById('truck-id').value = '';
    document.getElementById('truck-name').value = '';
    document.getElementById('truck-is-station').checked = false;
    document.getElementById('truck-template').value = 'full';
    document.getElementById('truck-options-group').style.display = 'block';
    document.getElementById('template-group').style.display = 'block';
    document.getElementById('truck-modal').style.display = 'flex';
}

function editTruck(id) {
    const truck = trucks.find(t => t.id === id);
    if (!truck) return;

    document.getElementById('truck-modal-title').textContent = 'Edit Truck';
    document.getElementById('truck-id').value = id;
    document.getElementById('truck-name').value = truck.name;
    document.getElementById('truck-is-station').checked = truck.is_station;
    document.getElementById('truck-options-group').style.display = 'none';
    document.getElementById('template-group').style.display = 'none';
    document.getElementById('truck-modal').style.display = 'flex';
}

function closeTruckModal() {
    document.getElementById('truck-modal').style.display = 'none';
}

document.getElementById('truck-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    const id = document.getElementById('truck-id').value;
    const name = document.getElementById('truck-name').value;
    const isStation = document.getElementById('truck-is-station').checked;
    const template = document.getElementById('truck-template').value;

    const url = id ? `/${SLUG}/admin/api/trucks/${id}` : `/${SLUG}/admin/api/trucks`;
    const method = id ? 'PUT' : 'POST';

    await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name, is_station: isStation ? 1 : 0, template })
    });

    closeTruckModal();
    loadTrucks();
});

async function deleteTruck(id) {
    if (!confirm('Delete this truck and all its positions?')) return;

    await fetch(`/${SLUG}/admin/api/trucks/${id}`, { method: 'DELETE' });
    loadTrucks();
}

function showAddPositionModal(truckId) {
    document.getElementById('position-truck-id').value = truckId;
    document.getElementById('position-name').value = '';
    document.getElementById('position-allow-multiple').checked = false;
    document.getElementById('position-modal').style.display = 'flex';
}

function closePositionModal() {
    document.getElementById('position-modal').style.display = 'none';
}

document.getElementById('position-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    const truckId = document.getElementById('position-truck-id').value;
    const name = document.getElementById('position-name').value;
    const allowMultiple = document.getElementById('position-allow-multiple').checked;

    await fetch(`/${SLUG}/admin/api/trucks/${truckId}/positions`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name, allow_multiple: allowMultiple ? 1 : 0 })
    });

    closePositionModal();
    loadTrucks();
});

async function deletePosition(id) {
    if (!confirm('Delete this position?')) return;

    await fetch(`/${SLUG}/admin/api/positions/${id}`, { method: 'DELETE' });
    loadTrucks();
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

loadTrucks();
</script>
<?php
$content = ob_get_clean();

echo view('layouts/admin', [
    'title' => 'Trucks',
    'brigade' => $brigade,
    'slug' => $slug,
    'content' => $content,
]);
