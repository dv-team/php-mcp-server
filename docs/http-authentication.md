# Custom HTTP authentication

[README](../README.md)

The MCP library provides an optional authentication boundary for native PHP HTTP. The application supplies the provider and decides what an identity means, how credentials are checked and which tools to register.

## Provider contract

Implement `McpSrv\Auth\HttpAuthenticationProviderInterface` in the application:

```php
public function authenticate(HttpRequest $request): object;
```

The provider reads headers with `$request->header('Authorization')` or any other field name; lookup is case insensitive. It can also use the application's injected request, identity and authorization services. On success it returns an application-defined context object, passed unchanged to the server factory. The library imposes no fields or user model.

To reject a request, throw `HttpAuthenticationException`. Its `status` defaults to 401 and can be 400, 401 or 403. Its optional `headers` and `body` determine the HTTP response. The exception's own message is not exposed. For example, an application's OAuth provider could produce its own challenge:

```php
throw new HttpAuthenticationException(
    status: 401,
    headers: [
        'WWW-Authenticate' => 'Bearer resource_metadata="https://tools.example.com/.well-known/oauth-protected-resource/mcp"',
    ],
);
```

This is only a response example. The application supplies the metadata route, login/token flow and credential validation. The library does not supply an OAuth authorization server, token verifier, account lookup or predefined authentication provider.

## Native HTTP entrypoint

```php
use McpSrv\Auth\AuthenticatedHttpServer;
use McpSrv\Auth\HttpAuthenticationProviderInterface;
use McpSrv\Common\Response\HttpResponseHandler;
use McpSrv\MCPServer;

// Supplied by the application's bootstrap or dependency injection container.
/** @var HttpAuthenticationProviderInterface $provider */
/** @var callable(MCPServer, object): void $registerApplicationTools */

$http = new AuthenticatedHttpServer($provider);
$http->run(
    static function(object $identity) use ($registerApplicationTools): MCPServer {
        $server = new MCPServer('Application tools', new HttpResponseHandler());
        $registerApplicationTools($server, $identity);
        return $server;
    },
    allowedOrigins: ['https://client.example.com'],
);
```

`$registerApplicationTools` is application code calling the existing tool registration methods. It may capture the identity in tool callbacks and register whatever the caller is allowed to use. The library neither discovers tools from external descriptions nor dispatches into an application framework itself.

For each request the wrapper authenticates first, then invokes the factory and calls the returned server's `runHttp()`. Rejection prevents server construction, discovery, listing and execution. Nothing about a previous request grants access to a later one. Responses to authentication rejections use `Cache-Control: no-store` and retain the provider's status even when PHP would implicitly change it for `WWW-Authenticate`.

Unexpected exceptions from the provider propagate and never run the factory. Handle those failures with the application's normal error handling; do not catch them and continue with an unauthenticated server. Preserve authentication headers through the web server/proxy. Route any public authentication metadata separately from this protected MCP entrypoint.

Existing applications can instead use framework middleware and call `MCPServer::runHttp()` after it succeeds. `MCPServer`'s constructor, registration methods, `run()`, `runCli()`, `runHttp()` and the response-handler interface remain unchanged by this authentication adapter.

## Verification

```bash
composer test
composer phpstan
python3 tests/http_smoke.py
python3 tests/auth_http_smoke.py
```

The authenticated test uses a fixture provider with synthetic credentials and manually registered tools. It verifies the provider boundary without any external identity service, database or tool importer.
