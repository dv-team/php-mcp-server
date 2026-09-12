<?php

declare(strict_types=1);

namespace McpSrv\Auth;

use McpSrv\Common\HttpRequest;
use McpSrv\MCPServer;

/** Optional native HTTP entrypoint with an application-owned authentication provider. */
final readonly class AuthenticatedHttpServer {
	public function __construct(private HttpAuthenticationProviderInterface $provider) {}

	/**
	 * Authentication runs on every request, before constructing the MCP server.
	 * Unexpected provider failures propagate; they never allow execution to continue.
	 * @param callable(object): MCPServer $createServer Receives the provider's identity/context.
	 * @param list<string> $allowedOrigins
	 */
	public function run(callable $createServer, array $allowedOrigins = []): void {
		try {
			$identity = $this->provider->authenticate(HttpRequest::fromGlobals());
		} catch(HttpAuthenticationException $e) {
			foreach($e->headers as $name => $value) {
				header($name . ': ' . $value);
			}
			header('Cache-Control: no-store');
			// WWW-Authenticate implicitly sets 401 in PHP; preserve the provider's status.
			http_response_code($e->status);
			echo $e->body;
			return;
		}
		$server = $createServer($identity);
		$server->runHttp($allowedOrigins);
	}
}
