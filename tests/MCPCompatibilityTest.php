<?php

declare(strict_types=1);

namespace McpSrv;

use McpSrv\Common\CapturingResponseHandler;
use McpSrv\Common\Request;
use McpSrv\Types\Tools\MCPToolInputSchema;
use McpSrv\Types\Tools\MCPToolProperties;
use McpSrv\Types\Tools\MCPToolResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MCPCompatibilityTest extends TestCase {
	private static function initialization(string $version): string {
		return json_encode([
			'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
			'params' => ['protocolVersion' => $version, 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
		], JSON_THROW_ON_ERROR);
	}

	private static function server(CapturingResponseHandler $handler): MCPServer {
		$server = new MCPServer('test', $handler, instructions: 'Use simple tools.');
		$server->registerTool('echo', 'Echo', new MCPToolInputSchema(new MCPToolProperties()),
			static fn(object $arguments): MCPToolResult => new MCPToolResult($arguments, false),
			returnSchema: (object) ['type' => 'object']);
		return $server;
	}

	/** @return iterable<string, array{string}> */
	public static function legacyVersions(): iterable {
		foreach(['2025-06-18', '2025-11-25'] as $version) {
			yield $version => [$version];
		}
	}

	#[DataProvider('legacyVersions')]
	public function testLegacyHandshakeAndOrdinaryCallsWithoutModernMetadata(string $version): void {
		$handler = new CapturingResponseHandler();
		$server = self::server($handler);
		$server->run(self::initialization($version));
		self::assertNull($handler->error);
		self::assertNotNull($handler->reply);
		self::assertEquals((object) [
			'protocolVersion' => $version,
			'capabilities' => (object) ['tools' => (object) []],
			'serverInfo' => (object) ['name' => 'test', 'version' => '1.0.0'],
			'instructions' => 'Use simple tools.',
		], $handler->reply['result']);

		$handler->reply = null;
		$server->run('{"jsonrpc":"2.0","method":"notifications/initialized"}');
		self::assertNull($handler->reply);
		$server->run('{"jsonrpc":"2.0","id":2,"method":"tools/list"}');
		self::assertNotNull($handler->reply);
		/** @var object{tools: list<object{name: string, outputSchema?: object}>} $result */
		$result = $handler->reply['result'];
		self::assertSame(['tools'], array_keys((array) $result));
		self::assertSame('echo', $result->tools[0]->name);
		self::assertObjectHasProperty('outputSchema', $result->tools[0]);

		$server->run('{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"echo","arguments":{"value":42},"_meta":{"progressToken":"ignored"}}}');
		self::assertNotNull($handler->reply);
		$result = (array) $handler->reply['result'];
		self::assertSame([['type' => 'text', 'text' => '{"value":42}']], $result['content']);
		self::assertSame(false, $result['isError']);
		self::assertEquals((object) ['value' => 42], $result['structuredContent']);
		self::assertArrayNotHasKey('resultType', $result);
		self::assertArrayNotHasKey('_meta', $result);
		self::assertArrayNotHasKey('ttlMs', $result);
		self::assertNull($handler->error);
	}

	public function testUnknownInitializationVersionNegotiatesLatestLegacyVersion(): void {
		$handler = new CapturingResponseHandler();
		self::server($handler)->run(self::initialization('2099-01-01'));
		self::assertNotNull($handler->reply);
		self::assertSame('2025-11-25', ((array) $handler->reply['result'])['protocolVersion']);
	}

	/** @return iterable<string, array{string}> */
	public static function malformedInitializations(): iterable {
		foreach([
			'{}',
			'{"protocolVersion":42,"capabilities":{},"clientInfo":{"name":"test","version":"1"}}',
			'{"protocolVersion":"2025-06-18","clientInfo":{"name":"test","version":"1"}}',
			'{"protocolVersion":"2025-06-18","capabilities":[],"clientInfo":{"name":"test","version":"1"}}',
			'{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{}}',
		] as $params) {
			yield $params => [$params];
		}
	}

	#[DataProvider('malformedInitializations')]
	public function testMalformedInitializationCannotEstablishLegacyStdio(string $params): void {
		$handler = new CapturingResponseHandler();
		$server = self::server($handler);
		$server->run('{"jsonrpc":"2.0","id":1,"method":"initialize","params":' . $params . '}');
		self::assertNull($handler->reply);
		self::assertSame(-32602, $handler->error['code'] ?? null);
		$server->run('{"jsonrpc":"2.0","id":2,"method":"tools/list"}');
		self::assertNull($handler->reply);
		self::assertSame(2, $handler->error['id']);
		self::assertSame(-32602, $handler->error['code']);
	}

	public function testModernRequestsRemainIndependentOfLegacyInitialization(): void {
		$handler = new CapturingResponseHandler();
		$server = self::server($handler);
		$server->run(self::initialization('2025-06-18'));
		$server->run(Request::encode(['id' => 2, 'method' => 'tools/call', 'params' => (object) ['name' => 'echo']]));
		self::assertNotNull($handler->reply);
		$result = (array) $handler->reply['result'];
		self::assertSame('complete', $result['resultType']);
		self::assertArrayHasKey('structuredContent', $result);

		$server->run('{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"echo"}}');
		self::assertNotNull($handler->reply);
		$result = (array) $handler->reply['result'];
		self::assertArrayNotHasKey('resultType', $result);
		self::assertArrayHasKey('structuredContent', $result);

		$handler->reply = null;
		$server->run('{"jsonrpc":"2.0","id":4,"method":"tools/list","params":{"_meta":{"io.modelcontextprotocol/protocolVersion":"2026-07-28"}}}');
		self::assertNull($handler->reply);
		self::assertSame(-32602, $handler->error['code'] ?? null);
	}

	#[DataProvider('legacyVersions')]
	public function testLegacyVersionCannotUseModernMetadata(string $version): void {
		$handler = new CapturingResponseHandler();
		self::server($handler)->run(str_replace('2026-07-28', $version, Request::encode(['id' => 1, 'method' => 'tools/list'])));
		self::assertNull($handler->reply);
		self::assertSame(-32602, $handler->error['code'] ?? null);
	}

	public function testLegacyPingBeforeAndAfterInitialization(): void {
		$handler = new CapturingResponseHandler();
		$server = self::server($handler);
		foreach([false, true] as $initialized) {
			if($initialized) {
				$server->run(self::initialization('2025-06-18'));
			}
			$server->run('{"jsonrpc":"2.0","id":"ping","method":"ping"}');
			self::assertEquals(['id' => 'ping', 'result' => (object) []], $handler->reply);
			self::assertNull($handler->error);
		}
	}
}
