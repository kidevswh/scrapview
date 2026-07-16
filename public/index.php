<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ScrapAnalytics</title>
    <link rel="stylesheet" href="/style.css">
</head>
<body>
    <main class="shell">
        <header class="topbar">
            <div>
                <h1>ScrapAnalytics</h1>
            </div>
            <img class="brandLogo" src="/images/LogoSWH.png" alt="Sungwoo Hitech">
        </header>

        <nav class="moduleNav" aria-label="Ansichten">
            <button class="moduleTab isActive" type="button" data-view="analyticsView">Ausschussanalyse</button>
            <button class="moduleTab" type="button" data-view="pressView" hidden>Pressenauftraege</button>
        </nav>

        <section id="analyticsView" class="moduleView isActive">
        <section class="metrics" aria-label="Kennzahlen">
            <article>
                <span>Zeitraum von</span>
                <input id="fromInput" class="dateInput" type="datetime-local">
            </article>
            <article>
                <span>Zeitraum bis</span>
                <input id="toInput" class="dateInput" type="datetime-local">
            </article>
            <article>
                <span>Messpunkte</span>
                <div class="metricAction">
                    <strong id="rowCount">-</strong>
                    <button id="refreshButton" type="button">Daten laden</button>
                </div>
            </article>
        </section>

        <section class="panel rangePanel">
            <div class="panelHeader">
                <h2>Zoom</h2>
                <span id="rangeStatus">Laden...</span>
            </div>
            <div class="rangeControl">
                <label>
                    Start
                    <input id="startRange" type="range" min="0" max="100000" value="0">
                </label>
                <label>
                    Ende
                    <input id="endRange" type="range" min="0" max="100000" value="100000">
                </label>
            </div>
            <div class="rangeLabels">
                <span id="absoluteMin">-</span>
                <span id="selectedRange">-</span>
                <span id="absoluteMax">-</span>
            </div>
        </section>

        <section class="panel chartPanel">
            <div class="panelHeader">
                <h2>Gewichtsaufbau der Container</h2>
                <span id="chartStatus">Bereit</span>
            </div>
            <div id="legend" class="legend"></div>
            <div class="chartLayout">
                <div class="chartWrap">
                    <svg id="weightChart" class="weightChart" role="img" aria-label="Gewichtsverlauf"></svg>
                    <div id="chartTooltip" class="chartTooltip" hidden></div>
                    <div id="pickupTotals" class="pickupTotals" aria-label="Summen der Abholungen"></div>
                </div>
                <aside class="dropSummary" aria-label="Abholungen">
                    <div class="dropSummaryHeader">
                        <h3>Abholungen</h3>
                        <label class="dropFilter">
                            <input id="dropFilter" type="checkbox" checked>
                            Filter
                        </label>
                    </div>
                    <div class="dropTableWrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Waage</th>
                                    <th>Gewicht</th>
                                    <th>Zeitpunkt</th>
                                </tr>
                            </thead>
                            <tbody id="dropTableBody">
                                <tr>
                                    <td colspan="3">Laden...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </aside>
            </div>
        </section>
        </section>

        <section id="pressView" class="moduleView">
            <section class="panel pressLoginPanel">
                <div class="panelHeader">
                    <div>
                        <h2>Pressenauftraege</h2>
                        <span>Schicht und Arbeitsplatz auswaehlen</span>
                    </div>
                    <div class="pressHeaderTools">
                        <span id="pressLiveStatus">Live-Verbindung wird vorbereitet...</span>
                        <button type="button" id="pressAdminButton">Admin</button>
                    </div>
                </div>
                <div class="pressLoginGrid">
                    <label>
                        Schicht
                        <select id="pressUserSelect"></select>
                    </label>
                    <label>
                        Arbeitsplatz / Presse
                        <select id="pressWorkplaceSelect"></select>
                    </label>
                </div>
            </section>

            <section class="pressBoard" id="pressBoard" aria-label="Pressen"></section>
        </section>
    </main>

    <script src="/app.js"></script>
</body>
</html>
