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
PRESS_USERS="Anlage 1,Anlage 2,Schichtfuehrer,Coda"
```

Die Arbeitsplatz-Zuordnungstabelle hat diese Struktur:

```sql
create table dbo.press_workplace_assignments (
    id int identity(1,1) not null primary key,
    hostname nvarchar(255) not null,
    press_id nvarchar(40) not null,
    workplace_label nvarchar(120) null,
    is_active bit not null default 1,
    created_at datetime2 not null default sysdatetime(),
    updated_at datetime2 null
);
```

Das komplette Skript liegt unter `sql/press_workplace_assignments.sql`.

Beispiel:

```sql
insert into dbo.press_workplace_assignments (hostname, press_id, workplace_label)
values ('PRESS-PC-01', 'P1', 'Arbeitsplatz Presse 1');
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
```
