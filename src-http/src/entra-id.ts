import { createRemoteJWKSet, jwtVerify } from "jose";

type AuthorizationRequest = {
	clientId: string;
	redirectUri: string;
	scope?: string;
	clientState?: string;
	codeChallenge?: string;
	codeChallengeMethod?: string;
};

type PendingAuthRequest = AuthorizationRequest & {
	expiresAt: number;
	nonce: string;
	codeVerifier: string;
};

export type EntraConfig = {
	tenantId: string;
	clientId: string;
	clientSecret: string;
	redirectUri: string;
	scopes: string[];
	authorityHost: string;
};

export type EntraAdapter = {
	authorize: (request: Request, context: AuthorizationRequest) => Promise<Response>;
	callback: (request: Request, url: URL) => Promise<Response>;
	purgeExpired: () => void;
};

export type EntraAdapterDependencies = {
	config: EntraConfig;
	stateTtlSeconds: number;
	nowMs: () => number;
	isExpired: (expiresAt: number) => boolean;
	randomToken: (bytes?: number) => string;
	createCodeChallenge: (verifier: string, method: string | null) => string;
	issueAuthorizationCode: (request: AuthorizationRequest) => string;
	getIssuer: (request: Request) => string;
	oauthError: (status: number, error: string, description?: string) => Response;
	corsHeaders: () => Record<string, string>;
};

type EntraTokenPayload = {
	access_token?: string;
	id_token?: string;
	token_type?: string;
	expires_in?: number;
	scope?: string;
	error?: string;
	error_description?: string;
};

type EntraTokenResult =
	| { ok: true; idToken: string }
	| { ok: false; error: string; errorDescription?: string };

function splitEnvList(value: string | undefined, fallback: string[], separator: RegExp = /[\s,]+/): string[] {
	const raw = value?.trim();
	if (!raw) return fallback;
	return raw
		.split(separator)
		.map((entry) => entry.trim())
		.filter(Boolean);
}

export function loadEntraConfig(env: Record<string, string | undefined>): EntraConfig {
	return {
		tenantId: env.ENTRA_TENANT_ID?.trim() ?? "",
		clientId: env.ENTRA_CLIENT_ID?.trim() ?? "",
		clientSecret: env.ENTRA_CLIENT_SECRET?.trim() ?? "",
		redirectUri: env.ENTRA_REDIRECT_URI?.trim() ?? "",
		scopes: splitEnvList(env.ENTRA_SCOPES, ["openid", "profile", "email"]),
		authorityHost: env.ENTRA_AUTHORITY_HOST?.trim() || "https://login.microsoftonline.com"
	};
}

function validateEntraConfig(config: EntraConfig): string | null {
	if (!config.tenantId) return "ENTRA_TENANT_ID is required.";
	if (!config.clientId) return "ENTRA_CLIENT_ID is required.";
	if (!config.clientSecret) return "ENTRA_CLIENT_SECRET is required.";
	return null;
}

function normalizeAuthorityHost(host: string): string {
	return host.replace(/\/+$/, "");
}

function getEntraAuthorityBase(config: EntraConfig): string {
	const host = normalizeAuthorityHost(config.authorityHost);
	return `${host}/${config.tenantId}/oauth2/v2.0`;
}

function getEntraAuthorizeEndpoint(config: EntraConfig): string {
	return `${getEntraAuthorityBase(config)}/authorize`;
}

function getEntraTokenEndpoint(config: EntraConfig): string {
	return `${getEntraAuthorityBase(config)}/token`;
}

function getEntraIssuer(config: EntraConfig): string {
	const host = normalizeAuthorityHost(config.authorityHost);
	return `${host}/${config.tenantId}/v2.0`;
}

function getEntraJwksUrl(config: EntraConfig): string {
	const host = normalizeAuthorityHost(config.authorityHost);
	return `${host}/${config.tenantId}/discovery/v2.0/keys`;
}

function getEntraRedirectUri(
	config: EntraConfig,
	request: Request,
	getIssuer: (request: Request) => string
): string {
	if (config.redirectUri) return config.redirectUri;
	return `${getIssuer(request)}/oauth/entra/callback`;
}

function redirectWithError(
	redirectUri: string,
	error: string,
	description?: string,
	state?: string
): Response {
	const url = new URL(redirectUri);
	url.searchParams.set("error", error);
	if (description) url.searchParams.set("error_description", description);
	if (state) url.searchParams.set("state", state);
	return Response.redirect(url.toString(), 302);
}

export function createEntraAdapter(deps: EntraAdapterDependencies): EntraAdapter {
	const pendingAuthRequests = new Map<string, PendingAuthRequest>();
	let entraJwks: ReturnType<typeof createRemoteJWKSet> | null = null;

	function getEntraJwks(): ReturnType<typeof createRemoteJWKSet> {
		if (!entraJwks) {
			entraJwks = createRemoteJWKSet(new URL(getEntraJwksUrl(deps.config)));
		}
		return entraJwks;
	}

	function purgeExpired(): void {
		for (const [key, record] of pendingAuthRequests.entries()) {
			if (deps.isExpired(record.expiresAt)) {
				pendingAuthRequests.delete(key);
			}
		}
	}

	function buildEntraAuthorizeUrl(
		request: Request,
		state: string,
		codeChallenge: string,
		nonce: string
	): string {
		const authorizeUrl = new URL(getEntraAuthorizeEndpoint(deps.config));
		authorizeUrl.searchParams.set("client_id", deps.config.clientId);
		authorizeUrl.searchParams.set("response_type", "code");
		authorizeUrl.searchParams.set("redirect_uri", getEntraRedirectUri(deps.config, request, deps.getIssuer));
		authorizeUrl.searchParams.set("response_mode", "query");
		authorizeUrl.searchParams.set("scope", deps.config.scopes.join(" "));
		authorizeUrl.searchParams.set("state", state);
		authorizeUrl.searchParams.set("nonce", nonce);
		authorizeUrl.searchParams.set("code_challenge", codeChallenge);
		authorizeUrl.searchParams.set("code_challenge_method", "S256");
		return authorizeUrl.toString();
	}

	async function exchangeEntraCode(
		request: Request,
		code: string,
		codeVerifier: string
	): Promise<EntraTokenResult> {
		const body = new URLSearchParams({
			client_id: deps.config.clientId,
			client_secret: deps.config.clientSecret,
			grant_type: "authorization_code",
			code,
			redirect_uri: getEntraRedirectUri(deps.config, request, deps.getIssuer),
			code_verifier: codeVerifier
		});

		if (deps.config.scopes.length) {
			body.set("scope", deps.config.scopes.join(" "));
		}

		let response: Response;
		try {
			response = await fetch(getEntraTokenEndpoint(deps.config), {
				method: "POST",
				headers: {
					"content-type": "application/x-www-form-urlencoded"
				},
				body: body.toString()
			});
		} catch {
			return { ok: false, error: "server_error", errorDescription: "Failed to reach Entra token endpoint." };
		}

		let payload: EntraTokenPayload;
		try {
			payload = (await response.json()) as EntraTokenPayload;
		} catch {
			return { ok: false, error: "server_error", errorDescription: "Invalid token response." };
		}

		if (!response.ok) {
			return {
				ok: false,
				error: payload.error ?? "access_denied",
				errorDescription: payload.error_description ?? "Token exchange failed."
			};
		}

		if (!payload.id_token) {
			return { ok: false, error: "server_error", errorDescription: "Missing id_token from Entra." };
		}

		return { ok: true, idToken: payload.id_token };
	}

	async function verifyEntraIdToken(idToken: string, expectedNonce: string): Promise<EntraTokenResult> {
		try {
			const { payload } = await jwtVerify(idToken, getEntraJwks(), {
				issuer: getEntraIssuer(deps.config),
				audience: deps.config.clientId,
				clockTolerance: 5
			});
			if (payload.nonce !== expectedNonce) {
				return { ok: false, error: "invalid_grant", errorDescription: "Nonce mismatch." };
			}
		} catch {
			return { ok: false, error: "invalid_grant", errorDescription: "Invalid id_token." };
		}

		return { ok: true, idToken };
	}

	async function authorize(request: Request, context: AuthorizationRequest): Promise<Response> {
		const configError = validateEntraConfig(deps.config);
		if (configError) return deps.oauthError(500, "server_error", configError);

		const state = deps.randomToken(18);
		const nonce = deps.randomToken(18);
		const codeVerifier = deps.randomToken(48);
		const codeChallenge = deps.createCodeChallenge(codeVerifier, "S256");
		const expiresAt = deps.nowMs() + deps.stateTtlSeconds * 1000;

		pendingAuthRequests.set(state, {
			...context,
			expiresAt,
			nonce,
			codeVerifier
		});

		const redirectUrl = buildEntraAuthorizeUrl(request, state, codeChallenge, nonce);
		return Response.redirect(redirectUrl, 302);
	}

	async function callback(request: Request, url: URL): Promise<Response> {
		if (request.method !== "GET") return new Response("", { status: 405, headers: deps.corsHeaders() });
		const configError = validateEntraConfig(deps.config);
		if (configError) return deps.oauthError(500, "server_error", configError);
		purgeExpired();

		const error = url.searchParams.get("error");
		const errorDescription = url.searchParams.get("error_description") ?? undefined;
		const state = url.searchParams.get("state");
		if (error) {
			if (state) {
				const pending = pendingAuthRequests.get(state);
				if (pending) {
					pendingAuthRequests.delete(state);
					return redirectWithError(pending.redirectUri, error, errorDescription, pending.clientState);
				}
			}
			return deps.oauthError(400, error, errorDescription);
		}

		const code = url.searchParams.get("code");
		if (!state || !code) {
			return deps.oauthError(400, "invalid_request", "code and state are required.");
		}

		const pending = pendingAuthRequests.get(state);
		if (!pending) {
			return deps.oauthError(400, "invalid_request", "Unknown or expired state.");
		}
		if (deps.isExpired(pending.expiresAt)) {
			pendingAuthRequests.delete(state);
			return deps.oauthError(400, "invalid_request", "Authorization request expired.");
		}

		const exchange = await exchangeEntraCode(request, code, pending.codeVerifier);
		if (!exchange.ok) {
			pendingAuthRequests.delete(state);
			return redirectWithError(
				pending.redirectUri,
				exchange.error,
				exchange.errorDescription,
				pending.clientState
			);
		}

		const verification = await verifyEntraIdToken(exchange.idToken, pending.nonce);
		if (!verification.ok) {
			pendingAuthRequests.delete(state);
			return redirectWithError(
				pending.redirectUri,
				verification.error,
				verification.errorDescription,
				pending.clientState
			);
		}

		pendingAuthRequests.delete(state);
		const authCode = deps.issueAuthorizationCode(pending);
		const redirect = new URL(pending.redirectUri);
		redirect.searchParams.set("code", authCode);
		if (pending.clientState) redirect.searchParams.set("state", pending.clientState);
		return Response.redirect(redirect.toString(), 302);
	}

	return {
		authorize,
		callback,
		purgeExpired
	};
}
