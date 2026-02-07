<?php

declare(strict_types=1);

namespace McpSrv;

use McpSrv\Common\CapturingResponseHandler;
use McpSrv\Types\Resources\MCPResource;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-type TProperty array{name: string, type: string, description: string, required?: bool}
 * @phpstan-type TTestProperties array{name: TProperty, format: TProperty}
 */
class MCPResourceTest extends TestCase {
	public function testListResourcesIncludesSchema(): void {
		$handler = new CapturingResponseHandler();
		$server = new MCPServer('test', $handler);

		$server->registerResource(
			uri: 'file://example.txt',
			name: 'Example file',
			description: 'Example resource',
			properties: [
				'path' => ['type' => 'string', 'description' => 'path'],
			],
			required: ['path']
		);

		$request = json_encode([
			'method' => 'resources/list',
			'id' => 11,
			'params' => (object) [],
		], JSON_THROW_ON_ERROR);

		$server->run($request);

		$this->assertNotNull($handler->reply);
		$this->assertSame(11, $handler->reply['id']);
		$this->assertIsArray($handler->reply['result']);
		/** @var array{resources: list<array<string, mixed>>} $result */
		$result = $handler->reply['result'];
		$this->assertCount(1, $result['resources']);

		/** @var array{name: string, uri: string, inputSchema: array{required?: array<int, string>, properties: object}} $resource */
		$resource = $result['resources'][0];
		$this->assertSame('Example file', $resource['name']);
		$this->assertSame('file://example.txt', $resource['uri']);
		$this->assertArrayNotHasKey('mimeType', $resource);
		$this->assertArrayHasKey('inputSchema', $resource);
		$inputSchema = $resource['inputSchema'];
		$required = $inputSchema['required'] ?? [];
		$this->assertSame(['path'], $required);
		$this->assertObjectHasProperty('path', $inputSchema['properties']);
	}

	public function testListResourceTemplatesIncludesSchema(): void {
		$handler = new CapturingResponseHandler();
		$server = new MCPServer('test', $handler);

		$server->registerResourceTemplate(
			uriTemplate: 'file://{path}',
			description: 'File template',
			properties: [
				['name' => 'path', 'type' => 'string', 'description' => 'path', 'required' => true],
				['name' => 'format', 'type' => 'string', 'description' => 'format'],
			],
			handler: static fn (object $args): array => []
		);

		$request = json_encode([
			'method' => 'resources/templates/list',
			'id' => 12,
			'params' => (object) [],
		], JSON_THROW_ON_ERROR);

		$server->run($request);

		$this->assertNotNull($handler->reply);
		$this->assertSame(12, $handler->reply['id']);
		$this->assertIsArray($handler->reply['result']);
		/** @var array{resourceTemplates: list<array{uriTemplate: string, description?: string, inputSchema: array{type: string, properties: array<string, mixed>, required?: array<int, string>}}>} $result */
		$result = $handler->reply['result'];
		$this->assertCount(1, $result['resourceTemplates']);

		/** @var array{uriTemplate: string, description?: string, inputSchema: array{type: string, properties: array<string, mixed>, required?: array<int, string>}} $template */
		$template = $result['resourceTemplates'][0];
		$this->assertSame('file://{path}', $template['uriTemplate']);
		$this->assertSame('File template', $template['description'] ?? null);
		$this->assertArrayHasKey('inputSchema', $template);

		/** @var array{type: string, properties: array{type: string, properties: object, required?: string[]}, required?: string[]} $inputSchema */
		$inputSchema = $template['inputSchema'];
		$this->assertSame('object', $inputSchema['type']);
		$required = $inputSchema['required'] ?? [];
		$this->assertSame(['path'], $required);
		$this->assertArrayHasKey('path', $inputSchema['properties']);

		$this->assertSame('string', $inputSchema['properties']['path']['type'] ?? null); // @phpstan-ignore-line
		$this->assertArrayHasKey('format', $inputSchema['properties']);
		$this->assertSame('string', $inputSchema['properties']['format']['type'] ?? null); // @phpstan-ignore-line
	}

	public function testResourceTemplateHandlerUsesParsedArguments(): void {
		$handler = new CapturingResponseHandler();
		$server = new MCPServer('test', $handler);
		$seenArgs = null;

		$server->registerResourceTemplate(
			uriTemplate: 'file://{path}',
			description: 'File template',
			properties: [
				['name' => 'path', 'type' => 'string', 'description' => 'path', 'required' => true],
				['name' => 'format', 'type' => 'string', 'description' => 'format'],
			],
			handler: function(object $args) use (&$seenArgs): array {
				$seenArgs = $args;
				return [new MCPResource('ok', 'text/plain')];
			}
		);

		$request = json_encode([
			'method' => 'resources/read',
			'id' => 13,
			'params' => (object) [
				'uri' => 'file://demo.txt',
				'arguments' => (object) ['path' => 'ignored.txt', 'format' => 'text'],
			],
		], JSON_THROW_ON_ERROR);

		$server->run($request);

		$this->assertNull($handler->error);
		$this->assertNotNull($handler->reply);
		$this->assertNotNull($seenArgs);
		$this->assertSame('file://demo.txt', $seenArgs->uri ?? null);
		$this->assertSame('demo.txt', $seenArgs->arguments->path ?? null);
		$this->assertSame('text', $seenArgs->arguments->format ?? null);

		/** @var array{contents: array<int, MCPResource>} $result */
		$result = $handler->reply['result'];
		$this->assertArrayHasKey('contents', $result);
		$this->assertCount(1, $result['contents']);
	}
}
