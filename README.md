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
