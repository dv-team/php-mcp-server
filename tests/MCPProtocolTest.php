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

class MCPProtocolTest extends TestCase {
	public function testDiscoveryAndCallsAreIndependentAndKeepCallbacks(): void {
		$handler = new CapturingResponseHandler();
		$server = new MCPServer('test', $handler, instructions: 'Use simple tools.');
		$seen = null;
		$server->registerTool('echo', 'Echo', new MCPToolInputSchema(new MCPToolProperties()),
			static function(object $arguments) use (&$seen): MCPToolResult {
				$seen = $arguments;
				return new MCPToolResult($arguments, false);
			});
		$server->run(Request::encode(['id' => 0, 'method' => 'tools/call', 'params' => (object) ['name' => 'echo']]));
		self::assertNotNull($handler->reply);
		self::assertSame(0, $handler->reply['id']);
		self::assertEquals((object) [], $seen);
		$result = (array) $handler->reply['result'];
		self::assertSame('complete', $result['resultType']);
		self::assertSame(false, $result['isError']);
		self::assertArrayHasKey('_meta', $result);

		$server->run(Request::encode(['id' => 'discover', 'method' => 'server/discover']));
		self::assertNotNull($handler->reply);
		$result = (array) $handler->reply['result'];
		self::assertSame(['2026-07-28'], $result['supportedVersions']);
		self::assertSame('Use simple tools.', $result['instructions']);
		self::assertEquals((object) ['tools' => (object) []], $result['capabilities']);
		self::assertEquals((object) ['io.modelcontextprotocol/serverInfo' => (object) ['name' => 'test', 'version' => '1.0.0']], $result['_meta']);

		// A preceding valid request must not supply the next request's metadata.
		$handler->reply = null;
		$server->run('{"jsonrpc":"2.0","id":2,"method":"tools/list"}');
		self::assertNull($handler->reply);
		self::assertSame(-32602, $handler->error['code'] ?? null);
	}

	/** @return iterable<string, array{string}> */
	public static function unsupportedVersions(): iterable {
		foreach(['2025-03-26', '2025-06-18', '2025-11-25', '2099-01-01'] as $version) {
			yield $version => [$version];
		}
	}

	#[DataProvider('unsupportedVersions')]
	public function testRejectsEveryOtherVersion(string $version): void {
		$handler = new CapturingResponseHandler();
		$server = new MCPServer('test', $handler);
		$request = str_replace('2026-07-28', $version, Request::encode(['id' => 1, 'method' => 'server/discover']));
		$server->run($request);
		self::assertNull($handler->reply);
		self::assertSame(-32022, $handler->error['code'] ?? null);
		self::assertSame(['supported' => ['2026-07-28'], 'requested' => $version], $handler->error['data'] ?? null);
	}

	/** @return iterable<string, array{string}> */
	public static function removedMethods(): iterable {
		foreach(['initialize', 'ping', 'logging/setLevel', 'resources/subscribe', 'subscriptions/listen', 'tasks/get'] as $method) {
			yield $method => [$method];
		}
	}

	#[DataProvider('removedMethods')]
	public function testUnsupportedMethodsHaveStandardError(string $method): void {
		$handler = new CapturingResponseHandler();
		(new MCPServer('test', $handler))->run(Request::encode(['id' => 1, 'method' => $method]));
		self::assertSame(-32601, $handler->error['code'] ?? null);
	}

	/** @return iterable<string, array{string}> */
	public static function invalidEnvelopes(): iterable {
		foreach(['null', '[]', '42', '"text"', '{}', '{"method":"tools/list","id":1}', '{"jsonrpc":"2.0","method":42,"id":1}', '{"jsonrpc":"2.0","method":"tools/list","id":null}', '{"jsonrpc":"2.0","method":"tools/list","id":1,"params":[]}'] as $json) {
			yield $json => [$json];
		}
	}

	#[DataProvider('invalidEnvelopes')]
	public function testInvalidEnvelopesUseNullIdWithoutChangingResponseInterface(string $json): void {
		$handler = new CapturingResponseHandler();
		(new MCPServer('test', $handler))->run($json);
		self::assertSame(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Invalid JSON-RPC request']], $handler->raw);
	}

	public function testNotificationCannotInvokeATool(): void {
		$handler = new CapturingResponseHandler();
		$server = new MCPServer('test', $handler);
		$server->registerTool('test', 'Test', new MCPToolInputSchema(new MCPToolProperties()), static function(object $input): MCPToolResult {
			self::fail('A notification must not execute this handler');
		});
		$server->run('{"jsonrpc":"2.0","method":"tools/call","params":{"name":"test","arguments":{}}}');
		self::assertNull($handler->reply);
		self::assertNull($handler->error);
		self::assertNull($handler->raw);
	}

	public function testListOrderIsIndependentOfRegistrationOrder(): void {
		$handler = new CapturingResponseHandler();
		$server = new MCPServer('test', $handler);
		foreach(['z', 'a'] as $name) {
			$server->registerTool($name, $name, new MCPToolInputSchema(new MCPToolProperties()), static fn(object $input): MCPToolResult => new MCPToolResult([], false));
		}
		$server->run(Request::encode(['id' => 1, 'method' => 'tools/list']));
		self::assertNotNull($handler->reply);
		/** @var object{tools: list<object{name: string}>} $result */
		$result = $handler->reply['result'];
		self::assertSame(['a', 'z'], array_map(static fn(object $tool): string => $tool->name, $result->tools));
	}

	public function testCliContinuesAfterMalformedJsonWithoutLogger(): void {
		$handler = new CapturingResponseHandler();
		$stream = fopen('php://memory', 'r+');
		self::assertIsResource($stream);
		fwrite($stream, "{\n" . Request::encode(['id' => 2, 'method' => 'server/discover']) . "\n");
		rewind($stream);
		try {
			(new MCPServer('test', $handler))->runCli($stream);
		} finally {
			fclose($stream);
		}
		self::assertSame(2, $handler->reply['id'] ?? null);
		self::assertNotNull($handler->raw);
	}
}
