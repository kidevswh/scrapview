# scrapview

Eine kleine Webanwendung zur Visualisierung von Daten aus einer SQL-Datenbank.

## Start

1. `.env.example` nach `.env` kopieren.
2. Datenbankverbindung in `.env` eintragen.
3. Mit Docker starten:

```powershell
docker compose up -d
```

4. Im Browser öffnen:

```text
http://localhost:8088
```

Alternativ mit lokal installiertem PHP:

```powershell
php -S localhost:8080 -t public
```

## Konfiguration

Die Verbindung wird als PDO-DSN angegeben:

```env
DB_DSN="mysql:host=localhost;port=3306;dbname=scrap;charset=utf8mb4"
DB_USER="root"
DB_PASSWORD=""
```

Solange `APP_DEMO=true` gesetzt ist, zeigt die Anwendung Demo-Daten an und greift noch nicht auf die Datenbank zu.
Fuer echte Daten `APP_DEMO=false` setzen.

Beispiele:

```env
DB_DSN="sqlsrv:Server=localhost,1433;Database=scrap"
DB_DSN="pgsql:host=localhost;port=5432;dbname=scrap"
```

## Erste Anpassung

Die Demo-Abfragen stehen in `config/charts.php`. Dort werden spaeter die echten Tabellen und Kennzahlen eingetragen.

## Pressenauftraege

Die zweite Ansicht `Pressenauftraege` zeigt vier Pressen mit Fertigungsauftrag, Laufzeit, Pause/Fortsetzen und Beenden.
Alle Arbeitsplaetze laden den Status alle zwei Sekunden neu und sehen dadurch Statusaenderungen nahezu in Echtzeit.
Die Historie ist je Presse ueber den Button `History` als Dialog mit scrollbarer Liste erreichbar.

Die Fertigungsauftraege werden im Echtbetrieb aus `sapdata.dbo.LOIPRO` gelesen. Die Autocomplete-Suche durchsucht `AUFNR` und `MATNR` in einem gemeinsamen Suchfeld. Die App speichert gestartete und beendete Laeufe in der lokalen Anwendungstabelle `dbo.press_job_runs`.
Die Zuordnung von PC-Hostname zu erlaubter Presse wird in `dbo.press_workplace_assignments` gespeichert. Wenn fuer den erkannten Host Eintraege vorhanden sind, kann dieser Arbeitsplatz nur die dort hinterlegten Pressen bedienen.

Wichtige `.env`-Werte:

```env
PRESS_LOIPRO_TABLE="sapdata.dbo.LOIPRO"
PRESS_WORKPLACE_TABLE="dbo.press_workplace_assignments"
PRESS_ADMIN_CODE=1234
PRESS_DB_TIMEZONE="Europe/Berlin"
PRESS_OPERATORS="Max Mustermann,Erika Muster"
```

Die Zuordnungen koennen in der Pressenansicht ueber `Admin` gepflegt werden. Der Dialog ist mit `PRESS_ADMIN_CODE` geschuetzt; der Wert muss vierstellig sein. Pro Zuordnung wird auch der Pressenfuehrer gepflegt. Die Schicht wird beim Start statisch aus der Uhrzeit gespeichert: 06:00-14:00 Fruehschicht, 14:00-22:00 Spaetschicht, 22:00-06:00 Nachtschicht.
SQL-Server-`datetime2`-Werte werden mit `PRESS_DB_TIMEZONE` interpretiert und als UTC-Zeit an den Browser gesendet.

Die Pressenbedienung nutzt neutrale Tokens in der URL. Die Launcher liegen unter `launchers/` und enthalten keine sichtbare Arbeitsplatzkennung:

```text
http://vpc-kidev-01:8088/?station=47821de8d8e2e66ee2f4a84d6ca47428#pressView
http://vpc-kidev-01:8088/?station=0a393d8be77f4c4b66cbde2672694b31#pressView
http://vpc-kidev-01:8088/?station=add0399b7c847587c25ca24df0eaf954#pressView
http://vpc-kidev-01:8088/?station=df85cf7d2a8a92e70404b4e1787f6faf#pressView
```

Die allgemeine Uebersicht ohne Bedienfreigabe und mit aufsummierter Pressen-Historie ist:

```text
http://vpc-kidev-01:8088/#pressView
```

Die Historie gesamt steht unter den Pressenkarten, ist aufklappbar und kann nach Presse, Schicht sowie Auftragsbeginn-Zeitraum gefiltert werden.

Das Feld `hostname` in `press_workplace_assignments` enthaelt weiterhin die intern aufgeloeste Arbeitsplatzkennung, zum Beispiel `TFS01`. Die Token-Zuordnung kann optional ueber `PRESS_STATION_TOKENS` als JSON ueberschrieben werden.

Die Arbeitsplatz-Zuordnungstabelle hat diese Struktur:

```sql
create table dbo.press_workplace_assignments (
    id int identity(1,1) not null primary key,
    hostname nvarchar(255) not null,
    press_id nvarchar(40) not null,
    workplace_label nvarchar(120) null,
    press_operator nvarchar(120) null,
    is_active bit not null default 1,
    created_at datetime2 not null default sysdatetime(),
    updated_at datetime2 null
);
```

Das komplette Skript liegt unter `sql/press_workplace_assignments.sql`.

Beispiel:

```sql
insert into dbo.press_workplace_assignments (hostname, press_id, workplace_label, press_operator)
values ('PRESS-PC-01', 'P1', 'Arbeitsplatz Presse 1', 'Max Mustermann');
```

Falls die Spalten in `LOIPRO` anders heissen, koennen sie gesetzt werden:

```env
PRESS_ORDER_ID_COLUMN="AUFNR"
PRESS_MATERIAL_COLUMN="MATNR"
PRESS_DESCRIPTION_COLUMN="KTEXT"
PRESS_QUANTITY_COLUMN="GAMNG"
PRESS_UNIT_COLUMN="GMEIN"
PRESS_PLANNED_START_COLUMN="GSTRP"
PRESS_PLANNED_END_COLUMN="GLTRP"
PRESS_WORK_CENTER_COLUMN="ARBPLZ"
PRESS_WORK_CENTER_VALUE="EINSTE"
```

Die Auftragssuche filtert LOIPRO standardmaessig auf `ARBPLZ = EINSTE`.
