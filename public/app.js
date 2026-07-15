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

async function requestJson(url) {
  const response = await fetch(url);
  const payload = await response.json();

  if (!response.ok) {
    throw new Error(payload.detail || payload.error || 'Unbekannter Fehler');
  }

  return payload;
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

boot().catch(showError);
