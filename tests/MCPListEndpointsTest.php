<?php

declare(strict_types=1);

namespace McpSrv;

use McpSrv\Common\CapturingResponseHandler;
use McpSrv\Common\PromptResult\RoleEnum;
use McpSrv\Common\Properties\MCPToolString;
use McpSrv\Types\Prompts\MCPPromptArgument;
use McpSrv\Types\Prompts\MCPPromptArguments;
use McpSrv\Types\Prompts\MCPPromptResult;
use McpSrv\Types\Prompts\PromptResult\PromptResultStringMessage;
use McpSrv\Types\Tools\MCPToolInputSchema;
use McpSrv\Types\Tools\MCPToolProperties;
use McpSrv\Types\Tools\MCPToolResult;
use PHPUnit\Framework\TestCase;

class MCPListEndpointsTest extends TestCase {
	public function testPromptsListIncludesArguments(): void {
		$handler = new CapturingResponseHandler();
		$server = new MCPServer('test', $handler);

		$server->registerPrompt(
			name: 'greet',
			description: 'Greeting prompt',
			arguments: new MCPPromptArguments(
				new MCPPromptArgument('name', 'Person name', true)
			),
			handler: static fn (object $args): MCPPromptResult => new MCPPromptResult(
				description: 'Greeting prompt',
				messages: [new PromptResultStringMessage(RoleEnum::User, 'hi')]
			)
		);

		$request = json_encode([
			'method' => 'prompts/list',
			'id' => 21,
			'params' => (object) [],
		], JSON_THROW_ON_ERROR);

		$server->run($request);

		$this->assertNotNull($handler->reply);
		$this->assertSame(21, $handler->reply['id']);
		$this->assertIsArray($handler->reply['result']);
		/** @var array{prompts: list<array{name: string, description: string, arguments: list<array{name: string, description: string|null, required: bool}>}>} $result */
		$result = $handler->reply['result'];
		$this->assertCount(1, $result['prompts']);
		$prompt = $result['prompts'][0];
		$this->assertSame('greet', $prompt['name']);
		$this->assertSame('Greeting prompt', $prompt['description']);
		$this->assertArrayHasKey('arguments', $prompt);
		$arguments = $prompt['arguments'];
		$this->assertCount(1, $arguments);
		$argument = $arguments[0];
		$this->assertSame('name', $argument['name']);
		$this->assertTrue($argument['required']);
	}

	public function testToolsListIncludesSchema(): void {
		$handler = new CapturingResponseHandler();
		$server = new MCPServer('test', $handler);

		$server->registerTool(
			name: 'echo_tool',
			description: 'Echoes input',
			inputSchema: new MCPToolInputSchema(
				properties: new MCPToolProperties(
					new MCPToolString(name: 'text', description: 'text to echo', required: true)
				)
			),
			isDangerous: false,
			handler: static fn (object $input): MCPToolResult => new MCPToolResult(content: (object) ['echo' => $input->text ?? null], isError: false)
		);

		$request = json_encode(['method' => 'tools/list', 'id' => 22, 'params' => (object) []], JSON_THROW_ON_ERROR);

		$server->run($request);

		$this->assertNotNull($handler->reply);
		$this->assertSame(22, $handler->reply['id']);
		$this->assertIsObject($handler->reply['result']);
		/** @var object{tools: list<object{name: string, description: string, inputSchema: object{type: string, properties: array<string, mixed>|object, required?: list<string>}}>} $result */
		$result = $handler->reply['result'];
		$this->assertCount(1, $result->tools);
		$tool = $result->tools[0];
		$this->assertSame('echo_tool', $tool->name);
		$this->assertObjectHasProperty('inputSchema', $tool);
		$inputSchema = $tool->inputSchema;
		$this->assertSame('object', $inputSchema->type);
		$this->assertObjectHasProperty('properties', $inputSchema);
		$this->assertIsObject($inputSchema->properties);
		$properties = $inputSchema->properties;
		$this->assertObjectHasProperty('text', $properties);
		$required = $inputSchema->required ?? [];
		$this->assertContains('text', $required);
	}
}
