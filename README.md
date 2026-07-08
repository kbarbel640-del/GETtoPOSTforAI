# GETtoPOSTforAI

GET-driven HTTP macro proxy for AI systems and automation.

**Public open-source project** — a lightweight bridge for environments that can only execute GET requests (e.g. certain AI agents, simple webhooks, or legacy tools).

Many AI agents can only send **GET requests**. This project stores predefined HTTP calls (POST, PUT, DELETE, etc.) as **macros** in a SQLite database and triggers them through simple GET URLs.

## How it works

```
Client / AI agent (GET)  →  macro_generator.php  →  SQLite  →  cURL  →  Target API
```

1. Create a macro via GET (`action=create`)
2. Update a macro via GET (`action=update`)
3. Execute a macro via GET (`action=run`) — the server performs the stored HTTP request
4. List or delete macros

## Requirements

- PHP 8.x
- PHP extensions: `curl`, `sqlite3`
- Web server (Apache, Nginx, etc.) with PHP support

## Installation

```bash
git clone https://github.com/kbarbel640-del/GETtoPOSTforAI.git
cd GETtoPOSTforAI
```

Copy the files into your web root (e.g. `/var/www/html/GETtoPOSTforAI/`).

### Apache with reverse proxy

If Apache forwards requests to a backend (e.g. `ProxyPass / http://localhost:8000/`), `/GETtoPOSTforAI/` must be excluded so PHP is served directly from the document root:

```apache
ProxyPass /GETtoPOSTforAI/ !
```

A full snippet is available in `deploy/apache-proxy-snippet.conf`.

The directory must be writable by the web server so SQLite can create `macro_generator.db`:

```bash
chown www-data:www-data /var/www/html/GETtoPOSTforAI
chmod 775 /var/www/html/GETtoPOSTforAI
```

### API key setup

`api_key.php` is not included in the repository. Create it from the template:

```bash
cp api_key.php.example api_key.php
```

Then configure the API key and security settings in `api_key.php`:

```php
$apiKey = 'your-secure-secret';
$requireHttps = true;
$allowedDomains = ['httpbin.org', 'api.example.com'];
$rateLimitPerMinute = 60;
```

| Setting | Description |
|---------|-------------|
| `$requireHttps` | Allow API requests only over HTTPS |
| `$allowedDomains` | Whitelist for target URLs when creating and running macros |
| `$rateLimitPerMinute` | Max requests per IP per minute (`0` = disabled) |

> **Note:** `api_key.php` is listed in `.gitignore` and must never be committed.

The SQLite database `macro_generator.db` is created automatically on first use.

## API endpoints

All actions are called via **GET** with the `key` parameter (API key).

| Action | Parameters | Description |
|--------|------------|-------------|
| `create` | `name`, `url`, `method`, `body`, `headers`, `key` | Create a macro |
| `update` | `id`, `name`, `url`, `method`, `body`, `headers`, `key` | Update a macro |
| `run` | `id`, `key` | Execute a macro |
| `list` | `key` | List all macros |
| `delete` | `id`, `key` | Delete a macro |
| `help` | `key` | Help page |

### Output format (`format`)

| Value | Behavior |
|-------|----------|
| `json` | JSON response with `Content-Type: application/json` |
| `html` | Readable HTML page with `Content-Type: text/html` |
| *(not set)* | `create`, `update`, `run`, `list`, `delete` → JSON; `help` → HTML |

Examples:

```
GET ?action=list&key=YOUR_KEY
GET ?action=list&format=html&key=YOUR_KEY
GET ?action=run&id=1&format=html&key=YOUR_KEY
GET ?action=help&format=json&key=YOUR_KEY
```

For `action=run` with `format=html`:

- Macro metadata is shown in a table
- JSON responses from the target API are formatted
- HTML responses from the target API are rendered in a sandboxed preview plus a source block

Error responses also respect `format=html` or `format=json`.

### Create a macro

```
GET ?action=create&name=test&method=POST&url=https://httpbin.org/post&body={"foo":"bar"}&key=YOUR_KEY
```

| Parameter | Required | Description |
|-----------|----------|-------------|
| `name` | yes | Unique macro name |
| `url` | yes | Target URL |
| `method` | no | HTTP method (default: `POST`) |
| `body` | no | Request body as JSON string (max 64 KB) |
| `headers` | no | HTTP headers as JSON string (default: `{}`, max 64 KB) |

**Response (success):**

```json
{
  "success": true,
  "id": 1,
  "message": "Macro 'test' created"
}
```

### Update a macro

```
GET ?action=update&id=1&name=test-v2&url=https://httpbin.org/post&key=YOUR_KEY
GET ?action=update&id=1&method=PUT&body={"foo":"baz"}&key=YOUR_KEY
```

| Parameter | Required | Description |
|-----------|----------|-------------|
| `id` | yes | Macro ID |
| `name` | no* | New macro name |
| `url` | no* | New target URL |
| `method` | no* | New HTTP method |
| `body` | no* | New request body |
| `headers` | no* | New HTTP headers as JSON string |

\* At least one of `name`, `url`, `method`, `body`, or `headers` must be provided. Omitted fields keep their existing values.

**Response (success):**

```json
{
  "success": true,
  "message": "Macro 1 updated",
  "macro": {
    "id": 1,
    "name": "test-v2",
    "method": "POST",
    "url": "https://httpbin.org/post",
    "body": "{\"foo\":\"bar\"}",
    "headers": "{}"
  }
}
```

### Execute a macro

```
GET ?action=run&id=1&key=YOUR_KEY
GET ?action=run&id=1&format=html&key=YOUR_KEY
```

**Response (success, JSON):**

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

**Response (success, HTML):** HTML page with macro details, HTTP status, and formatted response content. If the target API returns HTML, a sandboxed preview is shown.

### List macros

```
GET ?action=list&key=YOUR_KEY
```

**Response:**

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

### Delete a macro

```
GET ?action=delete&id=1&key=YOUR_KEY
```

**Response:**

```json
{
  "success": true,
  "message": "Macro 1 deleted"
}
```

## Database schema

Table `macros` in `macro_generator.db`:

| Field | Type | Description |
|-------|------|-------------|
| `id` | INTEGER | Primary key (auto-increment) |
| `name` | TEXT | Unique macro name |
| `method` | TEXT | HTTP method |
| `url` | TEXT | Target URL |
| `body` | TEXT | Request body |
| `headers` | TEXT | HTTP headers (JSON) |
| `created_at` | DATETIME | Creation timestamp |

Table `execution_log` (created automatically):

| Field | Type | Description |
|-------|------|-------------|
| `macro_id` | INTEGER | Executed macro |
| `macro_name` | TEXT | Macro name |
| `ip` | TEXT | Client IP |
| `http_status` | INTEGER | Target API HTTP status |
| `success` | INTEGER | `1` = success, `0` = error |
| `error` | TEXT | Error message (if any) |
| `executed_at` | DATETIME | Execution timestamp |

## Project structure

```
GETtoPOSTforAI/
├── macro_generator.php          # Main application (API + cURL proxy)
├── api_key.php.example          # API key and security template
├── api_key.php                  # Local config (not in repo)
├── macro_generator.db           # SQLite database (runtime)
├── deploy/
│   └── apache-proxy-snippet.conf
├── LICENSE
├── SECURITY.md
├── .gitignore
└── README.md
```

## Security (public instances)

Because this repository is public, the code includes **baseline protection** for internet-facing deployments. See [SECURITY.md](SECURITY.md) for the full policy and vulnerability reporting process.

| Measure | Status |
|---------|--------|
| API key with `hash_equals()` | ✅ |
| HTTPS enforcement (`$requireHttps`) | ✅ |
| Domain whitelist (`$allowedDomains`) | ✅ |
| SSRF protection (localhost, private IPs, DNS check) | ✅ |
| Input validation (`name`, `method`, `headers`, `url`) | ✅ |
| Per-IP rate limiting | ✅ |
| Execution log in SQLite | ✅ |
| cURL without redirect following | ✅ |

### Operational recommendations

- Use a **strong API key** and never commit `api_key.php`
- Restrict **`$allowedDomains`** to the APIs you actually need
- Keep **HTTPS** enabled
- Be aware of **server logs**: GET parameters may contain the API key
- **Back up** `macro_generator.db` regularly (e.g. via cron)
- Do **not** expose an unconfigured instance to the public internet

### Typical use cases

- AI agents without POST support
- GET-only webhooks as a bridge to REST APIs
- Quick API testing and mocking
- Legacy systems that need to reach modern HTTP methods indirectly

## License

MIT — see [LICENSE](LICENSE).