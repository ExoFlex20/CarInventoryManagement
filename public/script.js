const API_BASE = '../backend/index.php';

const partsTable = document.getElementById('partsTable');
const movementTable = document.getElementById('movementTable');
const slowTable = document.getElementById('slowTable');
const lowStockList = document.getElementById('lowStockList');
const alertList = document.getElementById('alertList');
const refreshBtn = document.getElementById('refreshBtn');
const apiStatus = document.getElementById('apiStatus');

const statParts = document.getElementById('statParts');
const statUnits = document.getElementById('statUnits');
const statAlerts = document.getElementById('statAlerts');
const statSuppliers = document.getElementById('statSuppliers');
const statValue = document.getElementById('statValue');
const statRes = document.getElementById('statRes');

const partForm = document.getElementById('partForm');
const partId = document.getElementById('partId');
const partName = document.getElementById('partName');
const partSku = document.getElementById('partSku');
const partDesc = document.getElementById('partDesc');
const partQty = document.getElementById('partQty');
const partReorder = document.getElementById('partReorder');
const partPrice = document.getElementById('partPrice');
const partSupplier = document.getElementById('partSupplier');
const partBarcode = document.getElementById('partBarcode');
const partLocation = document.getElementById('partLocation');
const partLead = document.getElementById('partLead');
const partActive = document.getElementById('partActive');
const resetPartForm = document.getElementById('resetPartForm');

const moveForm = document.getElementById('moveForm');
const movePart = document.getElementById('movePart');
const moveQty = document.getElementById('moveQty');
const moveDir = document.getElementById('moveDir');
const moveNote = document.getElementById('moveNote');

const supplierForm = document.getElementById('supplierForm');
const supName = document.getElementById('supName');
const supContact = document.getElementById('supContact');
const supPhone = document.getElementById('supPhone');
const supEmail = document.getElementById('supEmail');
const supAddress = document.getElementById('supAddress');

const loginForm = document.getElementById('loginForm');
const loginUser = document.getElementById('loginUser');
const loginPass = document.getElementById('loginPass');
const authStatus = document.getElementById('authStatus');
const logoutBtn = document.getElementById('logoutBtn');

const reservationForm = document.getElementById('reservationForm');
const resPart = document.getElementById('resPart');
const resQty = document.getElementById('resQty');
const resRef = document.getElementById('resRef');
const resNote = document.getElementById('resNote');

const poForm = document.getElementById('poForm');
const poSupplier = document.getElementById('poSupplier');
const poDate = document.getElementById('poDate');
const poPart = document.getElementById('poPart');
const poQty = document.getElementById('poQty');
const poPrice = document.getElementById('poPrice');
const poNotes = document.getElementById('poNotes');

const importForm = document.getElementById('importForm');
const importCsv = document.getElementById('importCsv');
const exportBtn = document.getElementById('exportBtn');
const attachForm = document.getElementById('attachForm');
const attachEntity = document.getElementById('attachEntity');
const attachId = document.getElementById('attachId');
const attachName = document.getElementById('attachName');
const attachUrl = document.getElementById('attachUrl');
const opTabButtons = document.querySelectorAll('[data-op-tab]');
const opPanes = document.querySelectorAll('[data-op-pane]');

const filterSearch = document.getElementById('filterSearch');
const filterLow = document.getElementById('filterLow');
const filterActive = document.getElementById('filterActive');
const filterSupplier = document.getElementById('filterSupplier');
const prevPage = document.getElementById('prevPage');
const nextPage = document.getElementById('nextPage');
const pageLabel = document.getElementById('pageLabel');

let partsCache = [];
let suppliersCache = [];
let reservationsCache = [];
let authToken = localStorage.getItem('authToken');
let currentPage = 1;

function setToken(token) {
  authToken = token;
  if (token) {
    localStorage.setItem('authToken', token);
    authStatus.textContent = 'Signed in';
    console.log('Token saved:', token.substring(0, 10) + '...');
  } else {
    localStorage.removeItem('authToken');
    authStatus.textContent = 'Not signed in';
    console.log('Token cleared');
  }
}

async function api(path, options = {}) {
  const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
  if (authToken) {
    headers.Authorization = `Bearer ${authToken}`;
  }
  const res = await fetch(`${API_BASE}/${path}`, { ...options, headers });
  if (res.status === 401) {
    setToken(null);
    throw new Error('Session expired. Please login again.');
  }
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error(data.error || `Request failed (${res.status})`);
  }
  return data;
}

function setOpTab(target) {
  opTabButtons.forEach((btn) => {
    const active = btn.dataset.opTab === target;
    btn.classList.toggle('active', active);
    btn.setAttribute('aria-pressed', active ? 'true' : 'false');
  });
  opPanes.forEach((pane) => {
    pane.classList.toggle('active', pane.dataset.opPane === target);
  });
}

async function pingApi() {
  try {
    await api('health');
    apiStatus.textContent = 'API online';
    apiStatus.style.color = '#14cba8';
  } catch (err) {
    apiStatus.textContent = 'API offline';
    apiStatus.style.color = '#ff4d6b';
  }
}

function renderStats(summary = null) {
  statParts.textContent = partsCache.length;
  statUnits.textContent = partsCache.reduce((sum, p) => sum + Number(p.quantity || 0), 0);
  const alerts = partsCache.filter((p) => p.is_low_stock).length;
  statAlerts.textContent = alerts;
  statSuppliers.textContent = suppliersCache.length;
  statRes.textContent = reservationsCache.filter((r) => r.status === 'open').length;
  if (summary) {
    statValue.textContent = `$${Number(summary.inventory_value || 0).toFixed(2)}`;
  }
}

function renderLowStock(listEl, items) {
  listEl.innerHTML = '';
  if (!items.length) {
    listEl.innerHTML = '<span class="muted">All good—no low stock.</span>';
    return;
  }
  items.forEach((p) => {
    const pill = document.createElement('div');
    pill.className = 'pill';
    pill.innerHTML = `<strong>${p.name}</strong><span class="qty">${p.quantity}</span><span class="muted">@ ${p.reorder_level}</span>`;
    listEl.appendChild(pill);
  });
}

function renderParts() {
  partsTable.innerHTML = '';
  partsCache.forEach((p) => {
    const tr = document.createElement('tr');
    const supplier = p.supplier_name ? p.supplier_name : '—';
    const price = p.price ? `$${Number(p.price).toFixed(2)}` : '—';
    const badgeClass = p.is_low_stock ? 'badge danger' : 'badge success';
    const badgeText = p.is_low_stock ? 'Low' : 'OK';
    tr.innerHTML = `
      <td>${p.name}</td>
      <td>${p.sku}</td>
      <td>${p.quantity}</td>
      <td>${p.reorder_level ?? '—'}</td>
      <td>${supplier}</td>
      <td>${price}</td>
      <td>${p.barcode ?? '—'}</td>
      <td>
        <span class="${badgeClass}">${badgeText}</span>
        <button class="action" data-edit="${p.id}">Edit</button>
        <button class="action" data-delete="${p.id}">Delete</button>
      </td>
    `;
    partsTable.appendChild(tr);
  });
}

function renderMovements(items) {
  movementTable.innerHTML = '';
  items.forEach((m) => {
    const tr = document.createElement('tr');
    const badgeClass = m.change_type === 'in' ? 'badge success' : 'badge danger';
    tr.innerHTML = `
      <td>${new Date(m.created_at).toLocaleString()}</td>
      <td>${m.part_name}</td>
      <td><span class="${badgeClass}">${m.change_type}</span></td>
      <td>${m.quantity}</td>
      <td>${m.note ?? ''}</td>
    `;
    movementTable.appendChild(tr);
  });
}

function renderSlow(items) {
  slowTable.innerHTML = '';
  items.forEach((m) => {
    const tr = document.createElement('tr');
    tr.innerHTML = `<td>${m.name ?? m.part_name ?? ''}</td><td>${m.sku}</td><td>${m.moved_out ?? 0}</td>`;
    slowTable.appendChild(tr);
  });
}

function populateSupplierSelects() {
  const selects = [partSupplier, poSupplier, filterSupplier];
  selects.forEach((sel) => {
    sel.innerHTML = '<option value="">-- all/none --</option>';
  });
  suppliersCache.forEach((s) => {
    const opt = document.createElement('option');
    opt.value = s.id;
    opt.textContent = s.name;
    partSupplier.appendChild(opt.cloneNode(true));
    poSupplier.appendChild(opt.cloneNode(true));
    filterSupplier.appendChild(opt.cloneNode(true));
  });
}

function populatePartSelects() {
  const selects = [movePart, resPart, poPart];
  selects.forEach((sel) => {
    sel.innerHTML = '';
  });
  partsCache.forEach((p) => {
    const opt = document.createElement('option');
    opt.value = p.id;
    opt.textContent = `${p.name} (${p.sku})`;
    selects.forEach((sel) => sel.appendChild(opt.cloneNode(true)));
  });
}

function attachRowEvents() {
  partsTable.querySelectorAll('[data-edit]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-edit');
      const part = partsCache.find((p) => String(p.id) === String(id));
      if (!part) return;
      partId.value = part.id;
      partName.value = part.name;
      partSku.value = part.sku;
      partDesc.value = part.description || '';
      partQty.value = part.quantity;
      partReorder.value = part.reorder_level ?? 0;
      partPrice.value = part.price ?? '';
      partSupplier.value = part.supplier_id ?? '';
      partBarcode.value = part.barcode ?? '';
      partLocation.value = part.location ?? '';
      partLead.value = part.lead_time_days ?? 0;
      partActive.checked = part.is_active ?? 1;
    });
  });

  partsTable.querySelectorAll('[data-delete]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-delete');
      if (!confirm('Delete this part?')) return;
      try {
        await api(`parts/${id}`, { method: 'DELETE' });
        await loadParts();
        await loadMovements();
      } catch (err) {
        alert(err.message);
      }
    });
  });
}

async function loadParts(page = 1) {
  currentPage = page;
  const params = new URLSearchParams();
  if (filterSearch.value) params.append('search', filterSearch.value);
  if (filterLow.checked) params.append('low_only', '1');
  if (!filterActive.checked) params.append('active', '0');
  if (filterSupplier.value) params.append('supplier_id', filterSupplier.value);
  params.append('page', String(currentPage));
  params.append('page_size', '25');
  const data = await api(`parts?${params.toString()}`);
  partsCache = data.items || [];
  renderParts();
  renderLowStock(lowStockList, partsCache.filter((p) => p.is_low_stock));
  renderStats();
  populatePartSelects();
  attachRowEvents();
  pageLabel.textContent = `${data.page} / ${Math.max(1, Math.ceil((data.total || 1) / (data.page_size || 25)))}`;
}

async function loadSuppliers() {
  const data = await api('suppliers');
  suppliersCache = data.items || [];
  populateSupplierSelects();
  renderStats();
}

async function loadMovements() {
  const data = await api('stock/movements');
  renderMovements(data.items || []);
}

async function loadReservations() {
  try {
    const data = await api('reservations');
    reservationsCache = data.items || [];
    renderStats();
  } catch (err) {
    reservationsCache = [];
  }
}

async function loadReports() {
  try {
    const summary = await api('reports/summary');
    renderStats(summary);
  } catch (err) {
    // ignore
  }
  try {
    const slow = await api('reports/slow-movers');
    renderSlow(slow.items || []);
  } catch (err) {}
  try {
    const alerts = await api('alerts/low');
    renderLowStock(alertList, alerts.items || []);
  } catch (err) {}
}

function buildPayload(base = {}) {
  return JSON.stringify(base);
}

loginForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  authStatus.textContent = 'Logging in...';
  try {
    const res = await api('auth/login', { method: 'POST', body: buildPayload({ username: loginUser.value, password: loginPass.value }) });
    if (!res.token) {
      throw new Error('No token received from server');
    }
    setToken(res.token);
    authStatus.textContent = 'Loading data...';
    await Promise.all([loadSuppliers(), loadParts(), loadMovements(), loadReservations(), loadReports()]);
    authStatus.textContent = 'Signed in successfully';
  } catch (err) {
    authStatus.textContent = 'Error: ' + err.message;
    alert('Login error: ' + err.message);
  }
});

logoutBtn.addEventListener('click', async () => {
  try {
    await api('auth/logout', { method: 'POST' });
  } catch (err) {
    // ignore
  }
  setToken(null);
});

partForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  const payload = {
    name: partName.value,
    sku: partSku.value,
    description: partDesc.value,
    quantity: Number(partQty.value || 0),
    reorder_level: Number(partReorder.value || 0),
    price: partPrice.value ? Number(partPrice.value) : null,
    supplier_id: partSupplier.value ? Number(partSupplier.value) : null,
    barcode: partBarcode.value || null,
    location: partLocation.value || null,
    lead_time_days: Number(partLead.value || 0),
    is_active: partActive.checked ? 1 : 0,
  };
  try {
    if (partId.value) {
      await api(`parts/${partId.value}`, { method: 'PUT', body: buildPayload(payload) });
    } else {
      await api('parts', { method: 'POST', body: buildPayload(payload) });
    }
    partForm.reset();
    partId.value = '';
    partActive.checked = true;
    await loadParts(currentPage);
  } catch (err) {
    alert(err.message);
  }
});

resetPartForm.addEventListener('click', () => {
  partForm.reset();
  partId.value = '';
  partActive.checked = true;
});

moveForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  const payload = {
    part_id: Number(movePart.value),
    quantity: Number(moveQty.value || 0),
    note: moveNote.value,
  };
  const dir = moveDir.value;
  try {
    await api(`stock/${dir}`, { method: 'POST', body: buildPayload(payload) });
    moveForm.reset();
    await loadParts(currentPage);
    await loadMovements();
  } catch (err) {
    alert(err.message);
  }
});

supplierForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  const payload = {
    name: supName.value,
    contact_name: supContact.value,
    phone: supPhone.value,
    email: supEmail.value,
    address: supAddress.value,
  };
  try {
    await api('suppliers', { method: 'POST', body: buildPayload(payload) });
    supplierForm.reset();
    await loadSuppliers();
  } catch (err) {
    alert(err.message);
  }
});

reservationForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  const payload = {
    part_id: Number(resPart.value),
    reserved_qty: Number(resQty.value || 0),
    reference_code: resRef.value,
    note: resNote.value,
  };
  try {
    await api('reservations', { method: 'POST', body: buildPayload(payload) });
    reservationForm.reset();
    await loadReservations();
    await loadParts(currentPage);
  } catch (err) {
    alert(err.message);
  }
});

poForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  const payload = {
    supplier_id: poSupplier.value ? Number(poSupplier.value) : null,
    expected_date: poDate.value || null,
    notes: poNotes.value,
    items: [{ part_id: Number(poPart.value), qty_ordered: Number(poQty.value || 0), price: poPrice.value ? Number(poPrice.value) : null }],
  };
  try {
    await api('purchase-orders', { method: 'POST', body: buildPayload(payload) });
    poForm.reset();
    await loadParts(currentPage);
    await loadMovements();
  } catch (err) {
    alert(err.message);
  }
});

importForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    await api('parts/import', { method: 'POST', body: buildPayload({ csv: importCsv.value }) });
    alert('Import completed');
    await loadParts(currentPage);
  } catch (err) {
    alert(err.message);
  }
});

exportBtn.addEventListener('click', async () => {
  try {
    const res = await fetch(`${API_BASE}/parts/export`, {
      headers: authToken ? { Authorization: `Bearer ${authToken}` } : {},
    });
    const text = await res.text();
    const blob = new Blob([text], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'parts.csv';
    a.click();
    URL.revokeObjectURL(url);
  } catch (err) {
    alert('Export failed');
  }
});

attachForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  try {
    await api('attachments', {
      method: 'POST',
      body: buildPayload({
        entity_type: attachEntity.value,
        entity_id: Number(attachId.value || 0),
        file_name: attachName.value,
        file_url: attachUrl.value,
      }),
    });
    alert('Attachment saved');
    attachForm.reset();
  } catch (err) {
    alert(err.message);
  }
});

refreshBtn.addEventListener('click', async () => {
  await Promise.all([loadParts(currentPage), loadSuppliers(), loadMovements(), loadReservations(), loadReports(), pingApi()]);
});

opTabButtons.forEach((btn) => {
  btn.addEventListener('click', () => setOpTab(btn.dataset.opTab));
});

setOpTab('parts');

filterSearch.addEventListener('input', () => loadParts(1));
filterLow.addEventListener('change', () => loadParts(1));
filterActive.addEventListener('change', () => loadParts(1));
filterSupplier.addEventListener('change', () => loadParts(1));
prevPage.addEventListener('click', () => {
  if (currentPage > 1) loadParts(currentPage - 1);
});
nextPage.addEventListener('click', () => loadParts(currentPage + 1));

(async function init() {
  setToken(authToken);
  await pingApi();
  // Only load data if already authenticated
  if (authToken) {
    await loadSuppliers();
    await loadParts();
    await loadMovements();
    await loadReservations();
    await loadReports();
  }
})();
