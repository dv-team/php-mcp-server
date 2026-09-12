# PHP MCP Server

This project demonstrates a simple PHP-based MCP Server (JSON-RPC) for handling requests that can respond immediately. It is designed for flexibility and can be adapted to various environments, such as database schema retrieval, prompt output, filesystem tasks, and more.

## Scope

- Simple tools that finish immediately or after a short wait, plus prompts and resources.
- Native PHP HTTP request/response handling and newline-delimited STDIO.
- Exactly **MCP 2026-07-28** on both transports. Future versions require an explicit implementation update; a later date is not automatically accepted.
- No dependency on `mcp/sdk`, no Node/Bun bridge, worker daemon, session store, or persistent connection required for native HTTP.
- No subagents, background tasks, sampling, elicitation/user questions, progress streams, logging notifications, or ongoing list/resource change notifications. These features are neither advertised nor implemented.

Each HTTP request constructs the server and registers its tools again. Authenticate the caller before registration and execution. If application state spans calls, pass an explicit application identifier as a tool argument. Do not use MCP sessions or previous requests to establish client identity or permissions.

## Requirements and installation

PHP 8.2 or higher and Composer:

```bash
composer install
```

The only runtime package dependency is `psr/log`. Authentication providers, tool discovery and application schema validation belong to the integrating application. There is no dependency on `mcp/sdk`.

## Compatibility and migration

The existing PHP constructors, registration methods, attribute-based registration, callback arguments, result types, `run()`, `runCli()`, and `ResponseHandlerInterface` signatures remain unchanged. HTTP adds `HttpResponseHandler` and `MCPServer::runHttp()`.

**The wire protocol changes are breaking for older MCP clients.** Clients must support `2026-07-28`; `initialize` is no longer supported. A client speaking only `2025-06-18` or `2025-11-25` cannot connect after this upgrade. Roll this release out together with client upgrades. Existing direct callers of `run()` must supply the new JSON-RPC envelope and metadata too.

Supporting HTTP/OAuth alone does not establish client support for the new protocol lifecycle. Check the protocol version supported and enabled by each client before upgrading.

Every request includes `params._meta` with:

- `io.modelcontextprotocol/protocolVersion`: `2026-07-28`
- `io.modelcontextprotocol/clientCapabilities`: an object, possibly `{}`
- Optional `io.modelcontextprotocol/clientInfo`: client name and version

Every successful response includes `resultType: "complete"` and server identity in `result._meta`. Protocol errors now use standard JSON-RPC/MCP codes. Malformed messages use `replyRaw()` to emit an error with `id: null`; custom response handlers must implement that existing method. List responses use deterministic ordering.

## Usage

### Implemented JSON-RPC methods

- `server/discover`: supported versions, capabilities, server identity, and optional instructions
- `prompts/list`, `prompts/get`
- `tools/list`, `tools/call`
- `resources/list`, `resources/read`, `resources/templates/list`

Discovery is optional for clients; tools can be called directly. Each request supplies its own metadata, including after a successful discovery call. Unsupported versions return error `-32022` with `data.supported` and `data.requested`. Unsupported methods return `-32601`.

### STDIO

```bash
printf '%s\n' '{"jsonrpc":"2.0","id":1,"method":"server/discover","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}' | php cli.php
```

The sample reads requests until EOF; the public `runCli($resource, $loop)` interface still supports single-request execution with `$loop = false`. Logs are written to `stdio–mcp.log` (the filename contains an en dash).

### Native PHP HTTP

Run the sample locally:

```bash
php -S 127.0.0.1:8080 http.php
```

Call the sample tool without a handshake:

```bash
curl --fail-with-body http://127.0.0.1:8080/mcp \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  -H 'MCP-Protocol-Version: 2026-07-28' \
  -H 'Mcp-Method: tools/call' \
  -H 'Mcp-Name: tell_date_and_time' \
  --data '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"tell_date_and_time","arguments":{},"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28","io.modelcontextprotocol/clientCapabilities":{}}}}'
```

For an application endpoint, use the same registration code with an HTTP response handler:

```php
use McpSrv\Common\Response\HttpResponseHandler;
use McpSrv\MCPServer;

$server = new MCPServer('My tools', new HttpResponseHandler());
// Existing registerTool(), registerToolsFromObject(), registerPrompt(), etc.
$server->runHttp(allowedOrigins: ['https://app.example.com']);
```

`runHttp()` reads `php://input` and native request headers. It returns one `application/json` response; SSE is not needed for this profile even though conforming clients advertise both accepted response types. Route the desired MCP endpoint to this script in Apache/nginx/PHP-FPM. The built-in development server is only a local example.

HTTP behavior:

- Only POST is supported; GET/DELETE return 405 with `Allow: POST`.
- `MCP-Protocol-Version` and `Mcp-Method` are required. `Mcp-Name` is also required for `tools/call`, `prompts/get`, and `resources/read` (the resource URI).
- Headers are checked against the body before invoking a handler. Recognized `x-mcp-header` tool parameters are checked too, including nested object properties and Base64-encoded values. Header failures return HTTP 400 / `-32020`.
- Unsupported versions and malformed metadata return HTTP 400; unknown methods return HTTP 404 / `-32601`.
- `Content-Type` must be `application/json`; Accept must include both `application/json` and `text/event-stream` with nonzero quality.
- An absent Origin is accepted. A present Origin must match an entry in `allowedOrigins` exactly; the default empty list rejects all present origins with HTTP 403. Configure public origins explicitly behind a proxy. This is validation, not a CORS/preflight implementation.
- No MCP session IDs are created, retained, or echoed. Responses are marked `Cache-Control: no-store`; no cache lifetime is promised for application-dependent lists.
- HTTP client notifications are rejected with HTTP 400 and no body. STDIO notifications are ignored without a reply or handler invocation.

The sample exposes only a clock tool and has no authentication. Integrate the application's authentication and authorization before registering/executing its tools, or at a trusted gateway. This library does not implement an OAuth authorization server.

### Custom authentication and tool providers

`AuthenticatedHttpServer` accepts an application-defined `HttpAuthenticationProviderInterface`. Its `authenticate(HttpRequest $request): object` method returns the application's identity/context or throws `HttpAuthenticationException` with its chosen HTTP rejection, headers and body. Only successful authentication invokes the factory that creates and registers the MCP server. The check runs on every request, including discovery, tool lists and tool calls.

The library does not interpret credentials, maintain user accounts or implement identity-provider integrations. Login flows, OAuth metadata/endpoints, authorization policy and user mapping belong to the application. Frameworks can also authenticate in their own middleware and then call `runHttp()` directly. See [custom HTTP authentication](docs/http-authentication.md) for the small provider interface and entrypoint example.

Tools continue to use the existing `registerTool()`, `registerToolFromMethod()` and `registerToolsFromObject()` methods. Applications can populate those registrations from any source. The MCP library handles their schemas and callbacks without knowing how tools were discovered or which application framework executes them.

### Historical HTTP bridges

`src-http/` contains the previous Bun bridge and its OAuth experiment. It is retained as historical code and is **not** the native `2026-07-28` HTTP implementation. Its old examples and third-party STDIO bridges require their own protocol review; use `http.php` / `runHttp()` for the implementation described here.

### Verification

```bash
composer test
composer phpstan
python3 tests/http_smoke.py
python3 tests/auth_http_smoke.py
```

The HTTP checks start temporary PHP servers bound to loopback and terminate them afterwards. The authenticated check uses an application-defined test provider and verifies rejected requests, custom challenges, provider failures, per-request identities and ordinary tool registration/calls. It does not contact external services. Both scripts require Python 3 and permission to open a local socket. Never deploy `tests/Fixtures/`. In environments that prohibit PHPStan's parallel-worker socket, run `composer phpstan -- --debug` instead.

## Example: Registering Prompts

The server uses YAML front-matter to parse Markdown files and register them as prompts, using an application-supplied YAML front-matter parser:

```php
$files = [
	__DIR__ . '/../prompts/email--basic-rules.md',
	__DIR__ . '/../prompts/behaviour--basic-rules.md'
];

$responseHandler = new StdoutResponseHandler();
$server = new MCPServer('Prompt provider example', $responseHandler);

foreach($files as $file) {
	$document = YamlFrontMatter::parseFile($file);

	/** @var string $name */
	$name = $document->matter('name');

	/** @var string $prompt */
	$prompt = $document->matter('prompt');

	/** @var string $description */
	$description = $document->matter('description');

	$server->registerPrompt(
		name: $name,
		description: $prompt,
		arguments: new MCPPromptArguments(),
		handler: function () use ($description, $document) {
			return new MCPPromptResult(
				description: $description,
				messages: [new PromptResultStringMessage(
					role: RoleEnum::User,
					content: $document->body()
				)]
			);
		}
	);
}
```

## Example: Registering Tools

Here are three simple tools you might register:

```php
$server->registerTool(
	name: 'echo_text',
	description: 'Echoes back the provided text.',
	inputSchema: new MCPToolInputSchema(
		properties: new MCPToolProperties(
			new MCPToolString(name: 'text', description: 'Text to echo', required: true),
		)
	),
	annotations: (object) [
		'readOnlyHint' => true,
		'idempotentHint' => true,
		'openWorldHint' => false,
	],
	handler: static function (object $input): MCPToolResult {
		return new MCPToolResult(
			content: (object) ['echo' => (string) ($input->text ?? '')],
			isError: false
		);
	}
);

$server->registerTool(
	name: 'sum_numbers',
	description: 'Adds two integers together.',
	inputSchema: new MCPToolInputSchema(
		properties: new MCPToolProperties(
			new MCPToolInteger(name: 'a', description: 'First addend', required: true),
			new MCPToolInteger(name: 'b', description: 'Second addend', required: true),
		)
	),
	annotations: (object) [
		'readOnlyHint' => true,
		'idempotentHint' => true,
		'openWorldHint' => false,
	],
	handler: static function (object $input): MCPToolResult {
		$sum = (int) ($input->a ?? 0) + (int) ($input->b ?? 0);

		return new MCPToolResult(
			content: ['sum' => $sum],
			isError: false
		);
	}
);

$server->registerTool(
	name: 'send_email',
	description: 'Send an email',
	inputSchema: new MCPToolInputSchema(
		properties: new MCPToolProperties(
			new MCPToolString(name: 'to', description: 'The recipient email address', required: true),
			new MCPToolString(name: 'cc', description: 'The cc-recipient email address', required: false),
			new MCPToolString(name: 'from', description: 'The sender email address', required: true),
			new MCPToolString(name: 'subject', description: 'The subject of the email', required: true),
			new MCPToolString(name: 'body', description: 'The body of the email', required: true),
		),
		required: []
	),
	annotations: (object) [
		'destructiveHint' => false,
		'openWorldHint' => true,
	],
	handler: static function (object $input): MCPToolResult {
		$json = json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		error_log($json);

		return new MCPToolResult(
			content: ['result' => 'Email queued!'],
			isError: false
		);
	}
);
```

## Example: Attribute-Based Tools

You can mark methods with attributes and let the `AttributeToolRegistrar` build the MCP tool definitions for you:

```php
use McpSrv\Common\Attributes\MCPDescription;
use McpSrv\Common\Attributes\MCPTool;
use McpSrv\Common\Tools\AttributeToolRegistrar;
use McpSrv\MCPServer;

class MathTools {
	#[MCPTool(
		name: 'add_numbers',
		description: 'Adds two integers together.',
		parametersSchema: [
			'type' => 'object',
			'properties' => [], // left empty; types/required flags are inferred from parameter signatures
		],
		annotations: [
			'readOnlyHint' => true,
			'idempotentHint' => true,
			'openWorldHint' => false,
		],
		outputSchema: [
			'type' => 'object',
			'properties' => [
				'sum' => ['type' => 'integer', 'description' => 'Result of the addition', 'required' => true],
			],
			'required' => ['sum'],
		],
	)]
	public function add(
		#[MCPDescription('First addend')]
		int $a,
		#[MCPDescription('Second addend')]
		int $b
	): array {
		return ['sum' => $a + $b];
	}
}

$server = new MCPServer('attribute-sample', $responseHandler);

$server->registerToolsFromObject(new MathTools());
```

Descriptions on parameters are copied into the generated schema; when a property is not supplied in `parametersSchema`, the registrar infers the JSON Schema `type` and required flag from the method signature.

## Tool Annotations

Tools may carry an optional `annotations` object whose properties are **hints** about the tool's behavior. The MCP spec defines the keys below; all are optional. Clients use them to render UI hints (e.g. a "destructive" badge) or to decide which tools to expose without user confirmation.

| Key | Type | Meaning |
| --- | --- | --- |
| `title` | string | Human-readable title for the tool. |
| `readOnlyHint` | bool | If true, the tool does not modify its environment. Default: `false`. |
| `destructiveHint` | bool | If true, the tool may perform destructive updates. Only meaningful when `readOnlyHint == false`. Default: `true`. |
| `idempotentHint` | bool | If true, repeated calls with the same arguments have no additional effect. Only meaningful when `readOnlyHint == false`. Default: `false`. |
| `openWorldHint` | bool | If true, the tool may interact with an open world of external entities (e.g. web search). If false, its domain is closed (e.g. an in-memory tool). Default: `true`. |

Annotations are hints, not guarantees — never make trust decisions based on annotations received from an untrusted server. See the [MCP specification](https://modelcontextprotocol.io/specification/2025-11-25/server/tools) for the canonical definition.

Pass annotations as a `stdClass` to `MCPServer::registerTool()` (cast an array with `(object) [...]` as shown in the examples) or as an associative array to `#[MCPTool]`. `registerTool()` also accepts an optional `outputSchema` parameter — a JSON-Schema object describing the tool's structured result — which is forwarded to the client alongside `inputSchema`.

The shipped JSON schema at `schema/2025-11-25/schema.json` is used by the test suite to validate that registered tools conform to the spec, annotations included.

## Notes

This is a synchronous request/response implementation. Existing schema builders and `MCPToolRawInputSchema` remain available; application callbacks remain responsible for validating their inputs and producing data matching any advertised output schema. The library does not fetch external schema URLs, infer application permissions or implement the optional interactive/background protocol features described above.

Protocol references: [2026-07-28 changes](https://modelcontextprotocol.io/specification/2026-07-28/changelog), [versioning and compatibility](https://modelcontextprotocol.io/specification/2026-07-28/basic/versioning), [discovery](https://modelcontextprotocol.io/specification/2026-07-28/server/discover), and [Streamable HTTP](https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http).

## License

MIT License
