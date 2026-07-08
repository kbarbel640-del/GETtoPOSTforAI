# GETtoPOSTforAI

GET-gesteuerter HTTP-Makro-Proxy für KI-Systeme und Automatisierung.

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

### API-Key einrichten

Die Datei `api_key.php` ist nicht im Repository enthalten. Aus der Vorlage anlegen:

```bash
cp api_key.php.example api_key.php
```

Dann den API-Key in `api_key.php` anpassen.

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
| `help` | `key` | HTML-Hilfeseite |

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
```

**Antwort (Erfolg):**

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

## Projektstruktur

```
GETtoPOSTforAI/
├── macro_generator.php   # Hauptanwendung (API + cURL-Proxy)
├── api_key.php.example   # Vorlage für den API-Key
├── api_key.php           # API-Key (lokal, nicht im Repo)
├── macro_generator.db    # SQLite-Datenbank (zur Laufzeit)
├── .gitignore
└── README.md
```

## Sicherheitshinweise

Dieses Projekt ist als **Proof-of-Concept** gedacht. Für den Produktionseinsatz sollten folgende Punkte beachtet werden:

- **API-Key:** Starken, einzigartigen Key verwenden und `api_key.php` niemals committen
- **HTTPS:** API-Key und sensible Daten nur über verschlüsselte Verbindungen übertragen
- **SSRF-Schutz:** Aktuell können beliebige URLs als Ziel gesetzt werden (inkl. interner Netzwerke). URL-Whitelist empfohlen
- **Rate-Limiting:** Kein Schutz gegen Missbrauch vorhanden
- **Logging:** GET-Parameter mit API-Key können in Server-Logs landen

## Lizenz

Keine Lizenz angegeben.