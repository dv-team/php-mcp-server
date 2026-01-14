<?php

declare(strict_types=1);

namespace McpSrv;

use McpSrv\Common\CapturingResponseHandler;
use PHPUnit\Framework\TestCase;

class MCPResourceTest extends TestCase {
	public function testListResourcesIncludesSchema(): void {
		$handler = new CapturingResponseHandler();
		$server = new MCPServer('test', $handler);

		$server->registerResource(
			uri: 'file://example.txt',
			name: 'Example file',
			description: 'Example resource',
			handler: static fn (object $args): array => [['uri' => 'file://example.txt', 'text' => 'ignored']],
			mimeType: 'text/plain',
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

		/** @var array{name: string, uri: string, mimeType?: string, inputSchema: array{required?: array<int, string>, properties: array<string, mixed>}} $resource */
		$resource = $result['resources'][0];
		$this->assertSame('Example file', $resource['name']);
		$this->assertSame('file://example.txt', $resource['uri']);
		if(!array_key_exists('mimeType', $resource)) {
			self::fail('Expected mimeType on resource');
		}
		$this->assertSame('text/plain', $resource['mimeType']);
		$this->assertArrayHasKey('inputSchema', $resource);
		$inputSchema = $resource['inputSchema'];
		$required = $inputSchema['required'] ?? [];
		$this->assertSame(['path'], $required);
		$this->assertArrayHasKey('path', $inputSchema['properties']);
	}
}
