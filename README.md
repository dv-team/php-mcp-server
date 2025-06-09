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

Here are examples of tools registered in `examples/src/prompts-and-tools-http.php` (the CLI sample mirrors the same behavior):

```php
$server->registerTool(
	name: 'list_files',
	description: 'Lists Items in the file system.',
	inputSchema: new MCPToolInputSchema(
		properties: new MCPToolProperties(
			new MCPToolString(name: 'directory', description: 'The directory to look into. Leave this empty to look in the root directory.', required: false),
		)
	),
	isDangerous: false,
	handler: function (object $input): MCPToolResult {
		$directory = trim((string) ($input->directory ?? ''), '/\\');
		$directory = strtr($directory, ['.' => '']);
		$baseDirectory = sprintf('%s/../file-structure', __DIR__);
		$files = scandir("$baseDirectory/$directory");

		$items = [];
		foreach($files as $file) {
			if(str_starts_with($file, '.')) {
				continue;
			}
			if(is_dir("$baseDirectory/$directory/$file")) {
				$items[] = (object) ['type' => 'directory', 'name' => "$directory/$file"];
			} elseif(is_file("$baseDirectory/$directory/$file")) {
				$items[] = (object) ['type' => 'file', 'name' => "$directory/$file"];
			}
		}

		return new MCPToolResult(data: (object) ['directory' => $directory, 'items' => $items]);
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
	handler: function (object $input): MCPToolResult {
		$json = json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
		error_log($json);

		return new MCPToolResult(
			data: ['result' => 'Email sent successfully!']
		);
	}
);
```

## Notes

- This server is intended for environments where immediate responses are required (no streaming support).
- For broader protocol coverage, including streaming, see [`logiscape/mcp-sdk-php`](https://github.com/logiscape/mcp-sdk-php).

## License

MIT License
