# Security Policy

## Supported Versions

Security fixes are provided for the latest release on the `main` branch.

| Version | Supported |
|---------|-----------|
| latest `main` | yes |
| older commits | no |

## Reporting a Vulnerability

If you discover a security issue, please **do not** open a public GitHub issue.

Instead, report it privately to:

- **Email:** ckmaenn@gmail.com
- **Subject:** `[GETtoPOSTforAI] Security Report`

Please include:

1. A clear description of the vulnerability
2. Steps to reproduce
3. Impact assessment (SSRF, auth bypass, data exposure, etc.)
4. Optional proof-of-concept

You should receive a response within **7 days**. If the report is accepted, we aim to provide a fix or mitigation plan within **30 days**.

## Public Instances

GETtoPOSTforAI is designed as a lightweight GET-to-HTTP proxy. Public deployments must be hardened before exposure to the internet.

### Required configuration

Copy `api_key.php.example` to `api_key.php` and configure:

```php
$apiKey = 'use-a-long-random-secret';
$requireHttps = true;
$allowedDomains = ['api.example.com'];
$rateLimitPerMinute = 60;
```

### Built-in protections

- API key authentication (`hash_equals`)
- HTTPS enforcement for API requests
- Domain whitelist for macro target URLs
- SSRF blocking (localhost, private/reserved IPs, DNS resolution checks)
- Input validation for macro names, methods, headers, and URLs
- Per-IP rate limiting
- Execution logging in SQLite
- cURL redirect following disabled

### Operator checklist

- [ ] Use a strong, unique API key
- [ ] Restrict `$allowedDomains` to required APIs only
- [ ] Keep `$requireHttps = true`
- [ ] Run behind HTTPS (reverse proxy or TLS termination)
- [ ] Ensure the web directory is writable only by the web server user
- [ ] Back up `macro_generator.db` regularly
- [ ] Review server access logs (API keys may appear in query strings)
- [ ] Do not expose an unconfigured instance to the public internet

## Known limitations

This project is a pragmatic bridge for GET-only environments, not a full API gateway.

- Single global API key (no per-user auth)
- GET parameters may be logged by web servers and proxies
- The HTML help page uses the `YOUR_KEY` placeholder in examples and does not render the configured API key
- Macro target responses are proxied as-is
- SQLite is suitable for small workloads, not high-traffic multi-tenant production
- Domain whitelist can be bypassed if DNS rebinding protections are misconfigured upstream

Contributions that improve safe defaults for public deployments are welcome.