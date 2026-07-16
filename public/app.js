const formatNumber = new Intl.NumberFormat('de-DE', { maximumFractionDigits: 2 });
const formatDate = new Intl.DateTimeFormat('de-DE', {
  year: '2-digit',
  month: '2-digit',
  day: '2-digit',
  hour: '2-digit',
  minute: '2-digit'
});

const state = {
  meta: null,
  loadedStart: null,
  loadedEnd: null,
  start: null,
  end: null,
  debounce: null,
  drops: [],
  displayedDrops: [],
  rows: [],
  visibleNodes: new Set(),
  hideFastRecoveries: true,
  selectedDropIndex: null,
  chartScale: null
};

const sliderMax = 100000;
const defaultWindowMs = 72 * 60 * 60 * 1000;
const dropFilterWindowMs = 60 * 60 * 1000;
const currentRisingWindowMs = 2 * 60 * 60 * 1000;
const risingTrendPointCount = 4;
const risingMinimumIncrease = 0;
const yAxisMax = 15000;

const colors = {
  74366: '#00a9e8',
  74371: '#16a34a',
  74376: '#ed1c24'
};

const rowColors = {
  74366: 'rgba(0, 169, 232, 0.12)',
  74371: 'rgba(22, 163, 74, 0.12)',
  74376: 'rgba(237, 28, 36, 0.12)'
};

const startRange = document.querySelector('#startRange');
const endRange = document.querySelector('#endRange');
const fromInput = document.querySelector('#fromInput');
const toInput = document.querySelector('#toInput');
const chart = document.querySelector('#weightChart');
const tooltip = document.querySelector('#chartTooltip');

async function requestJson(url, options = {}) {
  const response = await fetch(url, options);
  const payload = await response.json();

  if (!response.ok) {
    throw new Error(payload.detail || payload.error || 'Unbekannter Fehler');
  }

  return payload;
}

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;'
  }[char]));
}

function labelTime(value) {
  return formatDate.format(new Date(Number(value)));
}

function dateInputValue(value) {
  const date = new Date(Number(value));
  const pad = (part) => String(part).padStart(2, '0');

  return [
    date.getFullYear(),
    pad(date.getMonth() + 1),
    pad(date.getDate())
  ].join('-') + `T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function dateInputToMillis(value, fallback) {
  const millis = new Date(value).getTime();

  return Number.isFinite(millis) ? millis : fallback;
}

function setStatus(message) {
  document.querySelector('#chartStatus').textContent = message;
}

function setRangeLabels() {
  document.querySelector('#absoluteMin').textContent = labelTime(state.loadedStart);
  document.querySelector('#absoluteMax').textContent = labelTime(state.loadedEnd);
  document.querySelector('#selectedRange').textContent = `${labelTime(state.start)} - ${labelTime(state.end)}`;
}

function sliderToTime(value) {
  const min = Number(state.loadedStart);
  const max = Number(state.loadedEnd);

  return Math.round(min + (Number(value) / sliderMax) * (max - min));
}

function timeToSlider(value) {
  const min = Number(state.loadedStart);
  const max = Number(state.loadedEnd);

  if (max === min) {
    return 0;
  }

  return Math.round(((Number(value) - min) / (max - min)) * sliderMax);
}

function normalizeRange() {
  state.start = sliderToTime(startRange.value);
  state.end = sliderToTime(endRange.value);

  if (state.start > state.end) {
    [state.start, state.end] = [state.end, state.start];
  }

  setRangeLabels();
}

function groupByNode(rows) {
  return rows.reduce((groups, row) => {
    const key = String(row.nodeId);
    groups[key] = groups[key] || [];
    groups[key].push(row);
    return groups;
  }, {});
}

function renderLegend(nodes) {
  const legend = document.querySelector('#legend');
  legend.innerHTML = '';

  for (const node of nodes) {
    const item = document.createElement('label');
    item.className = 'legendItem';
    item.dataset.nodeId = String(node.id);
    item.innerHTML = `
      <input type="checkbox" value="${node.id}" checked>
      <i style="background:${colors[node.id] || '#637381'}"></i>
      ${node.label}
    `;
    item.querySelector('input').addEventListener('change', (event) => {
      const nodeId = Number(event.target.value);

      if (event.target.checked) {
        state.visibleNodes.add(nodeId);
      } else {
        state.visibleNodes.delete(nodeId);
      }

      renderVisibleData();
    });
    legend.appendChild(item);
  }
}

function visibleRows() {
  return state.rows.filter((row) => (
    state.visibleNodes.has(Number(row.nodeId))
    && Number(row.timestamp) >= state.start
    && Number(row.timestamp) <= state.end
  ));
}

function visibleDrops() {
  const drops = state.drops.filter((drop) => (
    state.visibleNodes.has(Number(drop.nodeId))
    && Number(drop.timestamp) >= state.start
    && Number(drop.timestamp) <= state.end
  ));

  if (!state.hideFastRecoveries) {
    return drops;
  }

  return withoutSecondaryDrops(drops);
}

function withoutSecondaryDrops(drops) {
  const hidden = new Set();
  const ascending = [...drops].sort((left, right) => Number(left.timestamp) - Number(right.timestamp));
  const previousKeptByNode = new Map();

  for (const drop of ascending) {
    const nodeId = Number(drop.nodeId);
    const previous = previousKeptByNode.get(nodeId);
    const followsHigherDrop = previous
      && Number(drop.timestamp) - Number(previous.timestamp) <= dropFilterWindowMs
      && Number(drop.reachedWeight) < Number(previous.reachedWeight);

    if (followsHigherDrop) {
      hidden.add(drop);
      continue;
    }

    previousKeptByNode.set(nodeId, drop);
  }

  return drops.filter((drop) => !hidden.has(drop));
}

function renderVisibleData() {
  const rows = visibleRows();
  const drops = visibleDrops();

  document.querySelector('#rowCount').textContent = formatNumber.format(rows.length);
  state.selectedDropIndex = null;
  updateRisingLegend(rows);
  renderPickupTotals(drops);
  renderDropTable(drops);
  renderChart(rows);
  setStatus(`${rows.length} Messpunkte`);
}

function renderPickupTotals(drops) {
  const container = document.querySelector('#pickupTotals');
  const totalsByNode = new Map();
  let grandTotal = 0;

  for (const drop of drops) {
    const nodeId = Number(drop.nodeId);
    const weight = Number(drop.reachedWeight || 0);
    totalsByNode.set(nodeId, (totalsByNode.get(nodeId) || 0) + weight);
    grandTotal += weight;
  }

  container.innerHTML = '';

  for (const node of state.meta.nodes) {
    const nodeId = Number(node.id);
    const total = totalsByNode.get(nodeId) || 0;
    const item = document.createElement('article');
    item.className = 'pickupTotal';
    item.style.borderColor = colors[nodeId] || '#637381';
    item.innerHTML = `
      <span>${node.label}</span>
      <strong>${formatNumber.format(total)}</strong>
      <small>Summe Abholungen</small>
    `;
    container.appendChild(item);
  }

  const totalItem = document.createElement('article');
  totalItem.className = 'pickupTotal isGrandTotal';
  totalItem.innerHTML = `
    <span>Gesamt</span>
    <strong>${formatNumber.format(grandTotal)}</strong>
    <small>Summe Abholungen</small>
  `;
  container.appendChild(totalItem);
}

function updateRisingLegend(rows) {
  const groups = groupByNode(rows);
  const risingNodes = new Set();
  const latestVisibleTimestamp = rows.reduce(
    (latest, row) => Math.max(latest, Number(row.timestamp)),
    Number.NEGATIVE_INFINITY
  );

  for (const [nodeId, points] of Object.entries(groups)) {
    if (points.length < 2 || !state.visibleNodes.has(Number(nodeId))) {
      continue;
    }

    const latestPoints = [...points].sort((left, right) => Number(left.timestamp) - Number(right.timestamp));
    const trendPoints = latestPoints.slice(-risingTrendPointCount);
    const latest = trendPoints[trendPoints.length - 1];
    const isCurrent = Number(latest.timestamp) >= latestVisibleTimestamp - currentRisingWindowMs;
    const totalIncrease = Number(latest.value) - Number(trendPoints[0].value);
    const isContinuousRise = trendPoints.length >= risingTrendPointCount
      && trendPoints.every((point, index) => index === 0 || Number(point.value) > Number(trendPoints[index - 1].value));

    if (isCurrent && isContinuousRise && totalIncrease >= risingMinimumIncrease) {
      risingNodes.add(Number(nodeId));
    }
  }

  for (const item of document.querySelectorAll('.legendItem')) {
    item.classList.toggle('isRising', risingNodes.has(Number(item.dataset.nodeId)));
  }
}

function renderChart(rows) {
  hideTooltip();
  chart.innerHTML = '';

  const width = 1100;
  const height = 440;
  const padding = { top: 24, right: 28, bottom: 56, left: 64 };
  const minValue = 0;
  const maxValue = yAxisMax;
  const timeMin = state.start;
  const timeMax = state.end === state.start ? state.start + 1 : state.end;
  const plotWidth = width - padding.left - padding.right;
  const plotHeight = height - padding.top - padding.bottom;

  chart.setAttribute('viewBox', `0 0 ${width} ${height}`);

  if (rows.length === 0) {
    const empty = document.createElementNS('http://www.w3.org/2000/svg', 'text');
    empty.setAttribute('x', width / 2);
    empty.setAttribute('y', height / 2);
    empty.setAttribute('text-anchor', 'middle');
    empty.setAttribute('class', 'emptyText');
    empty.textContent = 'Keine Messpunkte im gewaehlten Zeitraum';
    chart.appendChild(empty);
    return;
  }

  const x = (timestamp) => padding.left + ((timestamp - timeMin) / (timeMax - timeMin)) * plotWidth;
  const y = (value) => {
    const clampedValue = Math.min(Math.max(value, minValue), maxValue);

    return padding.top + (1 - ((clampedValue - minValue) / Math.max(maxValue - minValue, 1))) * plotHeight;
  };
  state.chartScale = { x, y, width, height, padding };

  drawGrid(width, height, padding, minValue, maxValue);

  const groups = groupByNode(rows);

  for (const [nodeId, points] of Object.entries(groups)) {
    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', points.map((point, index) => `${index === 0 ? 'M' : 'L'} ${x(point.timestamp)} ${y(point.value)}`).join(' '));
    path.setAttribute('class', 'seriesPath');
    path.setAttribute('style', `stroke:${colors[nodeId] || '#637381'}`);
    chart.appendChild(path);

    for (const point of points.filter((_, index) => index % Math.ceil(points.length / 80) === 0)) {
      const dot = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
      dot.setAttribute('cx', x(point.timestamp));
      dot.setAttribute('cy', y(point.value));
      dot.setAttribute('r', '3');
      dot.setAttribute('class', 'seriesDot');
      dot.setAttribute('style', `stroke:${colors[nodeId] || '#637381'}`);
      chart.appendChild(dot);
    }

    for (const point of points) {
      const hitDot = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
      hitDot.setAttribute('cx', x(point.timestamp));
      hitDot.setAttribute('cy', y(point.value));
      hitDot.setAttribute('r', '8');
      hitDot.setAttribute('class', 'hitDot');
      hitDot.addEventListener('pointerenter', (event) => showTooltip(point, event));
      hitDot.addEventListener('pointermove', (event) => moveTooltip(event));
      hitDot.addEventListener('pointerleave', hideTooltip);
      chart.appendChild(hitDot);
    }
  }

  drawAxisLabels(width, height, padding, minValue, maxValue);
  drawSelectedDrop();
}

function renderDropTable(drops) {
  const body = document.querySelector('#dropTableBody');
  state.displayedDrops = drops;
  body.innerHTML = '';

  if (drops.length === 0) {
    body.innerHTML = '<tr><td colspan="3">Keine Abholungen im Zeitraum</td></tr>';
    return;
  }

  for (const [index, drop] of drops.entries()) {
    const row = document.createElement('tr');
    const weight = formatNumber.format(drop.reachedWeight);
    const time = labelTime(drop.timestamp);
    row.dataset.dropIndex = String(index);
    row.style.backgroundColor = rowColors[drop.nodeId] || 'rgba(99, 115, 129, 0.12)';
    row.addEventListener('click', () => selectDrop(index));

    row.innerHTML = `
      <td>${drop.label}</td>
      <td>${weight}</td>
      <td>${time}</td>
    `;
    body.appendChild(row);
  }

  markSelectedDropRow();
}

function selectDrop(index) {
  state.selectedDropIndex = index;
  markSelectedDropRow();
  drawSelectedDrop();
}

function markSelectedDropRow() {
  for (const row of document.querySelectorAll('#dropTableBody tr')) {
    row.classList.toggle('isSelected', Number(row.dataset.dropIndex) === state.selectedDropIndex);
  }
}

function drawSelectedDrop() {
  chart.querySelector('#selectedDropMarker')?.remove();

  if (state.selectedDropIndex === null || !state.chartScale) {
    return;
  }

  const drop = state.displayedDrops[state.selectedDropIndex];

  if (!drop) {
    return;
  }

  const { x, y, height, padding } = state.chartScale;
  const markerX = x(drop.timestamp);
  const reachedY = y(drop.reachedWeight);
  const afterY = y(drop.afterWeight);
  const color = colors[drop.nodeId] || '#637381';
  const group = document.createElementNS('http://www.w3.org/2000/svg', 'g');
  group.setAttribute('id', 'selectedDropMarker');

  const vertical = document.createElementNS('http://www.w3.org/2000/svg', 'line');
  vertical.setAttribute('x1', markerX);
  vertical.setAttribute('x2', markerX);
  vertical.setAttribute('y1', padding.top);
  vertical.setAttribute('y2', height - padding.bottom);
  vertical.setAttribute('class', 'selectedDropLine');
  vertical.setAttribute('style', `stroke:${color}`);
  group.appendChild(vertical);

  const connector = document.createElementNS('http://www.w3.org/2000/svg', 'line');
  connector.setAttribute('x1', markerX);
  connector.setAttribute('x2', markerX);
  connector.setAttribute('y1', reachedY);
  connector.setAttribute('y2', afterY);
  connector.setAttribute('class', 'selectedDropConnector');
  connector.setAttribute('style', `stroke:${color}`);
  group.appendChild(connector);

  for (const [cy, className] of [[reachedY, 'selectedDropPoint'], [afterY, 'selectedDropPoint after']]) {
    const point = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
    point.setAttribute('cx', markerX);
    point.setAttribute('cy', cy);
    point.setAttribute('r', className.includes('after') ? '5' : '7');
    point.setAttribute('class', className);
    point.setAttribute('style', `stroke:${color}`);
    group.appendChild(point);
  }

  const label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
  label.setAttribute('x', Math.min(markerX + 10, 960));
  label.setAttribute('y', Math.max(22, Math.min(reachedY - 12, height - padding.bottom - 12)));
  label.setAttribute('class', 'selectedDropText');
  label.textContent = `${drop.label}: ${formatNumber.format(drop.reachedWeight)} -> ${formatNumber.format(drop.afterWeight)}`;
  group.appendChild(label);

  chart.appendChild(group);
}

function showTooltip(point, event) {
  tooltip.innerHTML = `
    <strong>${point.label}</strong>
    <span>${formatNumber.format(point.value)}</span>
    <small>${labelTime(point.timestamp)}</small>
  `;
  tooltip.hidden = false;
  tooltip.style.display = 'grid';
  moveTooltip(event);
}

function moveTooltip(event) {
  tooltip.style.left = `${event.clientX + 14}px`;
  tooltip.style.top = `${event.clientY + 14}px`;
}

function hideTooltip() {
  tooltip.hidden = true;
  tooltip.style.display = 'none';
}

function drawGrid(width, height, padding, minValue, maxValue) {
  const plotWidth = width - padding.left - padding.right;
  const plotHeight = height - padding.top - padding.bottom;

  for (let index = 0; index <= 4; index += 1) {
    const y = padding.top + (index / 4) * plotHeight;
    const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    line.setAttribute('x1', padding.left);
    line.setAttribute('x2', padding.left + plotWidth);
    line.setAttribute('y1', y);
    line.setAttribute('y2', y);
    line.setAttribute('class', 'gridLine');
    chart.appendChild(line);

    const value = maxValue - (index / 4) * (maxValue - minValue);
    const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
    text.setAttribute('x', padding.left - 12);
    text.setAttribute('y', y + 4);
    text.setAttribute('text-anchor', 'end');
    text.setAttribute('class', 'axisText');
    text.textContent = formatNumber.format(value);
    chart.appendChild(text);
  }

  const axis = document.createElementNS('http://www.w3.org/2000/svg', 'path');
  axis.setAttribute('d', `M ${padding.left} ${padding.top} V ${height - padding.bottom} H ${width - padding.right}`);
  axis.setAttribute('class', 'axis');
  chart.appendChild(axis);
}

function drawAxisLabels(width, height, padding, minValue, maxValue) {
  const left = document.createElementNS('http://www.w3.org/2000/svg', 'text');
  left.setAttribute('x', padding.left);
  left.setAttribute('y', height - 20);
  left.setAttribute('class', 'axisText');
  left.textContent = labelTime(state.start);
  chart.appendChild(left);

  const right = document.createElementNS('http://www.w3.org/2000/svg', 'text');
  right.setAttribute('x', width - padding.right);
  right.setAttribute('y', height - 20);
  right.setAttribute('text-anchor', 'end');
  right.setAttribute('class', 'axisText');
  right.textContent = labelTime(state.end);
  chart.appendChild(right);

  const unit = document.createElementNS('http://www.w3.org/2000/svg', 'text');
  unit.setAttribute('x', padding.left);
  unit.setAttribute('y', 14);
  unit.setAttribute('class', 'axisText');
  unit.textContent = `Gewicht ${formatNumber.format(minValue)} - ${formatNumber.format(maxValue)}`;
  chart.appendChild(unit);
}

async function loadSeries() {
  setStatus('Laden...');
  const metaMin = Number(state.meta.min);
  const metaMax = Number(state.meta.max);
  state.loadedStart = Math.max(metaMin, dateInputToMillis(fromInput.value, metaMin));
  state.loadedEnd = Math.min(metaMax, dateInputToMillis(toInput.value, metaMax));

  if (state.loadedStart > state.loadedEnd) {
    [state.loadedStart, state.loadedEnd] = [state.loadedEnd, state.loadedStart];
  }

  state.start = state.loadedStart;
  state.end = state.loadedEnd;
  startRange.value = 0;
  endRange.value = sliderMax;
  setRangeLabels();

  const payload = await requestJson(`/weights.php?action=data&start=${state.loadedStart}&end=${state.loadedEnd}`);
  state.rows = payload.data;
  state.drops = payload.drops;
  document.querySelector('#rangeStatus').textContent = `${formatNumber.format(state.rows.length)} Messpunkte geladen`;
  renderVisibleData();
}

function scheduleLoad() {
  window.clearTimeout(state.debounce);
  normalizeRange();
  state.debounce = window.setTimeout(() => {
    renderVisibleData();
  }, 250);
}

function showError(error) {
  setStatus('Fehler');
  chart.innerHTML = '';
  const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
  text.setAttribute('x', '50%');
  text.setAttribute('y', '50%');
  text.setAttribute('text-anchor', 'middle');
  text.setAttribute('class', 'emptyText');
  text.textContent = error.message;
  chart.appendChild(text);
  document.querySelector('#dropTableBody').innerHTML = '<tr><td colspan="3">Fehler</td></tr>';
}

const pressState = {
  context: { presses: [], users: [] },
  snapshot: { presses: [], serverTime: null },
  selectedUser: localStorage.getItem('scrapview.pressUser') || '',
  selectedPress: localStorage.getItem('scrapview.pressPress') || '',
  orders: {},
  orderQueries: {},
  selectedOrders: {},
  orderTimers: {},
  adminCode: sessionStorage.getItem('scrapview.pressAdminCode') || '',
  adminMappings: null,
  pollTimer: null,
  clockTimer: null
};

const pressBoard = document.querySelector('#pressBoard');
const pressUserSelect = document.querySelector('#pressUserSelect');
const pressWorkplaceSelect = document.querySelector('#pressWorkplaceSelect');
const pressLiveStatus = document.querySelector('#pressLiveStatus');
const pressAdminButton = document.querySelector('#pressAdminButton');

function setView(viewId) {
  document.querySelectorAll('.moduleView').forEach((view) => {
    view.classList.toggle('isActive', view.id === viewId);
  });

  document.querySelectorAll('.moduleTab').forEach((button) => {
    button.classList.toggle('isActive', button.dataset.view === viewId);
  });
}

function requestedView() {
  const hashView = window.location.hash.replace('#', '');

  return document.getElementById(hashView) ? hashView : 'analyticsView';
}

function pressStatusLabel(status) {
  return {
    active: 'Laeuft',
    paused: 'Stoerung / Pause',
    finished: 'Beendet'
  }[status] || 'Bereit';
}

function durationLabel(milliseconds) {
  const totalSeconds = Math.max(0, Math.floor(Number(milliseconds || 0) / 1000));
  const hours = Math.floor(totalSeconds / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;
  const pad = (value) => String(value).padStart(2, '0');

  return `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
}

function dateLabel(value) {
  if (!value) {
    return '-';
  }

  const date = new Date(value);

  return Number.isNaN(date.getTime()) ? String(value) : formatDate.format(date);
}

function runElapsedMs(run) {
  if (!run?.startedAt) {
    return 0;
  }

  const end = run.endedAt ? new Date(run.endedAt).getTime() : Date.now();
  const start = new Date(run.startedAt).getTime();
  const paused = Number(run.pausedMs || 0);

  return Math.max(0, end - start - paused);
}

function selectedUser() {
  return pressUserSelect ? pressUserSelect.value : pressState.selectedUser || '';
}

function selectedPress() {
  return pressWorkplaceSelect ? pressWorkplaceSelect.value : pressState.selectedPress || '';
}

function fillPressContext() {
  if (!pressUserSelect || !pressWorkplaceSelect) {
    return;
  }

  const workplace = pressState.context.workplace || {};
  const mappingAvailable = Boolean(workplace.mappingAvailable);
  const allowedPressIds = new Set(workplace.allowedPressIds || []);
  const availablePresses = mappingAvailable
    ? pressState.context.presses.filter((press) => allowedPressIds.has(press.id))
    : pressState.context.presses;

  pressUserSelect.innerHTML = '<option value="">Schicht waehlen</option>' + pressState.context.users
    .map((user) => `<option value="${escapeHtml(user)}">${escapeHtml(user)}</option>`)
    .join('');
  pressWorkplaceSelect.innerHTML = '<option value="">' + (mappingAvailable && availablePresses.length === 0 ? 'Keine Presse fuer diesen PC zugeordnet' : 'Presse waehlen') + '</option>' + pressState.context.presses
    .filter((press) => availablePresses.some((available) => available.id === press.id))
    .map((press) => `<option value="${escapeHtml(press.id)}">${escapeHtml(press.label)}</option>`)
    .join('');

  if (pressState.selectedUser && pressState.context.users.includes(pressState.selectedUser)) {
    pressUserSelect.value = pressState.selectedUser;
  }

  if (!pressUserSelect.value) {
    pressState.selectedUser = '';
    localStorage.removeItem('scrapview.pressUser');
  }

  if (pressState.selectedPress && availablePresses.some((press) => press.id === pressState.selectedPress)) {
    pressWorkplaceSelect.value = pressState.selectedPress;
  }

  if (!pressWorkplaceSelect.value && availablePresses[0]) {
    pressWorkplaceSelect.value = availablePresses[0].id;
    pressState.selectedPress = pressWorkplaceSelect.value;
  }

  if (!pressWorkplaceSelect.value) {
    pressState.selectedPress = '';
    localStorage.removeItem('scrapview.pressPress');
  }

  pressWorkplaceSelect.disabled = mappingAvailable && availablePresses.length <= 1;
}

async function loadPressContext() {
  const payload = await requestJson('/press-api.php?action=context');
  pressState.context = payload.data;
  fillPressContext();
}

async function loadPressOrders(pressId, query = '') {
  if (!pressId) {
    return;
  }

  const payload = await requestJson(`/press-api.php?action=orders&press=${encodeURIComponent(pressId)}&query=${encodeURIComponent(query)}`);
  pressState.orders[pressId] = payload.data || [];

  if (pressState.selectedOrders[pressId] && !pressState.orders[pressId].some((order) => order.id === pressState.selectedOrders[pressId].id)) {
    pressState.selectedOrders[pressId] = null;
  }

  renderPressBoard();
}

async function loadPressSnapshot({ quiet = false } = {}) {
  const payload = await requestJson('/press-api.php?action=snapshot');
  pressState.snapshot = payload.data;

  if (pressLiveStatus) {
    pressLiveStatus.textContent = `Live aktualisiert: ${dateLabel(pressState.snapshot.serverTime)}`;
  }

  renderPressBoard();

  if (!quiet) {
    updatePressTimers();
  }
}

function selectedOrderForPress(pressId) {
  const selected = pressState.selectedOrders[pressId];
  const orders = pressState.orders[pressId] || [];

  if (selected && orders.some((order) => order.id === selected.id)) {
    return selected;
  }

  return null;
}

function renderPressBoard() {
  if (!pressBoard) {
    return;
  }

  const activeElement = document.activeElement;
  const activeAction = activeElement?.dataset?.action;
  const activePressId = activeElement?.dataset?.pressId;
  const activeSelection = typeof activeElement?.selectionStart === 'number' ? activeElement.selectionStart : null;
  pressBoard.innerHTML = '';

  for (const press of pressState.snapshot.presses || []) {
    const activeRun = press.activeRun;
    const canOperate = selectedPress() === press.id && selectedUser() !== '';
    const card = document.createElement('article');
    card.className = 'pressCard';
    card.classList.toggle('isRunning', activeRun?.status === 'active');
    card.classList.toggle('isPaused', activeRun?.status === 'paused');
    card.classList.toggle('isWorkplace', selectedPress() === press.id);

    card.innerHTML = `
      <header class="pressCardHeader">
        <div>
          <h3>${escapeHtml(press.label)}</h3>
          <span>${selectedPress() === press.id ? 'Dieser Arbeitsplatz' : 'Live-Status'}</span>
        </div>
        <div class="pressCardTools">
          <strong class="pressStatus">${escapeHtml(pressStatusLabel(activeRun?.status))}</strong>
          <button type="button" class="historyButton" data-action="history" data-press-id="${escapeHtml(press.id)}">History</button>
        </div>
      </header>
      ${activeRun ? renderActiveRun(press, activeRun, canOperate) : renderOrderPicker(press, canOperate)}
    `;

    pressBoard.appendChild(card);
  }

  if (activeAction && activePressId) {
    const restored = pressBoard.querySelector(`[data-action="${activeAction}"][data-press-id="${activePressId}"]`);
    restored?.focus();
    if (activeSelection !== null && typeof restored?.setSelectionRange === 'function') {
      restored.setSelectionRange(activeSelection, activeSelection);
    }
  }
}

function renderActiveRun(press, run, canOperate) {
  const disabled = canOperate ? '' : 'disabled';
  const pauseButton = run.status === 'paused'
    ? `<button type="button" data-action="resume" data-press-id="${escapeHtml(press.id)}" ${disabled}>Fortsetzen</button>`
    : `<button type="button" class="warnButton" data-action="pause" data-press-id="${escapeHtml(press.id)}" ${disabled}>Pausieren</button>`;

  return `
    <section class="activeRun">
      <div>
        <span>Fertigungsauftrag</span>
        <strong>${escapeHtml(run.orderLabel || run.orderId)}</strong>
        <small>${escapeHtml(run.description || run.material || '-')}</small>
      </div>
      <div class="runTimer" data-run-id="${escapeHtml(run.id)}">${durationLabel(runElapsedMs(run))}</div>
      <dl class="runFacts">
        <div><dt>Start</dt><dd>${escapeHtml(dateLabel(run.startedAt))}</dd></div>
        <div><dt>Schicht</dt><dd>${escapeHtml(run.startedBy || '-')}</dd></div>
      </dl>
      <div class="runActions">
        ${pauseButton}
        <button type="button" class="finishButton" data-action="finish" data-press-id="${escapeHtml(press.id)}" ${disabled}>Beenden</button>
      </div>
      ${canOperate ? '' : '<p class="pressHint">Bedienung nur am gewaehlten Arbeitsplatz moeglich.</p>'}
    </section>
  `;
}

function renderOrderPicker(press, canOperate) {
  const orders = pressState.orders[press.id] || [];
  const selectedOrder = selectedOrderForPress(press.id);
  const query = pressState.orderQueries[press.id] || '';
  const showResults = canOperate && query.trim() !== '';

  return `
    <section class="orderPicker">
      <label>
        Fertigungsauftrag suchen
        <input type="search" data-action="order-query" data-press-id="${escapeHtml(press.id)}" value="${escapeHtml(query)}" placeholder="AUFNR oder MATNR eingeben" autocomplete="off" ${canOperate ? '' : 'disabled'}>
      </label>
      ${showResults ? renderOrderAutocomplete(press.id, orders) : ''}
      ${selectedOrder ? `
        <div class="selectedOrder">
          <span>Ausgewaehlt</span>
          <strong>${escapeHtml(selectedOrder.label || selectedOrder.id)}</strong>
          <small>${escapeHtml(selectedOrder.description || selectedOrder.material || '-')}</small>
        </div>
      ` : '<p class="pressHint">Bitte einen Auftrag aus der Suche auswaehlen.</p>'}
      <button type="button" data-action="start" data-press-id="${escapeHtml(press.id)}" ${canOperate && selectedOrder ? '' : 'disabled'}>Auftrag starten</button>
      ${canOperate ? '' : '<p class="pressHint">Bitte Schicht und diese Presse als Arbeitsplatz waehlen.</p>'}
    </section>
  `;
}

function renderOrderAutocomplete(pressId, orders) {
  if (orders.length === 0) {
    return '<div class="orderAutocomplete"><p>Keine Treffer fuer AUFNR oder MATNR.</p></div>';
  }

  return `
    <div class="orderAutocomplete">
      ${orders.map((order) => `
        <button type="button" data-action="order-pick" data-press-id="${escapeHtml(pressId)}" data-order-id="${escapeHtml(order.id)}">
          <strong>${escapeHtml(order.id)}</strong>
          <span>${escapeHtml(order.material || '-')}</span>
          <small>${escapeHtml(order.description || '')}</small>
        </button>
      `).join('')}
    </div>
  `;
}

function renderPressHistoryRows(history) {
  if (!history.length) {
    return '<p>Noch keine abgeschlossenen Auftraege.</p>';
  }

  return `
    <div class="pressHistoryList">
      ${history.map((run) => `
        <article>
          <div>
            <strong>${escapeHtml(run.orderId)}</strong>
            <span>${escapeHtml(run.material || '-')}</span>
          </div>
          <dl>
            <div><dt>Start</dt><dd>${escapeHtml(dateLabel(run.startedAt))}</dd></div>
            <div><dt>Ende</dt><dd>${escapeHtml(dateLabel(run.endedAt))}</dd></div>
            <div><dt>Dauer</dt><dd>${escapeHtml(durationLabel(run.elapsedMs))}</dd></div>
            <div><dt>Schicht</dt><dd>${escapeHtml(run.startedBy || '-')}</dd></div>
          </dl>
        </article>
      `).join('')}
    </div>
  `;
}

function openPressHistory(pressId) {
  const press = (pressState.snapshot.presses || []).find((item) => item.id === pressId);
  if (!press) {
    return;
  }

  document.querySelector('.pressHistoryBackdrop')?.remove();
  const backdrop = document.createElement('div');
  backdrop.className = 'pressHistoryBackdrop';
  backdrop.innerHTML = `
    <section class="pressHistoryDialog" role="dialog" aria-modal="true" aria-labelledby="pressHistoryTitle">
      <header>
        <div>
          <span>History</span>
          <h3 id="pressHistoryTitle">${escapeHtml(press.label)}</h3>
        </div>
        <button type="button" class="closeHistoryButton" data-action="close-history">Schliessen</button>
      </header>
      ${renderPressHistoryRows(press.history || [])}
    </section>
  `;

  const close = () => backdrop.remove();
  backdrop.addEventListener('click', (event) => {
    if (event.target === backdrop || event.target.closest('[data-action="close-history"]')) {
      close();
    }
  });

  document.body.appendChild(backdrop);
  backdrop.querySelector('[data-action="close-history"]')?.focus();
}

function pressAdminHeaders() {
  return {
    'Content-Type': 'application/json',
    'X-Press-Admin-Code': pressState.adminCode
  };
}

async function loadPressAdminMappings() {
  const payload = await requestJson('/press-api.php?action=adminWorkplaces', {
    headers: { 'X-Press-Admin-Code': pressState.adminCode }
  });
  pressState.adminMappings = payload.data || [];
}

async function savePressAdminMapping(payload) {
  const response = await requestJson('/press-api.php?action=saveWorkplace', {
    method: 'POST',
    headers: pressAdminHeaders(),
    body: JSON.stringify(payload)
  });
  pressState.adminMappings = response.data || [];
}

async function deletePressAdminMapping(id) {
  const response = await requestJson('/press-api.php?action=deleteWorkplace', {
    method: 'POST',
    headers: pressAdminHeaders(),
    body: JSON.stringify({ id })
  });
  pressState.adminMappings = response.data || [];
}

function renderPressAdminMappings() {
  if (!pressState.adminMappings?.length) {
    return '<p class="pressAdminEmpty">Noch keine Arbeitsplatz-Zuordnungen vorhanden.</p>';
  }

  return `
    <div class="pressAdminTableWrap">
      <table class="pressAdminTable">
        <thead>
          <tr><th>Hostname</th><th>Presse</th><th>Arbeitsplatz</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          ${pressState.adminMappings.map((mapping) => `
            <tr>
              <td><strong>${escapeHtml(mapping.hostname)}</strong></td>
              <td>${escapeHtml(mapping.pressLabel || mapping.pressId)}</td>
              <td>${escapeHtml(mapping.workplaceLabel || '-')}</td>
              <td>${mapping.isActive ? 'Aktiv' : 'Inaktiv'}</td>
              <td>
                <div class="pressAdminActions">
                  <button type="button" data-action="admin-edit" data-id="${escapeHtml(mapping.id)}">Bearbeiten</button>
                  <button type="button" class="dangerButton" data-action="admin-delete" data-id="${escapeHtml(mapping.id)}">Entfernen</button>
                </div>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>
  `;
}

function renderPressAdminDialog(error = '') {
  document.querySelector('.pressAdminBackdrop')?.remove();

  const isUnlocked = Array.isArray(pressState.adminMappings);
  const pressOptions = pressState.context.presses
    .map((press) => `<option value="${escapeHtml(press.id)}">${escapeHtml(press.label)}</option>`)
    .join('');
  const backdrop = document.createElement('div');
  backdrop.className = 'pressAdminBackdrop';
  backdrop.innerHTML = `
    <section class="pressAdminDialog" role="dialog" aria-modal="true" aria-labelledby="pressAdminTitle">
      <header>
        <div>
          <span>Admin</span>
          <h3 id="pressAdminTitle">Arbeitsplaetze zu Pressen</h3>
        </div>
        <button type="button" class="closeHistoryButton" data-action="admin-close">Schliessen</button>
      </header>
      ${error ? `<p class="pressAdminError">${escapeHtml(error)}</p>` : ''}
      <form class="pressAdminCodeForm" data-action="admin-unlock">
        <label>
          Admin-Code
          <input type="password" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" name="code" value="${escapeHtml(pressState.adminCode)}" placeholder="0000" required>
        </label>
        <button type="submit">${isUnlocked ? 'Neu laden' : 'Entsperren'}</button>
      </form>
      ${isUnlocked ? `
        <form class="pressAdminForm" data-action="admin-save">
          <input type="hidden" name="id" value="">
          <label>
            Hostname
            <input type="text" name="hostname" placeholder="PRESS-PC-01" required>
          </label>
          <label>
            Presse
            <select name="pressId" required>${pressOptions}</select>
          </label>
          <label>
            Arbeitsplatzname
            <input type="text" name="workplaceLabel" placeholder="Arbeitsplatz Presse 1">
          </label>
          <label class="pressAdminCheck">
            <input type="checkbox" name="isActive" checked>
            Aktiv
          </label>
          <div class="pressAdminFormActions">
            <button type="submit">Speichern</button>
            <button type="button" class="secondaryButton" data-action="admin-reset">Neu</button>
          </div>
        </form>
        ${renderPressAdminMappings()}
      ` : ''}
    </section>
  `;

  backdrop.addEventListener('click', async (event) => {
    if (event.target === backdrop || event.target.closest('[data-action="admin-close"]')) {
      backdrop.remove();
      return;
    }

    const button = event.target.closest('button[data-action]');
    if (!button) {
      return;
    }

    const form = backdrop.querySelector('.pressAdminForm');
    const action = button.dataset.action;

    try {
      if (action === 'admin-reset') {
        form?.reset();
        if (form?.elements.id) {
          form.elements.id.value = '';
        }
      } else if (action === 'admin-edit') {
        const mapping = pressState.adminMappings.find((item) => String(item.id) === String(button.dataset.id));
        if (mapping && form) {
          form.elements.id.value = mapping.id;
          form.elements.hostname.value = mapping.hostname;
          form.elements.pressId.value = mapping.pressId;
          form.elements.workplaceLabel.value = mapping.workplaceLabel || '';
          form.elements.isActive.checked = Boolean(mapping.isActive);
          form.elements.hostname.focus();
        }
      } else if (action === 'admin-delete') {
        button.disabled = true;
        await deletePressAdminMapping(Number(button.dataset.id));
        renderPressAdminDialog();
        await loadPressContext();
        await loadPressSnapshot({ quiet: true });
      }
    } catch (caught) {
      renderPressAdminDialog(caught.message);
    }
  });

  backdrop.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.target;

    try {
      if (form.dataset.action === 'admin-unlock') {
        const code = String(form.elements.code.value || '').trim();
        if (!/^\d{4}$/.test(code)) {
          throw new Error('Bitte einen vierstelligen Code eingeben.');
        }
        pressState.adminCode = code;
        sessionStorage.setItem('scrapview.pressAdminCode', code);
        await loadPressAdminMappings();
        renderPressAdminDialog();
      } else if (form.dataset.action === 'admin-save') {
        await savePressAdminMapping({
          id: form.elements.id.value ? Number(form.elements.id.value) : 0,
          hostname: form.elements.hostname.value,
          pressId: form.elements.pressId.value,
          workplaceLabel: form.elements.workplaceLabel.value,
          isActive: form.elements.isActive.checked
        });
        renderPressAdminDialog();
        await loadPressContext();
        await loadPressSnapshot({ quiet: true });
      }
    } catch (caught) {
      if (form.dataset.action === 'admin-unlock') {
        sessionStorage.removeItem('scrapview.pressAdminCode');
        pressState.adminCode = '';
        pressState.adminMappings = null;
      }
      renderPressAdminDialog(caught.message);
    }
  });

  document.body.appendChild(backdrop);
  backdrop.querySelector(isUnlocked ? '.pressAdminForm input[name="hostname"]' : '.pressAdminCodeForm input')?.focus();
}

function updatePressTimers() {
  for (const runElement of document.querySelectorAll('[data-run-id]')) {
    const runId = Number(runElement.dataset.runId);
    const run = (pressState.snapshot.presses || [])
      .map((press) => press.activeRun)
      .find((item) => Number(item?.id) === runId);

    if (run) {
      runElement.textContent = durationLabel(runElapsedMs(run));
    }
  }
}

async function postPressAction(action, pressId, order = null) {
  const payload = {
    pressId,
    user: selectedUser()
  };

  if (order) {
    payload.order = order;
  }

  const response = await requestJson(`/press-api.php?action=${encodeURIComponent(action)}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });
  pressState.snapshot = response.data;
  renderPressBoard();
}

function setupPressEvents() {
  document.querySelectorAll('.moduleTab').forEach((button) => {
    button.addEventListener('click', () => {
      setView(button.dataset.view);
      window.location.hash = button.dataset.view;
    });
  });

  pressUserSelect?.addEventListener('change', () => {
    pressState.selectedUser = pressUserSelect.value;
    localStorage.setItem('scrapview.pressUser', pressState.selectedUser);
    renderPressBoard();
  });

  pressWorkplaceSelect?.addEventListener('change', () => {
    pressState.selectedPress = pressWorkplaceSelect.value;
    localStorage.setItem('scrapview.pressPress', pressState.selectedPress);
    loadPressOrders(pressState.selectedPress, pressState.orderQueries[pressState.selectedPress] || '').catch(showPressError);
    renderPressBoard();
  });

  pressAdminButton?.addEventListener('click', () => {
    renderPressAdminDialog();
  });

  pressBoard?.addEventListener('input', (event) => {
    const target = event.target;
    if (!target.matches('[data-action="order-query"]')) {
      return;
    }

    const pressId = target.dataset.pressId;
    pressState.orderQueries[pressId] = target.value;
    pressState.selectedOrders[pressId] = null;
    window.clearTimeout(pressState.orderTimers[pressId]);
    pressState.orderTimers[pressId] = window.setTimeout(() => {
      loadPressOrders(pressId, pressState.orderQueries[pressId]).catch(showPressError);
    }, 300);
  });

  pressBoard?.addEventListener('click', async (event) => {
    const button = event.target.closest('button[data-action]');
    if (!button) {
      return;
    }

    const pressId = button.dataset.pressId;
    const action = button.dataset.action;

    try {
      if (action === 'order-pick') {
        const order = (pressState.orders[pressId] || []).find((item) => item.id === button.dataset.orderId);
        if (order) {
          pressState.selectedOrders[pressId] = order;
          pressState.orderQueries[pressId] = `${order.id} ${order.material || ''}`.trim();
          renderPressBoard();
        }
        return;
      }

      if (action === 'history') {
        openPressHistory(pressId);
        return;
      }

      button.disabled = true;
      if (action === 'start') {
        await postPressAction('start', pressId, selectedOrderForPress(pressId));
      } else {
        await postPressAction(action, pressId);
      }
    } catch (error) {
      showPressError(error);
    }
  });
}

function showPressError(error) {
  if (pressLiveStatus) {
    pressLiveStatus.textContent = error.message;
  }
}

async function bootPresses() {
  const pressTab = document.querySelector('.moduleTab[data-view="pressView"]');
  const directPressAccess = requestedView() === 'pressView';
  if (!pressBoard || (pressTab?.hidden && !directPressAccess)) {
    return;
  }

  setupPressEvents();
  await loadPressContext();
  await loadPressSnapshot();
  await loadPressOrders(selectedPress(), pressState.orderQueries[selectedPress()] || '');

  pressState.clockTimer = window.setInterval(updatePressTimers, 1000);
  pressState.pollTimer = window.setInterval(() => {
    loadPressSnapshot({ quiet: true }).catch(showPressError);
  }, 2000);
}

async function boot() {
  state.meta = await requestJson('/weights.php?action=meta');
  state.loadedStart = Math.max(Number(state.meta.min), Number(state.meta.max) - defaultWindowMs);
  state.loadedEnd = Number(state.meta.max);
  state.start = state.loadedStart;
  state.end = state.loadedEnd;
  fromInput.value = dateInputValue(state.loadedStart);
  toInput.value = dateInputValue(state.loadedEnd);
  startRange.value = 0;
  endRange.value = sliderMax;
  state.visibleNodes = new Set(state.meta.nodes.map((node) => Number(node.id)));

  renderLegend(state.meta.nodes);
  document.querySelector('#rangeStatus').textContent = `${formatNumber.format(state.meta.rows)} Messpunkte gesamt`;
  setRangeLabels();
  await loadSeries();
}

startRange.addEventListener('input', scheduleLoad);
endRange.addEventListener('input', scheduleLoad);
document.querySelector('#refreshButton').addEventListener('click', () => loadSeries().catch(showError));
document.querySelector('#dropFilter').addEventListener('change', (event) => {
  state.hideFastRecoveries = event.target.checked;
  renderVisibleData();
});
chart.addEventListener('pointerleave', hideTooltip);
chart.addEventListener('pointermove', (event) => {
  if (!event.target.classList?.contains('hitDot')) {
    hideTooltip();
  }
});
window.addEventListener('scroll', hideTooltip, true);
setView(requestedView());

boot().catch(showError);
bootPresses().catch(showPressError);
