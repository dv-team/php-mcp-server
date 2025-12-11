# PHP MCP Server

This project demonstrates a simple PHP-based MCP Server for handling processes that require near-instant responses (as no support for streaming is intended to get a simpler interface). It is designed for flexibility and can be adapted to various environments, such as database schema retrieval, prompt output, filesystem tasks, and more.

## High-Level Goals

- **Simple PHP and PHP-esque MCP Server** for processes that can respond (near) immediately.
- **HTTP and STDIN samples** for quick testing; no streaming support.
- **Versatile usage**: Suitable for a wide range of environments, including database, prompt handling, and filesystem operations.

## Requirements

- PHP 8.2 or higher
- Composer

## Installation

```bash
composer install
```

## Usage

### HTTP server (main)

```bash
php -S 127.0.0.1:8080 public/index.php
```

Use `test.http` to exercise the JSON-RPC methods against the running server.

### Example servers

HTTP sample that loads prompts/tools from `examples/prompts` and exposes demo tools:

```bash
php -S 127.0.0.1:8080 examples/src/prompts-and-tools-http.php
```

STDIN sample that logs requests/responses to `examples/src/stdio–mcp.log`:

```bash
php examples/src/prompts-and-tools-cli.php < request.json
```

## Example: Registering Prompts

The server uses YAML front-matter to parse Markdown files and register them as prompts, as shown in `examples/src/prompts-and-tools-http.php`:

```php
$files = [
	__DIR__ . '/../prompts/email--basic-rules.md',
	__DIR__ . '/../prompts/behaviour--basic-rules.md'
];

$responseHandler = new HttpResponseHandler();
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
	isDangerous: false,
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
	isDangerous: false,
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
	isDangerous: true,
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
		returnSchema: [
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

$registrar = new AttributeToolRegistrar();
$registrar->register(new MathTools(), $server);
```

Descriptions on parameters are copied into the generated schema; when a property is not supplied in `parametersSchema`, the registrar infers the JSON Schema `type` and required flag from the method signature.

## Notes

- This server is intended for environments where immediate responses are required (no streaming support).
- For broader protocol coverage, including streaming, see [`logiscape/mcp-sdk-php`](https://github.com/logiscape/mcp-sdk-php).

## License

MIT License
