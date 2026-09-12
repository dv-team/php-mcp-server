<?php

namespace McpSrv;

use JsonException;
use McpSrv\Common\HttpRequest;
use McpSrv\Common\MCPException;
use McpSrv\Common\MCPGeneralException;
use McpSrv\Common\MCPInvalidArgumentException;
use McpSrv\Common\Resources\ResourceRequest;
use McpSrv\Common\Response\ResponseHandlerInterface;
use McpSrv\Common\Tools\AttributeToolRegistrar;
use McpSrv\Types\MCPPrompt;
use McpSrv\Types\Prompts\MCPPromptArguments;
use McpSrv\Types\Prompts\MCPPromptResult;
use McpSrv\Types\Resources\MCPResource;
use McpSrv\Types\Tools\MCPTool;
use McpSrv\Types\Tools\MCPToolInputSchemaInterface;
use McpSrv\Types\Tools\MCPToolResult;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * @phpstan-type TPromptArgument array{
 *     name: string,
 *     description: null|string,
 *     required: bool
 * }
 *
 * @phpstan-type TPrompt array{
 *     name: string,
 *     description: string,
 *     arguments: TPromptArgument[]
 * }
 *
 * @phpstan-type TResourceInputSchema array{
 *     type: 'object',
 *     properties?: array<string, mixed>|object,
 *     required?: string[],
 *     additionalProperties: bool
 * }
 *
 * @phpstan-type TResource array{
 *     uri: string,
 *     name: string,
 *     description: null|string,
 *     handler: null|TResourceHandler,
 *     mimeType: null|string,
 *     inputSchema: TResourceInputSchema
 * }
 *
 * @phpstan-type TResourceHandler callable(ResourceRequest $request): iterable<MCPResource>
 *
 * @phpstan-type TResourceTemplateProperty array{type: string, name: string, description?: string, required?: bool}
 *
 * @phpstan-type TResourceTemplate array{
 *     uriTemplate: string,
 *     description?: string,
 *     properties: TResourceTemplateProperty[],
 *     handler: null|TResourceHandler
 * }
 */
class MCPServer {
	private const SUPPORTED_PROTOCOL_VERSIONS = ['2026-07-28'];

	/** @var array<string, MCPPrompt> */
	private array $prompts = [];

	/** @var array<string, MCPTool> */
	private array $tools = [];

	/** @var array<string, TResource> */
	private array $resources = [];

	/** @var null|TResourceHandler */
	private $resourceHandler = null;

	/** @var array<string, TResourceTemplate> */
	private array $resourceTemplates = [];

	public function __construct(
		private readonly string $name,
		private readonly ResponseHandlerInterface $responseHandler,
		private readonly ?string $instructions = null,
		private readonly ?LoggerInterface $logger = null,
	) {}

	/**
	 * @param string $name
	 * @param string $description
	 * @param MCPPromptArguments $arguments
	 * @param callable(object): MCPPromptResult $handler
	 */
	public function registerPrompt(
		string $name,
		string $description,
		MCPPromptArguments $arguments,
		callable $handler,
	): void {
		$this->prompts[$name] = new MCPPrompt(
			description: $description,
			arguments: $arguments,
			handler: $handler
		);
	}

	/**
	 * @param string $uri
	 * @param string $name
	 * @param null|string $description
	 * @param null|TResourceHandler $handler
	 * @param array<string, mixed> $properties
	 * @param string[] $required
	 */
	public function registerResource(
		string $uri,
		string $name,
		?string $description,
		$handler = null,
		?string $mimeType = null,
		array $properties = [],
		array $required = [],
	): void {
		$inputSchema = [
			'type' => 'object',
			'properties' => (object) $properties,
			'additionalProperties' => false,
		];

		if(empty($properties)) {
			unset($inputSchema['properties']);
			$inputSchema['additionalProperties'] = true;
		}

		if(count($required)) {
			$inputSchema['required'] = array_values(array_unique($required));
		}

		$this->resources[$uri] = [
			'uri' => $uri,
			'name' => $name,
			'handler' => $handler,
			'mimeType' => $mimeType,
			'description' => $description,
			'inputSchema' => $inputSchema,
		];
	}

	/**
	 * @param TResourceHandler $handler
	 */
	public function registerResourceHandler(callable $handler): void {
		$this->resourceHandler = $handler;
	}

	/**
	 * @param string $uriTemplate
	 * @param string $description
	 * @param TResourceTemplateProperty[] $properties
	 * @param null|TResourceHandler $handler
	 * @return void
	 */
	public function registerResourceTemplate(string $uriTemplate, string $description, array $properties, ?callable $handler = null): void {
		$this->resourceTemplates[$uriTemplate] = [
			'uriTemplate' => $uriTemplate,
			'description' => $description,
			'properties' => $properties,
			'handler' => $handler,
		];
	}

	public function registerToolsFromObject(object $obj): void {
		AttributeToolRegistrar::registerObject($obj, $this);
	}

	public function registerToolFromMethod(callable $method): void {
		AttributeToolRegistrar::registerFn($method, $this);
	}

	/**
	 * @param string $name
	 * @param string $description
	 * @param MCPToolInputSchemaInterface $inputSchema
	 * @param callable(object): MCPToolResult $handler
	 * @param null|object $annotations
	 * @param null|object $outputSchema
	 */
	public function registerTool(
		string $name,
		string $description,
		MCPToolInputSchemaInterface $inputSchema,
		$handler,
		?object $annotations = null,
		?object $outputSchema = null,
	): void {
		$this->tools[$name] = new MCPTool(
			name: $name,
			description: $description,
			arguments: $inputSchema,
			handler: $handler,
			annotations: $annotations,
			outputSchema: $outputSchema
		);
	}

	/**
	 * @param resource $resource
	 * @param bool $loop
	 * @return void
	 * @throws JsonException
	 */
	public function runCli($resource = STDIN, bool $loop = true): void {
		if(!is_resource($resource)) {
			throw new RuntimeException('Failed to open stdin');
		}
		try {
			while(!feof($resource)) {
				/** @var string|false $line */
				$line = fgets($resource, null);
				if($line === false || trim($line) === '') {
					continue;
				}

				try {
					$this->logger?->debug("IN", (array) json_decode(json: $line, associative: false, flags: JSON_THROW_ON_ERROR));
				} catch (JsonException $e) {
					$this->logger->error("IN ERROR $line // {$e->getMessage()}", []);
				}

				$this->run($line);

				if(!$loop) {
					break;
				}
			}
		} finally {
			$this->logger?->debug('SYSTEM: Server stopped');
		}
	}

	public function run(string $input): void {
		$this->process($input);
	}

	/**
	 * Serve one native PHP HTTP request. Use HttpResponseHandler for HTTP output.
	 * An absent Origin is accepted; present origins must be explicitly allowed.
	 * Authentication and authorization belong in the application's entrypoint.
	 *
	 * @param list<string> $allowedOrigins Exact origins, including scheme and port.
	 */
	public function runHttp(array $allowedOrigins = []): void {
		$request = HttpRequest::fromGlobals();
		$origin = $request->header('Origin');
		if($origin !== null && !in_array($origin, $allowedOrigins, true)) {
			http_response_code(403);
			return;
		}
		if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
			http_response_code(405);
			header('Allow: POST');
			return;
		}
		$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
		if(!is_string($contentType) || strtolower(trim(explode(';', $contentType, 2)[0])) !== 'application/json') {
			http_response_code(415);
			return;
		}
		$accepted = [];
		foreach(explode(',', $request->header('Accept') ?? '') as $range) {
			$parts = explode(';', strtolower(trim($range)));
			$quality = 1.0;
			foreach(array_slice($parts, 1) as $parameter) {
				if(preg_match('/^q\s*=\s*([0-9.]+)$/', trim($parameter), $match)) {
					$quality = (float) $match[1];
				}
			}
			if($quality > 0) {
				$accepted[] = trim($parts[0]);
			}
		}
		if(!in_array('application/json', $accepted, true) || !in_array('text/event-stream', $accepted, true)) {
			http_response_code(406);
			return;
		}
		$input = file_get_contents('php://input');
		if($input === false) {
			http_response_code(400);
			return;
		}
		$this->process($input, $request);
	}

	private function process(string $input, ?HttpRequest $http = null): void {
		try {
			$body = json_decode($input, associative: false, flags: JSON_THROW_ON_ERROR);
		} catch(JsonException) {
			$this->replyInvalidRequest('Failed to parse request body', -32700);
			return;
		}
		if(!$body instanceof stdClass || ($body->jsonrpc ?? null) !== '2.0'
			|| !isset($body->method) || !is_string($body->method)
			|| (property_exists($body, 'id') && !is_int($body->id) && !is_string($body->id))
			|| (property_exists($body, 'params') && !$body->params instanceof stdClass)) {
			$this->replyInvalidRequest('Invalid JSON-RPC request', -32600);
			return;
		}
		// Notifications never execute handlers and never receive JSON-RPC replies.
		if(!property_exists($body, 'id')) {
			if($http !== null) {
				http_response_code(400); // No HTTP client notifications in this profile.
			}
			return;
		}
		/** @var int|string $id */
		$id = $body->id;
		$params = ($body->params ?? null) instanceof stdClass ? $body->params : new stdClass();
		try {
			$tool = is_string($params->name ?? null) ? ($this->tools[$params->name] ?? null) : null;
			$http?->validate($body->method, $params, $body->method === 'tools/call' ? $tool?->arguments->jsonSerialize() : null);
			if($body->method === 'initialize') {
				throw new MCPInvalidArgumentException('initialize is not supported; use MCP 2026-07-28 and server/discover', -32601);
			}
			$this->validateMetadata($params);
			$result = match($body->method) {
				'server/discover' => $this->discover(),
				'prompts/list' => $this->listPrompts($params),
				'prompts/get' => $this->getPrompt($params),
				'resources/list' => $this->listResources($params),
				'resources/read' => $this->readResource($params),
				'resources/templates/list' => $this->listResourceTemplates($params),
				'tools/list' => $this->listTools($params),
				'tools/call' => $this->callTool($params)->jsonSerialize(),
				default => throw new MCPInvalidArgumentException('Method not found', -32601),
			};
			$fields = (array) $result;
			$fields['resultType'] = 'complete';
			$fields['_meta'] = (object) [
				'io.modelcontextprotocol/serverInfo' => (object) ['name' => $this->name, 'version' => '1.0.0'],
			];
			$this->responseHandler->reply($id, is_array($result) ? $fields : (object) $fields);
		} catch(MCPException $e) {
			$code = $e->getCode();
			if($code === 0 || $code === 100 || $code === 500) {
				$code = $e instanceof MCPInvalidArgumentException ? -32602 : -32603;
			}
			$this->responseHandler->replyError($id, $e->getMessage(), $code, $e->getData());
		} catch(Throwable $e) {
			$this->logger?->error('Internal server error', ['exception' => $e]);
			$this->responseHandler->replyError($id, 'Internal server error', -32603);
		}
	}

	private function replyInvalidRequest(string $message, int $code): void {
		// replyRaw preserves the existing interface while allowing the required null id.
		$this->responseHandler->replyRaw([
			'jsonrpc' => '2.0',
			'id' => null,
			'error' => ['code' => $code, 'message' => $message],
		]);
	}

	private function validateMetadata(object $params): void {
		$meta = $params->_meta ?? null;
		if(!$meta instanceof stdClass
			|| !is_string($meta->{'io.modelcontextprotocol/protocolVersion'} ?? null)
			|| !($meta->{'io.modelcontextprotocol/clientCapabilities'} ?? null) instanceof stdClass) {
			throw new MCPInvalidArgumentException('Required protocolVersion and clientCapabilities metadata is missing or invalid', -32602);
		}
		$version = $meta->{'io.modelcontextprotocol/protocolVersion'};
		if(!in_array($version, self::SUPPORTED_PROTOCOL_VERSIONS, true)) {
			throw new MCPInvalidArgumentException('Unsupported protocol version', -32022, [
				'supported' => self::SUPPORTED_PROTOCOL_VERSIONS,
				'requested' => $version,
			]);
		}
	}

	private function discover(): object {
		$capabilities = [];
		if(count($this->prompts)) {
			$capabilities['prompts'] = (object) [];
		}
		if(count($this->resources) || count($this->resourceTemplates) || $this->resourceHandler !== null) {
			$capabilities['resources'] = (object) [];
		}
		if(count($this->tools)) {
			$capabilities['tools'] = (object) [];
		}
		$result = [
			'supportedVersions' => self::SUPPORTED_PROTOCOL_VERSIONS,
			'capabilities' => (object) $capabilities,
		];
		if($this->instructions !== null) {
			$result['instructions'] = $this->instructions;
		}
		return (object) $result;
	}

	/**
	 * @param object $params
	 * @return array{
	 *     prompts: TPrompt[]
	 * }
	 */
	private function listPrompts(object $params): array {
		$prompts = [];
		$registered = $this->prompts;
		ksort($registered, SORT_STRING);
		foreach($registered as $name => $prompt) {
			$arguments = [];
			foreach($prompt->arguments as $arg) {
				$arguments[] = [
					'name' => $arg->name,
					'description' => $arg->description,
					'required' => $arg->required,
				];
			}

			$prompts[] = [
				'name' => $name,
				'description' => $prompt->description,
				'arguments' => $arguments,
			];
		}

		return ['prompts' => $prompts];
	}

	/**
	 * @param object $params
	 * @return MCPPromptResult
	 * @throws MCPInvalidArgumentException
	 */
	private function getPrompt(object $params): MCPPromptResult {
		if(!property_exists($params, 'name') || !is_string($params->name)) {
			throw new MCPInvalidArgumentException('Missing or invalid prompt name', 100);
		}

		if(!array_key_exists($params->name, $this->prompts)) {
			throw new MCPInvalidArgumentException("Unknown prompt: {$params->name}", 100);
		}
		$prompt = $this->prompts[$params->name];
		$handler = $prompt->handler;

		return $handler($params);
	}

	/**
	 * @param object $params
	 * @return array{resources: list<array{name: string, uri: string, description?: string|null, inputSchema?: TResourceInputSchema}>}
	 */
	private function listResources(object $params): array {
		$resources = [];
		$registered = $this->resources;
		ksort($registered, SORT_STRING);
		foreach($registered as $resource) {
			$entry = [
				'name' => $resource['name'],
				'uri' => $resource['uri'],
			];

			if($resource['description'] !== null) {
				$entry['description'] = $resource['description'];
			}

			if($resource['mimeType'] !== null) {
				$entry['mimeType'] = $resource['mimeType'];
			}

			if(count((array) ($resource['inputSchema']['properties'] ?? [])) || array_key_exists('required', $resource['inputSchema'])) {
				$entry['inputSchema'] = $resource['inputSchema'];
			}

			$resources[] = $entry;
		}

		return ['resources' => $resources];
	}

	/**
	 * @param string $uriTemplate
	 * @param string $uri
	 * @return null|array<string, string>
	 */
	private function parseResourceTemplateUri(string $uriTemplate, string $uri): ?array {
		$parts = preg_split(
			'/(\{[a-zA-Z_][a-zA-Z0-9_]*\})/',
			$uriTemplate,
			-1,
			PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
		);

		if($parts === false) {
			return null;
		}

		$pattern = '^';
		foreach($parts as $part) {
			if(preg_match('/^\{([a-zA-Z_][a-zA-Z0-9_]*)\}$/', $part, $match)) {
				$pattern .= '(?P<' . $match[1] . '>[^/]+)';
			} else {
				$pattern .= preg_quote($part, '~');
			}
		}
		$pattern .= '$';

		if(!preg_match('~' . $pattern . '~', $uri, $matches)) {
			return null;
		}

		$namedMatches = [];
		foreach($matches as $key => $value) {
			if(!is_int($key)) {
				$namedMatches[$key] = $value;
			}
		}

		return $namedMatches;
	}

	/**
	 * @param TResourceTemplate $template
	 * @param object $requestArguments
	 * @return object
	 */
	private function buildResourceTemplateArguments(array $template, object $requestArguments): object {
		if(empty($template['properties'])) {
			return $requestArguments;
		}

		$arguments = [];
		foreach($template['properties'] as $property) {
			$name = $property['name'];
			$arguments[$name] = property_exists($requestArguments, $name) ? $requestArguments->{$name} : null;
		}

		return (object) $arguments;
	}

	/**
	 * @param object $params
	 * @return array{contents: MCPResource[]}
	 * @throws MCPInvalidArgumentException
	 */
	private function readResource(object $params): array {
		if(!property_exists($params, 'uri') || !is_string($params->uri)) {
			throw new MCPInvalidArgumentException('Missing or invalid resource uri', 100);
		}

		$requestArguments = property_exists($params, 'arguments') && is_object($params->arguments) ? $params->arguments : new \stdClass();

		if(array_key_exists($params->uri, $this->resources)) {
			if($this->resources[$params->uri]['handler'] === null) {
				if($this->resourceHandler === null) {
					throw new MCPGeneralException('No resource handler registered', 500);
				}
				$handler = $this->resourceHandler;
			} else {
				$handler = $this->resources[$params->uri]['handler'];
			}

			/** @var iterable<MCPResource> $resources */
			$resources = $handler(new ResourceRequest(
				uri: $params->uri,
				parameters: (object) [],
				arguments: $requestArguments,
			));

			$result = [];
			foreach($resources as $resource) {
				$resource->uri ??= $params->uri;
				$result[] = $resource;
			}

			return ['contents' => $result];
		}

		foreach($this->resourceTemplates as $template) {
			$matches = $this->parseResourceTemplateUri($template['uriTemplate'], $params->uri);
			if($matches === null) {
				continue;
			}

			$handler = $template['handler'] ?? null;
			if($handler === null) {
				if($this->resourceHandler === null) {
					throw new MCPGeneralException('No resource handler registered', 500);
				}
				$handler = $this->resourceHandler;
			}

			$arguments = $this->buildResourceTemplateArguments($template, $requestArguments);

			/** @var iterable<MCPResource> $resources */
			$resources = $handler(new ResourceRequest(
				uri: $params->uri,
				parameters: (object) $matches,
				arguments: $arguments,
			));

			$result = [];
			foreach($resources as $resource) {
				$resource->uri ??= $params->uri;
				$result[] = $resource;
			}

			return ['contents' => $result];
		}

		throw new MCPInvalidArgumentException("Unknown resource: {$params->uri}", 100);
	}

	/**
	 * @param object $params
	 * @return array{resourceTemplates: array{uriTemplate: string, description?: string, inputSchema?: array{type: 'object', properties?: array<string, mixed>, required?: string[], additionalProperties: bool}}[]}
	 */
	private function listResourceTemplates(object $params): array {
		$templates = [];
		$registered = $this->resourceTemplates;
		ksort($registered, SORT_STRING);
		foreach($registered as $template) {
			$properties = [];
			$required = [];
			foreach($template['properties'] as $property) {
				if(array_key_exists('required', $property)) {
					if($property['required']) {
						$required[] = $property['name'];
					}
					unset($property['required']);
				}
				$name = $property['name'];
				unset($property['name']);
				$properties[$name] = $property;
			}
			unset($template['properties'], $template['handler']);
			$template['name'] = $template['uriTemplate'];
			$template['inputSchema'] = [
				'type' => 'object',
				'properties' => $properties,
				'required' => $required,
				'additionalProperties' => false,
			];

			if(empty($properties)) {
				unset($template['inputSchema']['properties']);
				$template['inputSchema']['additionalProperties'] = true;
			}

			$templates[] = $template;
		}

		return ['resourceTemplates' => $templates];
	}

	/**
	 * @param object $params
	 * @return object{tools: array<object{name: string, description: string, inputSchema?: object, annotations?: object, outputSchema?: object}>}
	 */
	private function listTools(object $params): object {
		$tools = [];
		$registered = $this->tools;
		ksort($registered, SORT_STRING);
		foreach($registered as $tool) {
			$tools[] = $tool->jsonSerialize();
		}

		return (object) ['tools' => $tools];
	}

	/**
	 * @param object $params
	 * @return MCPToolResult
	 * @throws MCPInvalidArgumentException
	 */
	private function callTool(object $params): MCPToolResult {
		if(!property_exists($params, 'name') || !is_string($params->name)) {
			throw new MCPInvalidArgumentException('Missing or invalid tool name', 100);
		}

		$arguments = property_exists($params, 'arguments') ? $params->arguments : new stdClass();
		if(!is_object($arguments)) {
			throw new MCPInvalidArgumentException('Tool call must include arguments object', 100);
		}

		if(!array_key_exists($params->name, $this->tools)) {
			throw new MCPInvalidArgumentException("Unknown tool: {$params->name}", 100);
		}
		$tool = $this->tools[$params->name];
		$handler = $tool->handler;

		return $handler($arguments);
	}

}
