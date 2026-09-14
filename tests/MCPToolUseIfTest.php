<?php

declare(strict_types=1);

namespace McpSrv;

use McpSrv\Common\AttributeToolRegistrarFixture;
use McpSrv\Common\CapturingResponseHandler;
use McpSrv\Common\Request;
use McpSrv\Common\Tools\AttributeToolRegistrar;
use McpSrv\Types\Tools\MCPToolInputSchema;
use McpSrv\Types\Tools\MCPToolProperties;
use McpSrv\Types\Tools\MCPToolResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class MCPToolUseIfTest extends TestCase {
	/** @return iterable<string, array{string, int}> */
	public static function registrationPaths(): iterable {
		yield 'object' => ['object', 2];
		yield 'callable' => ['callable', 0];
		yield 'method' => ['method', 0];
	}

	#[DataProvider('registrationPaths')]
	public function testAutomaticRegistrationSkipsDisabledToolsBeforeSchemaProcessing(string $path, int $count): void {
		$responses = new CapturingResponseHandler();
		$server = new MCPServer('test', $responses);
		$fixture = new AttributeToolRegistrarFixture();
		match($path) {
			'object' => $server->registerToolsFromObject($fixture),
			'callable' => $server->registerToolFromMethod($fixture->disabled(...)),
			default => AttributeToolRegistrar::registerMethod($fixture, new ReflectionMethod($fixture, 'disabled'), $server),
		};
		$server->run(Request::encode(['id' => 1, 'method' => 'tools/list']));
		/** @var array{tools: list<object{name: string}>} $result */
		$result = (array) ($responses->reply['result'] ?? []);
		self::assertCount($count, $result['tools']);
		self::assertNotContains('disabled', array_column($result['tools'], 'name'));
		$server->run(Request::encode(['id' => 2, 'method' => 'tools/call', 'params' => (object) ['name' => 'disabled']]));
		self::assertSame(-32602, $responses->error['code'] ?? null);
		self::assertSame('Unknown tool: disabled', $responses->error['message']);
	}

	public function testDisabledAttributeDoesNotReplaceAnEnabledTool(): void {
		$responses = new CapturingResponseHandler();
		$server = new MCPServer('test', $responses);
		$schema = new MCPToolInputSchema(new MCPToolProperties());
		$server->registerTool('disabled', 'Enabled', $schema,
			static fn(object $input): MCPToolResult => new MCPToolResult(['source' => 'enabled'], false));
		$fixture = new AttributeToolRegistrarFixture();
		$server->registerToolFromMethod($fixture->disabled(...));
		$server->run(Request::encode(['id' => 1, 'method' => 'tools/call', 'params' => (object) ['name' => 'disabled']]));
		$result = (array) ($responses->reply['result'] ?? []);
		self::assertSame(['source' => 'enabled'], $result['structuredContent']);
	}
}
