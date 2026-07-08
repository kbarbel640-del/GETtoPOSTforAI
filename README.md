# GETtoPOSTforAI

GET-gesteuerter HTTP-Makro-Proxy für KI-Systeme und Automatisierung.

**Öffentliches Open-Source-Projekt** — leichtgewichtige Brücke für Umgebungen, die nur GET-Requests ausführen können (z. B. bestimmte KI-Agenten, einfache Webhooks oder Legacy-Tools).

Viele KI-Agenten können nur **GET-Requests** ausführen. Dieses Projekt speichert vordefinierte HTTP-Aufrufe (POST, PUT, DELETE usw.) als **Makros** in einer SQLite-Datenbank und löst sie über einfache GET-URLs aus.

## Funktionsweise

```
Client / KI-Agent (GET)  →  macro_generator.php  →  SQLite  →  cURL  →  Ziel-API
```

1. Makro per GET anlegen (`action=create`)
2. Makro per GET ausführen (`action=run`) — der Server führt den gespeicherten HTTP-Request aus
3. Makros auflisten oder löschen

## Voraussetzungen

- PHP 8.x
- PHP-Erweiterungen: `curl`, `sqlite3`
- Webserver (Apache, Nginx o.ä.) mit PHP-Unterstützung

## Installation

```bash
git clone https://github.com/kbarbel640-del/GETtoPOSTforAI.git
cd GETtoPOSTforAI
```

Dateien in das Webroot-Verzeichnis legen (z. B. `/var/www/html/GETtoPOSTforAI/`).

### Apache mit Reverse-Proxy

Wenn Apache Anfragen an ein Backend weiterleitet (z. B. `ProxyPass / http://localhost:8000/`), muss `/GETtoPOSTforAI/` davon ausgenommen werden, damit PHP direkt aus dem Webroot ausgeliefert wird:

```apache
ProxyPass /GETtoPOSTforAI/ !
```

Eine vollständige Snippet-Datei liegt unter `deploy/apache-proxy-snippet.conf`.

Das Verzeichnis muss für den Webserver beschreibbar sein, damit SQLite `macro_generator.db` anlegen kann:

```bash
chown www-data:www-data /var/www/html/GETtoPOSTforAI
chmod 775 /var/www/html/GETtoPOSTforAI
```

### API-Key einrichten

Die Datei `api_key.php` ist nicht im Repository enthalten. Aus der Vorlage anlegen:

```bash
cp api_key.php.example api_key.php
```

Dann den API-Key und die Sicherheitseinstellungen in `api_key.php` anpassen:

```php
$apiKey = 'dein-sicherer-schluessel';
$requireHttps = true;
$allowedDomains = ['httpbin.org', 'api.example.com'];
$rateLimitPerMinute = 60;
```

| Einstellung | Beschreibung |
|-------------|--------------|
| `$requireHttps` | API-Aufrufe nur über HTTPS erlauben |
| `$allowedDomains` | Whitelist für Ziel-URLs beim Anlegen und Ausführen von Makros |
| `$rateLimitPerMinute` | Max. Requests pro IP und Minute (`0` = aus) |

> **Hinweis:** `api_key.php` steht in der `.gitignore` und darf nicht ins Repository committed werden.

Die SQLite-Datenbank `macro_generator.db` wird beim ersten Aufruf automatisch erzeugt.

## API-Endpunkte

Alle Aktionen werden per **GET** mit dem Parameter `key` (API-Key) aufgerufen.

| Aktion | Parameter | Beschreibung |
|--------|-----------|--------------|
| `create` | `name`, `url`, `method`, `body`, `headers`, `key` | Makro anlegen |
| `run` | `id`, `key` | Makro ausführen |
| `list` | `key` | Alle Makros auflisten |
| `delete` | `id`, `key` | Makro löschen |
| `help` | `key` | Hilfeseite |

### Ausgabeformat (`format`)

| Wert | Verhalten |
|------|-----------|
| `json` | JSON-Antwort mit `Content-Type: application/json` |
| `html` | Lesbare HTML-Seite mit `Content-Type: text/html` |
| *(nicht gesetzt)* | `create`, `run`, `list`, `delete` → JSON; `help` → HTML |

Beispiele:

```
GET ?action=list&key=DEIN_KEY
GET ?action=list&format=html&key=DEIN_KEY
GET ?action=run&id=1&format=html&key=DEIN_KEY
GET ?action=help&format=json&key=DEIN_KEY
```

Bei `action=run` mit `format=html`:

- Metadaten des Makros werden als Tabelle dargestellt
- JSON-Antworten der Ziel-API werden formatiert angezeigt
- HTML-Antworten der Ziel-API werden in einer sandboxed Vorschau plus Quelltext-Block gerendert

Fehlerantworten respektieren ebenfalls `format=html` oder `format=json`.

### Makro anlegen

```
GET ?action=create&name=test&method=POST&url=https://httpbin.org/post&body={"foo":"bar"}&key=DEIN_KEY
```

| Parameter | Pflicht | Beschreibung |
|-----------|---------|--------------|
| `name` | ja | Eindeutiger Makro-Name |
| `url` | ja | Ziel-URL |
| `method` | nein | HTTP-Methode (Standard: `POST`) |
| `body` | nein | Request-Body als JSON-String |
| `headers` | nein | HTTP-Headers als JSON-String (Standard: `{}`) |

**Antwort (Erfolg):**

```json
{
  "success": true,
  "id": 1,
  "message": "Makro 'test' angelegt"
}
```

### Makro ausführen

```
GET ?action=run&id=1&key=DEIN_KEY
GET ?action=run&id=1&format=html&key=DEIN_KEY
```

**Antwort (Erfolg, JSON):**

```json
{
  "success": true,
  "macro": "test",
  "target_url": "https://httpbin.org/post",
  "method": "POST",
  "response": {
    "status": 200,
    "response": { ... }
  }
}
```

**Antwort (Erfolg, HTML):** HTML-Seite mit Makro-Details, HTTP-Status und formatiertem Antwortinhalt. Liefert die Ziel-API HTML, erscheint eine sandboxed Vorschau.

### Makros auflisten

```
GET ?action=list&key=DEIN_KEY
```

**Antwort:**

```json
{
  "macros": [
    {
      "id": 1,
      "name": "test",
      "method": "POST",
      "url": "https://httpbin.org/post",
      "created_at": "2026-07-08 21:19:01"
    }
  ],
  "count": 1
}
```

### Makro löschen

```
GET ?action=delete&id=1&key=DEIN_KEY
```

**Antwort:**

```json
{
  "success": true,
  "message": "Makro 1 gelöscht"
}
```

## Datenbankschema

Tabelle `macros` in `macro_generator.db`:

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `id` | INTEGER | Primärschlüssel (Auto-Increment) |
| `name` | TEXT | Eindeutiger Makro-Name |
| `method` | TEXT | HTTP-Methode |
| `url` | TEXT | Ziel-URL |
| `body` | TEXT | Request-Body |
| `headers` | TEXT | HTTP-Headers (JSON) |
| `created_at` | DATETIME | Erstellungszeitpunkt |

Tabelle `execution_log` (automatisch):

| Feld | Typ | Beschreibung |
|------|-----|--------------|
| `macro_id` | INTEGER | Ausgeführtes Makro |
| `macro_name` | TEXT | Makro-Name |
| `ip` | TEXT | Client-IP |
| `http_status` | INTEGER | HTTP-Status der Ziel-API |
| `success` | INTEGER | `1` = erfolgreich, `0` = Fehler |
| `error` | TEXT | Fehlermeldung (falls vorhanden) |
| `executed_at` | DATETIME | Zeitpunkt der Ausführung |

## Projektstruktur

```
GETtoPOSTforAI/
├── macro_generator.php          # Hauptanwendung (API + cURL-Proxy)
├── api_key.php.example          # Vorlage für den API-Key
├── api_key.php                  # API-Key (lokal, nicht im Repo)
├── macro_generator.db           # SQLite-Datenbank (zur Laufzeit)
├── deploy/
│   └── apache-proxy-snippet.conf
├── .gitignore
└── README.md
```

## Sicherheit (öffentliche Instanzen)

Da das Repository öffentlich ist, enthält der Code **Baseline-Schutz** für öffentlich erreichbare Installationen:

| Maßnahme | Status |
|----------|--------|
| API-Key mit `hash_equals()` | ✅ |
| HTTPS erzwingen (`$requireHttps`) | ✅ |
| Domain-Whitelist (`$allowedDomains`) | ✅ |
| SSRF-Schutz (localhost, private IPs, DNS-Check) | ✅ |
| Input-Validierung (`name`, `method`, `headers`, `url`) | ✅ |
| Rate-Limiting pro IP | ✅ |
| Ausführungs-Log in SQLite | ✅ |
| cURL ohne Redirect-Follow | ✅ |

### Empfehlungen für den Betrieb

- **Starken API-Key** setzen und `api_key.php` niemals committen
- **`$allowedDomains`** auf die wirklich benötigten APIs begrenzen
- **HTTPS** aktiv lassen
- **Server-Logs** beachten: GET-Parameter mit API-Key können in Access-Logs landen
- **`macro_generator.db`** regelmäßig sichern (z. B. per Cronjob)
- Instanz **nicht ungeschützt** ins Internet stellen, ohne Whitelist und Rate-Limit

### Typische Anwendungsfälle

- KI-Agenten ohne POST-Unterstützung
- GET-only Webhooks als Brücke zu REST-APIs
- Schnelles Testen und Mocken von APIs
- Legacy-Systeme, die moderne HTTP-Methoden indirekt ansteuern

## Lizenz

Keine Lizenz angegeben.