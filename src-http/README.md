# Streamable-HTTP MCP Bridge (Bun)

Dieses Verzeichnis enthaelt einen schlanken Streamable-HTTP MCP Server auf Bun-Basis. Er bindet ein STDIO-MCP-CLI-Script an und stellt es via HTTP bereit. Die Antworten werden als Chunked HTTP (kein SSE) aus dem STDOUT des CLI gestreamt. SSL-Terminierung wird von einem vorgeschalteten Webserver erledigt und ist hier nicht enthalten.

## Voraussetzungen

- Bun (>= 1.x)
- PHP 8.2+ fuer das CLI (z. B. `php ../cli.php`)

## Installation

```bash
cd src-http
bun install
```

## Starten

```bash
cd src-http
bun run start
```

## Wichtige Endpunkte

- `POST /mcp` Streamable-HTTP MCP (Chunked HTTP, kein SSE)
- `GET /.well-known/oauth-authorization-server` OAuth2 Metadata
- `GET /oauth/authorize` Minimaler OAuth2 Authorization Code Flow
- `POST /oauth/token` Token-Ausgabe (authorization_code, client_credentials, refresh_token)
- `GET /healthz` Health Check

## OAuth 2 (ChatGPT-kompatibel, minimal)

Die OAuth2-Implementierung ist bewusst minimal und nutzt In-Memory Stores. Sie ist auf ChatGPT-Kompatibilitaet ausgelegt, nicht auf eine vollstaendige Provider-Implementierung. Fuer eine spaetere Erweiterung sind OAuth2-Bibliotheken bereits als Abhaengigkeiten vorhanden.

## Konfiguration (Umgebungsvariablen)

- `PORT` (Standard: `8787`)
- `MCP_CLI_CMD` (Standard: `php ../cli.php`)
- `MCP_REQUIRE_AUTH` (`true|false`, Standard: `false`)
- `OAUTH_CLIENT_ID` (Standard: `mcp-client`)
- `OAUTH_CLIENT_SECRET` (Standard: `mcp-secret`)
- `OAUTH_REDIRECT_URIS` (CSV, Standard: `http://localhost:3000/callback`)
- `OAUTH_CODE_TTL_SECONDS` (Standard: `600`)
- `OAUTH_TOKEN_TTL_SECONDS` (Standard: `3600`)
- `OAUTH_REFRESH_TTL_SECONDS` (Standard: `86400`)
- `BASE_URL` (z. B. `https://mcp.example.com` fuer korrekte OAuth Issuer URLs)
- `CORS_ALLOW_ORIGIN` (Standard: `*`)

## Beispielaufruf (Chunked HTTP)

```bash
curl -sS http://127.0.0.1:8787/mcp \
  -H 'content-type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"curl","version":"0.0"}}}'
```
