<?php

namespace McpSrv;

use JsonException;
use McpSrv\Common\MCPException;
use McpSrv\Common\MCPGeneralException;
use McpSrv\Common\MCPInvalidArgumentException;
use McpSrv\Common\Response\ResponseHandlerInterface;
use McpSrv\Types\MCPPrompt;
use McpSrv\Types\Prompts\MCPPromptArguments;
use McpSrv\Types\Prompts\MCPPromptResult;
use McpSrv\Types\Resources\MCPResource;
use McpSrv\Types\Tools\MCPTool;
use McpSrv\Types\Tools\MCPToolInputSchemaInterface;
use McpSrv\Types\Tools\MCPToolResult;
use Psr\Log\LoggerInterface;
use RuntimeException;
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
 *     properties: array<string, mixed>,
 *     required?: string[]
 * }
 *
 * @phpstan-type TResource array{
 *     uri: string,
 *     name: string,
 *     description: null|string,
 *     mimeType: null|string,
 *     inputSchema: TResourceInputSchema
 * }
 *
 * @phpstan-type TResourceHandler callable(object{uri: string, arguments: object}): Resource[]
 *
 * @phpstan-type TResourceTemplateProperty array{type: string, name: string, description?: string, required?: bool}
 *
 * @phpstan-type TResourceTemplate array{
 *     uriTemplate: string,
 *     description?: string,
 *     properties: TResourceTemplateProperty[]
 * }
 */
class MCPServer {
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
	 * @param array<string, mixed> $properties
	 * @param string[] $required
	 */
	public function registerResource(
		string $uri,
		string $name,
		?string $description,
		?string $mimeType = null,
		array $properties = [],
		array $required = []
	): void {
		$inputSchema = [
			'type' => 'object',
			'properties' => $properties,
		];

		if(count($required)) {
			$inputSchema['required'] = array_values(array_unique($required));
		}

		$this->resources[$uri] = [
			'uri' => $uri,
			'name' => $name,
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
	 * @param callable(object $arguments): iterable<Resource> $handler
	 * @return void
	 */
	public function registerResourceTemplate(string $uriTemplate, string $description, array $properties, callable $handler): void {
		$this->resourceTemplates[$uriTemplate] = [
			'uriTemplate' => $uriTemplate,
			'description' => $description,
			'properties' => $properties,
			'handler' => $handler
		];
	}

	/**
	 * @param string $name
	 * @param string $description
	 * @param MCPToolInputSchemaInterface $inputSchema
	 * @param bool $isDangerous
	 * @param callable(object): MCPToolResult $handler
	 * @param null|object $returnSchema
	 * @return void
	 */
	public function registerTool(
		string $name,
		string $description,
		MCPToolInputSchemaInterface $inputSchema,
		bool $isDangerous,
		$handler,
		?object $returnSchema = null
	) {
		$this->tools[$name] = new MCPTool(
			name: $name,
			description: $description,
			arguments: $inputSchema,
			isDangerous: $isDangerous,
			handler: $handler,
			returnSchema: $returnSchema
		);
	}

	/**
	 * @param resource $resource
	 * @return void
	 * @throws JsonException
	 */
	public function runCli($resource = STDIN): void {
		if(!is_resource($resource)) {
			throw new RuntimeException('Failed to open stdin');
		}
		while(!feof($resource)) {
			/** @var string|false $line */
			$line = fgets($resource, null);
			if($line === false) {
				continue;
			}

			$this->logger?->debug("IN", (array) json_decode(json: $line, associative: false, flags: JSON_THROW_ON_ERROR));

			$this->run($line);
		}
		$this->logger?->debug('SYSTEM: Server stopped');
	}

	public function run(string $input): void {
		if(trim($input) === '') {
			$this->responseHandler->replyError(0, 'Empty input body', 100);
			return;
		}

		try {
			/** @var object{method: string, id: int|string, params: object} $body */
			$body = json_decode($input, associative: false, flags: JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			$this->responseHandler->replyError(0, 'Failed to parse request body', 100);
			return;
		}

		try {
			$this->logger?->info("Request {$body->method}", ['body' => $body]);

			if($body->method === 'initialize') {
				$result = $this->initialize();
			} elseif($body->method === 'notifications/initialized') {
				http_response_code(202);
				$result = null;
			} elseif($body->method === 'prompts/list') {
				$result = $this->listPrompts($body->params);
			} elseif($body->method === 'prompts/get') {
				$result = $this->getPrompt($body->params);
			} elseif($body->method === 'resources/list') {
				$result = $this->listResources($body->params);
			} elseif($body->method === 'resources/read') {
				$result = $this->readResource($body->params);
			} elseif($body->method === 'resources/templates/list') {
				$result = $this->listResourceTemplates($body->params);
			} elseif($body->method === 'tools/list') {
				$result = $this->listTools($body->params);
			} elseif($body->method === 'tools/call') {
				$result = $this->callTool($body->params)->jsonSerialize();
			} elseif(str_starts_with($body->method, 'notifications/')) {
				// Do nothing, the server does not handle notifications
				$result = null;
			} else {
				throw new MCPInvalidArgumentException('Invalid method', 100);
			}

			if($result !== null) {
				$this->responseHandler->reply($body->id, $result);
			}
		} catch(MCPException $e) {
			$this->responseHandler->replyError($body->id, $e->getMessage(), $e->getCode() ?: 500);
		} catch(Throwable) {
			$this->responseHandler->replyError($body->id, 'Internal server error', 500);
		}
	}

	/**
	 * @return array{
	 *     protocolVersion: string,
	 *     serverInfo: array{name: string, version: string},
	 *     capabilities: array{
	 *         prompts?: array{listChanged: bool},
	 *         resources?: array{listChanged: bool},
	 *         tools?: array{listChanged: bool}
	 *     }
	 * }
	 */
	private function initialize(): array {
		$capabilities = [];

		//'resources' => new stdClass(),
		if(count($this->prompts)) {
			$capabilities['prompts'] = ['listChanged' => true];
		}

		if(count($this->resources)) {
			$capabilities['resources'] = ['listChanged' => true];
		}

		if(count($this->tools)) {
			$capabilities['tools'] = ['listChanged' => true];
		}

		$result = [
			'protocolVersion' => '2025-03-26',
			'serverInfo' => ['name' => $this->name, 'version' => '1.0.0'],
			'capabilities' => $capabilities
		];

		if($this->instructions !== null) {
			$result['instructions'] = $this->instructions;
		}

		return $result;
	}

	/**
	 * @param object $params
	 * @return array{
	 *     prompts: TPrompt[]
	 * }
	 */
	private function listPrompts(object $params): array {
		$prompts = [];
		foreach($this->prompts as $name => $prompt) {
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
				'arguments' => $arguments
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
		foreach($this->resources as $resource) {
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

			if(count($resource['inputSchema']['properties']) || array_key_exists('required', $resource['inputSchema'])) {
				$entry['inputSchema'] = $resource['inputSchema'];
			}

			$resources[] = $entry;
		}

		return ['resources' => $resources];
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

		$arguments = property_exists($params, 'arguments') && is_object($params->arguments) ? $params->arguments : new \stdClass();

		if(array_key_exists($params->uri, $this->resources)) {
			if($this->resourceHandler === null) {
				throw new MCPGeneralException('No resource handler registered', 500);
			}
			$handler = $this->resourceHandler;

			/** @var iterable<MCPResource> $resources */
			$resources = $handler((object) [
				'uri' => $params->uri,
				'arguments' => $arguments,
			]);

			$result = [];
			foreach($resources as $resource) {
				$resource->uri ??= $params->uri;
				$result[] = $resource;
			}

			return ['contents' => $result];
		}

		foreach($this->resourceTemplates as $template) {
			if($this->resourceHandler === null) {
				throw new MCPGeneralException('No resource handler registered', 500);
			}
			$handler = $this->resourceHandler;

			/** @var iterable<MCPResource> $resources */
			$resources = $handler((object) [
				'uri' => $params->uri,
				'arguments' => $arguments,
			]);

			$regex = sprintf('{%s}', preg_replace('{\{([a-zA-Z_][a-zA-Z0-9_]*)\}}', '(?<$1>[^/]+)', $template['uriTemplate']));

			if(preg_match($regex, $params->uri, $matches)) {
				$arguments = [];
				foreach($template['properties'] as $property) {
					$arguments[$property['name']] = $matches[$property['name']] ?? null;
				}

				$result = [];
				foreach($resources as $resource) {
					$resource->uri ??= $params->uri;
					$result[] = $resource;
				}
				return ['contents' => $result];
			}
		}

		throw new MCPInvalidArgumentException("Unknown resource: {$params->uri}", 100);
	}

	/**
	 * @param object $params
	 * @return array{resourceTemplates: array{uriTemplate: string, description?: string, inputSchema: array{type: 'object', properties: array<string, mixed>, required?: string[]}}[]}
	 */
	private function listResourceTemplates(object $params): array {
		$templates = [];
		foreach($this->resourceTemplates as $template) {
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
			unset($template['properties']);
			$template['inputSchema'] = ['type' => 'object', 'properties' => $properties, 'required' => $required];
			$templates[] = $template;
		}

		return ['resourceTemplates' => $templates];
	}

	/**
	 * @param object $params
	 * @return object{tools: array<object{name: string, description: string, inputSchema: object, returnSchema?: object}>}
	 */
	private function listTools(object $params): object {
		$tools = [];
		foreach($this->tools as $tool) {
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

		if(!property_exists($params, 'arguments') || !is_object($params->arguments)) {
			throw new MCPInvalidArgumentException('Tool call must include arguments object', 100);
		}

		if(!array_key_exists($params->name, $this->tools)) {
			throw new MCPInvalidArgumentException("Unknown tool: {$params->name}", 100);
		}
		$tool = $this->tools[$params->name];
		$handler = $tool->handler;
		return $handler($params->arguments);
	}
}
