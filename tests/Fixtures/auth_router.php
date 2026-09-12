<?php

declare(strict_types=1);

use McpSrv\Auth\AuthenticatedHttpServer;
use McpSrv\Auth\HttpAuthenticationException;
use McpSrv\Auth\HttpAuthenticationProviderInterface;
use McpSrv\Common\HttpRequest;
use McpSrv\Common\Response\HttpResponseHandler;
use McpSrv\MCPServer;
use McpSrv\Types\Tools\MCPToolInputSchema;
use McpSrv\Types\Tools\MCPToolProperties;
use McpSrv\Types\Tools\MCPToolResult;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Synthetic credentials for this test only. Never deploy this fixture.
$provider = new class implements HttpAuthenticationProviderInterface {
	public function authenticate(HttpRequest $request): object {
		$credential = $request->header('X-Test-Credential');
		if($credential === 'unavailable') {
			throw new RuntimeException('Test provider unavailable');
		}
		if($credential === 'forbidden') {
			throw new HttpAuthenticationException(403, [
				'WWW-Authenticate' => 'Custom realm="test", error="forbidden"',
				'Content-Type' => 'application/json',
			], '{"error":"custom_denial"}');
		}
		if(!in_array($credential, ['alice', 'bob'], true)) {
			throw new HttpAuthenticationException(headers: ['WWW-Authenticate' => 'Custom realm="test"']);
		}
		return (object) ['name' => $credential];
	}
};

try {
	(new AuthenticatedHttpServer($provider))->run(static function(object $identity): MCPServer {
		header('X-Test-Factory: entered');
		$server = new MCPServer('Protected fixture', new HttpResponseHandler());
		$server->registerTool('whoami', 'Return the current test identity', new MCPToolInputSchema(new MCPToolProperties()),
			static fn(object $arguments): MCPToolResult => new MCPToolResult(['identity' => $identity], false));
		return $server;
	}, allowedOrigins: ['https://client.example.com']);
} catch(Throwable) {
	// Test application error boundary; provider failures must not reach the factory.
	header('Cache-Control: no-store');
	http_response_code(503);
}
