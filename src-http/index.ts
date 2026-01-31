import { createHash, randomBytes, timingSafeEqual } from "crypto";
import { config as loadEnv } from "dotenv";
import { fileURLToPath } from "url";
import { createEntraAdapter, loadEntraConfig, type EntraAdapter } from "./src/entra-id";

loadEnv({ path: fileURLToPath(new URL(".env", import.meta.url)) });

function normalizeBaseUrl(value: string): string {
	const trimmed = value.trim();
	if (!trimmed) return "";
	return trimmed.replace(/\/+$/, "");
}

function normalizeRedirectUri(value: string): string {
	const trimmed = value.trim();
	if (!trimmed) return "";
	try {
		const url = new URL(trimmed);
		if (url.pathname.length > 1) {
			url.pathname = url.pathname.replace(/\/+$/, "");
		}
		url.pathname = url.pathname.replace(/\/{2,}/g, "/");
		return url.toString();
	} catch {
		return trimmed.replace(/\/+$/, "");
	}
}

function buildRedirectUriSet(values: string[]): Set<string> {
	const set = new Set<string>();
	for (const raw of values) {
		const trimmed = raw.trim();
		if (!trimmed) continue;
		set.add(trimmed);
		set.add(normalizeRedirectUri(trimmed));
	}
	return set;
}

const encoder = new TextEncoder();

const redirectUris = (process.env.OAUTH_REDIRECT_URIS ?? "http://localhost:3000/callback")
	.split(",")
	.map((value) => value.trim())
	.filter(Boolean);

const config = {
	port: Number.parseInt(process.env.PORT ?? "8787", 10),
	cliCommand: process.env.MCP_CLI_CMD ?? "php ../cli.php",
	cliWorkingDir: process.env.MCP_CLI_CWD?.trim() || "",
	cliTraceStdout: (process.env.MCP_CLI_TRACE_STDOUT ?? "false") === "true",
	requireAuth: (process.env.MCP_REQUIRE_AUTH ?? "false") === "true",
	clientId: process.env.OAUTH_CLIENT_ID ?? "mcp-client",
	clientSecret: process.env.OAUTH_CLIENT_SECRET ?? "mcp-secret",
	redirectUris,
	redirectUriSet: buildRedirectUriSet(redirectUris),
	authAdapter: (process.env.AUTH_ADAPTER ?? "local").toLowerCase(),
	authStateTtlSeconds: Number.parseInt(process.env.OAUTH_STATE_TTL_SECONDS ?? "900", 10),
	codeTtlSeconds: Number.parseInt(process.env.OAUTH_CODE_TTL_SECONDS ?? "600", 10),
	tokenTtlSeconds: Number.parseInt(process.env.OAUTH_TOKEN_TTL_SECONDS ?? "3600", 10),
	refreshTtlSeconds: Number.parseInt(process.env.OAUTH_REFRESH_TTL_SECONDS ?? "86400", 10),
	baseUrl: normalizeBaseUrl(process.env.BASE_URL ?? ""),
	corsAllowOrigin: process.env.CORS_ALLOW_ORIGIN ?? "*",
	entra: loadEntraConfig(process.env)
};

type AuthCodeRecord = {
	clientId: string;
	redirectUri: string;
	scope?: string;
	codeChallenge?: string;
	codeChallengeMethod?: string;
	expiresAt: number;
};

type TokenRecord = {
	clientId: string;
	scope?: string;
	expiresAt: number;
};

type RefreshRecord = {
	clientId: string;
	scope?: string;
	expiresAt: number;
	accessToken: string;
};

type AuthorizationRequest = {
	clientId: string;
	redirectUri: string;
	scope?: string;
	clientState?: string;
	codeChallenge?: string;
	codeChallengeMethod?: string;
};

const authCodes = new Map<string, AuthCodeRecord>();
const accessTokens = new Map<string, TokenRecord>();
const refreshTokens = new Map<string, RefreshRecord>();
type CliProcess = Bun.Subprocess<"pipe", "pipe", "pipe">;
let entraAdapter: EntraAdapter | null = null;

function spawnCliProcess(): CliProcess {
	const proc = Bun.spawn(["/bin/sh", "-lc", config.cliCommand], {
		stdin: "pipe",
		stdout: "pipe",
		stderr: "pipe",
		...(config.cliWorkingDir ? { cwd: config.cliWorkingDir } : {})
	});

	if (!proc.stdin || typeof proc.stdin === "number") {
		throw new Error("CLI stdin is not available");
	}
	if (!proc.stdout || typeof proc.stdout === "number") {
		throw new Error("CLI stdout is not available");
	}
	if (proc.stderr && typeof proc.stderr !== "number") {
		void pumpStderr(proc, proc.stderr.getReader());
	}

	return proc;
}

async function writeCliPayload(proc: CliProcess, payload: Uint8Array): Promise<void> {
	if (!proc.stdin || typeof proc.stdin === "number") {
		throw new Error("CLI stdin is not available");
	}
	const writeResult = proc.stdin.write(payload);
	if (writeResult instanceof Promise) {
		await writeResult;
	}
	const endResult = proc.stdin.end();
	if (endResult instanceof Promise) {
		await endResult;
	}
}

async function readCliOutput(proc: CliProcess): Promise<string> {
	if (!proc.stdout || typeof proc.stdout === "number") {
		throw new Error("CLI stdout is not available");
	}
	const reader = proc.stdout.getReader();
	const decoder = new TextDecoder();
	let output = "";
	while (true) {
		const { value, done } = await reader.read();
		if (done) break;
		output += decoder.decode(value, { stream: true });
	}
	output += decoder.decode();
	return output;
}

async function pumpStderr(
	proc: CliProcess,
	reader: ReadableStreamDefaultReader<Uint8Array>
): Promise<void> {
	const decoder = new TextDecoder();
	let buffer = "";
	try {
		while (true) {
			const { value, done } = await reader.read();
			if (done) break;
			buffer += decoder.decode(value, { stream: true });
			let newlineIndex = buffer.indexOf("\n");
			while (newlineIndex !== -1) {
				const line = buffer.slice(0, newlineIndex).trim();
				buffer = buffer.slice(newlineIndex + 1);
				if (line) {
					console.error(`[mcp-cli stderr] ${line}`);
				}
				newlineIndex = buffer.indexOf("\n");
			}
		}
	} catch (error) {
		console.error("[mcp-cli stderr] pipe failed", error);
	}

	if (buffer.trim()) {
		console.error(`[mcp-cli stderr] ${buffer.trim()}`);
	}
}

function base64Url(buffer: Buffer): string {
	return buffer
		.toString("base64")
		.replace(/\+/g, "-")
		.replace(/\//g, "_")
		.replace(/=+$/g, "");
}

function randomToken(bytes = 32): string {
	return base64Url(randomBytes(bytes));
}

function nowMs(): number {
	return Date.now();
}

function isExpired(expiresAt: number): boolean {
	return expiresAt <= nowMs();
}

function purgeExpired(): void {
	for (const [key, record] of authCodes.entries()) {
		if (isExpired(record.expiresAt)) {
			authCodes.delete(key);
		}
	}
	for (const [key, record] of accessTokens.entries()) {
		if (isExpired(record.expiresAt)) {
			accessTokens.delete(key);
		}
	}
	for (const [key, record] of refreshTokens.entries()) {
		if (isExpired(record.expiresAt)) {
			refreshTokens.delete(key);
		}
	}
	if (config.authAdapter === "entra") {
		getEntraAdapter().purgeExpired();
	}
}

function oauthError(status: number, error: string, description?: string): Response {
	const body = {
		error,
		...(description ? { error_description: description } : {})
	};
	return jsonResponse(status, body, {
		"cache-control": "no-store",
		pragma: "no-cache"
	});
}

function jsonResponse(status: number, body: unknown, extraHeaders: HeadersInit = {}): Response {
	return new Response(JSON.stringify(body), {
		status,
		headers: {
			"content-type": "application/json; charset=utf-8",
			...extraHeaders,
			...corsHeaders()
		}
	});
}

function corsHeaders(): Record<string, string> {
	return {
		"access-control-allow-origin": config.corsAllowOrigin,
		"access-control-allow-headers": "authorization, content-type",
		"access-control-allow-methods": "GET, POST, OPTIONS"
	};
}

function parseForm(body: string): Record<string, string> {
	const params = new URLSearchParams(body);
	const output: Record<string, string> = {};
	for (const [key, value] of params) {
		output[key] = value;
	}
	return output;
}

function parseBasicAuth(header: string | null): { clientId: string; clientSecret: string } | null {
	if (!header) return null;
	const [scheme, token] = header.split(" ");
	if (!scheme || !token || scheme.toLowerCase() !== "basic") return null;
	const decoded = Buffer.from(token, "base64").toString("utf-8");
	const index = decoded.indexOf(":");
	if (index === -1) return null;
	return {
		clientId: decoded.slice(0, index),
		clientSecret: decoded.slice(index + 1)
	};
}

function isNotificationPayload(bodyText: string): boolean {
	let parsed: unknown;
	try {
		parsed = JSON.parse(bodyText);
	} catch {
		return false;
	}

	const isNotification = (value: unknown): boolean => {
		if (!value || typeof value !== "object") return false;
		const record = value as Record<string, unknown>;
		if (typeof record.method !== "string") return false;
		return !Object.prototype.hasOwnProperty.call(record, "id");
	};

	if (Array.isArray(parsed)) {
		return parsed.length > 0 && parsed.every(isNotification);
	}

	return isNotification(parsed);
}

function validateClient(clientId: string | null, clientSecret: string | null): boolean {
	if (!clientId || !clientSecret) return false;
	return clientId === config.clientId && clientSecret === config.clientSecret;
}

function createCodeChallenge(verifier: string, method: string | null): string {
	if (!method || method === "plain") return verifier;
	if (method === "S256") {
		return base64Url(createHash("sha256").update(verifier).digest());
	}
	return verifier;
}

function secureEqual(a: string, b: string): boolean {
	const aBuf = Buffer.from(a);
	const bBuf = Buffer.from(b);
	if (aBuf.length !== bBuf.length) return false;
	return timingSafeEqual(aBuf, bBuf);
}

function getIssuer(request: Request): string {
	if (config.baseUrl) return config.baseUrl;
	const url = new URL(request.url);
	const forwardedProto = request.headers.get("x-forwarded-proto");
	const forwardedHost = request.headers.get("x-forwarded-host");
	const protocol = forwardedProto ?? url.protocol.replace(":", "");
	const host = forwardedHost ?? request.headers.get("host") ?? url.host;
	return `${protocol}://${host}`;
}

function issueAuthorizationCode(request: AuthorizationRequest): string {
	const code = randomToken(24);
	const expiresAt = nowMs() + config.codeTtlSeconds * 1000;
	authCodes.set(code, {
		clientId: request.clientId,
		redirectUri: request.redirectUri,
		scope: request.scope,
		codeChallenge: request.codeChallenge,
		codeChallengeMethod: request.codeChallengeMethod,
		expiresAt
	});
	return code;
}


function issueToken(clientId: string, scope?: string): { accessToken: string; refreshToken: string; expiresIn: number } {
	const accessToken = randomToken(32);
	const refreshToken = randomToken(32);
	const accessExpiresAt = nowMs() + config.tokenTtlSeconds * 1000;
	const refreshExpiresAt = nowMs() + config.refreshTtlSeconds * 1000;
	accessTokens.set(accessToken, { clientId, scope, expiresAt: accessExpiresAt });
	refreshTokens.set(refreshToken, { clientId, scope, expiresAt: refreshExpiresAt, accessToken });
	return { accessToken, refreshToken, expiresIn: config.tokenTtlSeconds };
}

function validateBearer(request: Request): TokenRecord | null {
	const header = request.headers.get("authorization");
	if (!header) return null;
	const match = header.match(/^Bearer\s+(.+)$/i);
	if (!match) return null;
	const token = match[1];
	const record = accessTokens.get(token);
	if (!record) return null;
	if (isExpired(record.expiresAt)) {
		accessTokens.delete(token);
		return null;
	}
	return record;
}

function getEntraAdapter(): EntraAdapter {
	if (!entraAdapter) {
		entraAdapter = createEntraAdapter({
			config: config.entra,
			stateTtlSeconds: config.authStateTtlSeconds,
			nowMs,
			isExpired,
			randomToken,
			createCodeChallenge,
			issueAuthorizationCode,
			getIssuer,
			oauthError,
			corsHeaders
		});
	}

	return entraAdapter;
}

function isAllowedRedirectUri(redirectUri: string): boolean {
	if (config.redirectUriSet.has(redirectUri)) return true;
	const normalized = normalizeRedirectUri(redirectUri);
	return config.redirectUriSet.has(normalized);
}

type AuthAdapter = {
	name: string;
	authorize: (request: Request, context: AuthorizationRequest) => Promise<Response>;
	callback?: (request: Request, url: URL) => Promise<Response>;
};

async function handleLocalAuthorize(context: AuthorizationRequest): Promise<Response> {
	const code = issueAuthorizationCode(context);
	const redirect = new URL(context.redirectUri);
	redirect.searchParams.set("code", code);
	if (context.clientState) redirect.searchParams.set("state", context.clientState);
	return Response.redirect(redirect.toString(), 302);
}

const authAdapters: Record<string, AuthAdapter> = {
	local: {
		name: "local",
		authorize: async (_request, context) => handleLocalAuthorize(context)
	},
	entra: {
		name: "entra",
		authorize: async (request, context) => getEntraAdapter().authorize(request, context),
		callback: async (request, url) => getEntraAdapter().callback(request, url)
	}
};

function getAuthAdapter(): AuthAdapter {
	return authAdapters[config.authAdapter] ?? authAdapters.local;
}

async function handleAuthorize(request: Request, url: URL): Promise<Response> {
	if (request.method !== "GET") return new Response("", { status: 405, headers: corsHeaders() });
	purgeExpired();

	const responseType = url.searchParams.get("response_type");
	if (responseType !== "code") {
		return oauthError(400, "unsupported_response_type", "Only response_type=code is supported.");
	}

	const clientId = url.searchParams.get("client_id");
	if (clientId !== config.clientId) {
		return oauthError(401, "invalid_client", "Unknown client_id.");
	}

	const redirectUri = url.searchParams.get("redirect_uri");
	if (!redirectUri || !isAllowedRedirectUri(redirectUri)) {
		return oauthError(400, "invalid_request", "redirect_uri is not allowed.");
	}

	const context: AuthorizationRequest = {
		clientId,
		redirectUri,
		scope: url.searchParams.get("scope") ?? undefined,
		clientState: url.searchParams.get("state") ?? undefined,
		codeChallenge: url.searchParams.get("code_challenge") ?? undefined,
		codeChallengeMethod: url.searchParams.get("code_challenge_method") ?? "plain"
	};

	const adapter = getAuthAdapter();
	return adapter.authorize(request, context);
}

async function handleToken(request: Request): Promise<Response> {
	if (request.method !== "POST") return new Response("", { status: 405, headers: corsHeaders() });
	purgeExpired();

	const contentType = request.headers.get("content-type") ?? "";
	let bodyText = "";
	try {
		bodyText = await request.text();
	} catch {
		return oauthError(400, "invalid_request", "Unable to read request body.");
	}

	let params: Record<string, string> = {};
	if (contentType.includes("application/json")) {
		try {
			const parsed = JSON.parse(bodyText) as Record<string, unknown>;
			for (const [key, value] of Object.entries(parsed ?? {})) {
				if (value === undefined || value === null) continue;
				params[key] = String(value);
			}
		} catch {
			return oauthError(400, "invalid_request", "Invalid JSON body.");
		}
	} else {
		params = parseForm(bodyText);
	}

	const authHeader = request.headers.get("authorization");
	const basicAuth = parseBasicAuth(authHeader);
	const clientId = basicAuth?.clientId ?? params.client_id ?? null;
	const clientSecret = basicAuth?.clientSecret ?? params.client_secret ?? null;

	if (!validateClient(clientId, clientSecret)) {
		return new Response(JSON.stringify({ error: "invalid_client" }), {
			status: 401,
			headers: {
				"www-authenticate": "Basic",
				"content-type": "application/json; charset=utf-8",
				...corsHeaders()
			}
		});
	}

	const grantType = params.grant_type;
	if (!grantType) {
		return oauthError(400, "invalid_request", "grant_type is required.");
	}

	if (grantType === "authorization_code") {
		const code = params.code;
		const redirectUri = params.redirect_uri;
		const codeVerifier = params.code_verifier ?? null;
		if (!code || !redirectUri) {
			return oauthError(400, "invalid_request", "code and redirect_uri are required.");
		}
		const record = authCodes.get(code);
		if (!record || record.redirectUri !== redirectUri) {
			return oauthError(400, "invalid_grant", "Invalid authorization code.");
		}
		if (isExpired(record.expiresAt)) {
			authCodes.delete(code);
			return oauthError(400, "invalid_grant", "Authorization code expired.");
		}
		if (record.codeChallenge) {
			if (!codeVerifier) {
				return oauthError(400, "invalid_request", "code_verifier is required.");
			}
			const expected = createCodeChallenge(codeVerifier, record.codeChallengeMethod ?? "plain");
			if (!secureEqual(expected, record.codeChallenge)) {
				return oauthError(400, "invalid_grant", "PKCE verification failed.");
			}
		}

		authCodes.delete(code);
		const token = issueToken(record.clientId, record.scope);
		return jsonResponse(200, {
			access_token: token.accessToken,
			token_type: "bearer",
			expires_in: token.expiresIn,
			refresh_token: token.refreshToken,
			scope: record.scope
		}, {
			"cache-control": "no-store",
			pragma: "no-cache"
		});
	}

	if (grantType === "client_credentials") {
		const scope = params.scope ?? undefined;
		const token = issueToken(clientId!, scope);
		return jsonResponse(200, {
			access_token: token.accessToken,
			token_type: "bearer",
			expires_in: token.expiresIn,
			scope
		}, {
			"cache-control": "no-store",
			pragma: "no-cache"
		});
	}

	if (grantType === "refresh_token") {
		const refreshToken = params.refresh_token;
		if (!refreshToken) {
			return oauthError(400, "invalid_request", "refresh_token is required.");
		}
		const record = refreshTokens.get(refreshToken);
		if (!record || record.clientId !== clientId) {
			return oauthError(400, "invalid_grant", "Invalid refresh_token.");
		}
		if (isExpired(record.expiresAt)) {
			refreshTokens.delete(refreshToken);
			return oauthError(400, "invalid_grant", "refresh_token expired.");
		}
		const token = issueToken(record.clientId, record.scope);
		return jsonResponse(200, {
			access_token: token.accessToken,
			token_type: "bearer",
			expires_in: token.expiresIn,
			refresh_token: token.refreshToken,
			scope: record.scope
		}, {
			"cache-control": "no-store",
			pragma: "no-cache"
		});
	}

	return oauthError(400, "unsupported_grant_type", "Unsupported grant_type.");
}

async function handleMetadata(request: Request): Promise<Response> {
	const issuer = getIssuer(request);
	return jsonResponse(200, {
		issuer,
		authorization_endpoint: `${issuer}/oauth/authorize`,
		token_endpoint: `${issuer}/oauth/token`,
		response_types_supported: ["code"],
		grant_types_supported: ["authorization_code", "client_credentials", "refresh_token"],
		token_endpoint_auth_methods_supported: ["client_secret_basic", "client_secret_post"],
		code_challenge_methods_supported: ["S256", "plain"]
	});
}

async function handleMcp(request: Request): Promise<Response> {
	if (request.method !== "POST") return new Response("", { status: 405, headers: corsHeaders() });
	purgeExpired();

	if (config.requireAuth) {
		const token = validateBearer(request);
		if (!token) {
			return new Response(JSON.stringify({ error: "unauthorized" }), {
				status: 401,
				headers: {
					"content-type": "application/json; charset=utf-8",
					"www-authenticate": "Bearer",
					...corsHeaders()
				}
			});
		}
	}

	let bodyText = "";
	try {
		bodyText = await request.text();
	} catch {
		return new Response(JSON.stringify({ error: "invalid_request" }), {
			status: 400,
			headers: { "content-type": "application/json; charset=utf-8", ...corsHeaders() }
		});
	}

	const notificationOnly = isNotificationPayload(bodyText);
	const payload = bodyText.endsWith("\n") ? bodyText : `${bodyText}\n`;

	try {
		const proc = spawnCliProcess();
		if (config.cliTraceStdout) {
			console.log(`[mcp-cli stdin] ${payload.trimEnd()}`);
		}
		await writeCliPayload(proc, encoder.encode(payload));
		const output = await readCliOutput(proc);
		const exitCode = await proc.exited;

		if (config.cliTraceStdout && output.trim()) {
			console.log(`[mcp-cli stdout] ${output.trimEnd()}`);
		}

		if (!output.trim() || exitCode !== 0) {
			if (exitCode !== 0) {
				console.error(`[mcp-cli] exited with code ${exitCode}`);
			}
			if (notificationOnly && exitCode === 0) {
				return new Response("", { status: 204, headers: corsHeaders() });
			}
			return new Response(JSON.stringify({ error: "mcp_cli_failed" }), {
				status: 500,
				headers: { "content-type": "application/json; charset=utf-8", ...corsHeaders() }
			});
		}

		const responseBody = output.endsWith("\n") ? output : `${output}\n`;
		return new Response(responseBody, {
			status: 200,
			headers: {
				"content-type": "application/json; charset=utf-8",
				"cache-control": "no-store",
				"transfer-encoding": "chunked",
				...corsHeaders()
			}
		});
	} catch (error) {
		console.error("Failed to handle MCP CLI request", error);
		return new Response(JSON.stringify({ error: "mcp_cli_failed" }), {
			status: 500,
			headers: { "content-type": "application/json; charset=utf-8", ...corsHeaders() }
		});
	}
}

const server = Bun.serve({
	port: config.port,
	fetch: async (request) => {
		const url = new URL(request.url);

		if (request.method === "OPTIONS") {
			return new Response("", { status: 204, headers: corsHeaders() });
		}

		switch (url.pathname) {
			case "/":
				return new Response("Streamable-HTTP MCP bridge is running.\n", {
					status: 200,
					headers: { "content-type": "text/plain; charset=utf-8", ...corsHeaders() }
				});
			case "/healthz":
				return new Response("ok\n", {
					status: 200,
					headers: { "content-type": "text/plain; charset=utf-8", ...corsHeaders() }
				});
			case "/.well-known/oauth-authorization-server":
				return handleMetadata(request);
			case "/oauth/authorize":
				return handleAuthorize(request, url);
			case "/oauth/entra/callback":
				if (config.authAdapter !== "entra") {
					return new Response("Not found\n", {
						status: 404,
						headers: { "content-type": "text/plain; charset=utf-8", ...corsHeaders() }
					});
				}
				return getEntraAdapter().callback(request, url);
			case "/oauth/token":
				return handleToken(request);
			case "/mcp":
				return handleMcp(request);
			default:
				return new Response("Not found\n", {
					status: 404,
					headers: { "content-type": "text/plain; charset=utf-8", ...corsHeaders() }
				});
		}
	}
});

console.log(`Streamable-HTTP MCP server listening on http://localhost:${server.port}`);
console.log(`MCP CLI command: ${config.cliCommand}`);
if (config.cliWorkingDir) {
	console.log(`MCP CLI working dir: ${config.cliWorkingDir}`);
}
const adapter = getAuthAdapter();
console.log(`Auth adapter: ${adapter.name}`);
if (adapter.name === "entra" && config.entra.redirectUri) {
	console.log(`Entra redirect URI: ${config.entra.redirectUri}`);
}
