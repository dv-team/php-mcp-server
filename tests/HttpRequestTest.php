<?php

declare(strict_types=1);

namespace McpSrv;

use McpSrv\Common\HttpRequest;
use McpSrv\Common\MCPInvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HttpRequestTest extends TestCase {
	/** @return array<string, string> */
	private static function headers(): array {
		return ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => 'tools/call', 'Mcp-Name' => 'echo'];
	}

	private static function params(): \stdClass {
		return (object) ['name' => 'echo', '_meta' => (object) ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']];
	}

	public function testHeaderNamesAreCaseInsensitive(): void {
		(new HttpRequest(array_change_key_case(self::headers(), CASE_LOWER)))->validate('tools/call', self::params());
		$this->addToAssertionCount(1);
	}

	/** @return iterable<string, array{string, string|null}> */
	public static function invalidHeaders(): iterable {
		yield 'missing version' => ['MCP-Protocol-Version', null];
		yield 'missing method' => ['Mcp-Method', null];
		yield 'missing name' => ['Mcp-Name', null];
		yield 'version mismatch' => ['MCP-Protocol-Version', '2025-11-25'];
		yield 'method mismatch' => ['Mcp-Method', 'tools/list'];
		yield 'method case' => ['Mcp-Method', 'Tools/Call'];
		yield 'name mismatch' => ['Mcp-Name', 'other'];
		yield 'name newline' => ['Mcp-Name', "echo\r\nx: y"];
		yield 'bad encoding' => ['Mcp-Name', '=?base64?!!!?='];
		yield 'invalid utf8' => ['Mcp-Name', '=?base64?/w==?='];
	}

	#[DataProvider('invalidHeaders')]
	public function testRejectsHeaderMismatch(string $name, ?string $value): void {
		$headers = self::headers();
		if($value === null) {
			unset($headers[$name]);
		} else {
			$headers[$name] = $value;
		}
		$this->expectException(MCPInvalidArgumentException::class);
		$this->expectExceptionCode(-32020);
		(new HttpRequest($headers))->validate('tools/call', self::params());
	}

	/** @return iterable<string, array{string}> */
	public static function encodedNames(): iterable {
		foreach(['Grüße', ' padded ', "line\nbreak", '=?base64?literal?='] as $name) {
			yield $name => [$name];
		}
	}

	#[DataProvider('encodedNames')]
	public function testDecodesNamesExactlyOnce(string $name): void {
		$params = self::params();
		$params->name = $name;
		$headers = self::headers();
		$headers['Mcp-Name'] = '=?base64?' . base64_encode($name) . '?=';
		(new HttpRequest($headers))->validate('tools/call', $params);
		$this->addToAssertionCount(1);
	}

	public function testResourceHeaderUsesUri(): void {
		$params = self::params();
		$params->uri = 'file://demo.txt';
		$headers = self::headers();
		$headers['Mcp-Method'] = 'resources/read';
		$headers['Mcp-Name'] = 'file://demo.txt';
		(new HttpRequest($headers))->validate('resources/read', $params);
		$this->addToAssertionCount(1);
	}

	public function testNestedCustomHeadersAndFalseAndZero(): void {
		$schema = (object) ['properties' => [
			'context' => ['properties' => ['region' => ['type' => 'string', 'x-mcp-header' => 'Region']]],
			'count' => ['type' => 'integer', 'x-mcp-header' => 'Count'],
			'enabled' => ['type' => 'boolean', 'x-mcp-header' => 'Enabled'],
		]];
		$params = self::params();
		$params->arguments = (object) ['context' => (object) ['region' => 'eu'], 'count' => 0, 'enabled' => false];
		$headers = self::headers() + ['Mcp-Param-Region' => 'eu', 'Mcp-Param-Count' => '0', 'Mcp-Param-Enabled' => 'false'];
		(new HttpRequest($headers))->validate('tools/call', $params, $schema);
		unset($headers['Mcp-Param-Count']);
		$this->expectExceptionCode(-32020);
		$this->expectException(MCPInvalidArgumentException::class);
		(new HttpRequest($headers))->validate('tools/call', $params, $schema);
	}

	public function testHandlerCannotReceiveADifferentValueFromItsBoundHeader(): void {
		$value = "e\u{200B}u";
		$params = self::params();
		$params->arguments = (object) ['region' => $value];
		$schema = (object) ['properties' => ['region' => ['type' => 'string', 'x-mcp-header' => 'Region']]];
		$this->expectExceptionCode(-32020);
		(new HttpRequest(self::headers() + ['Mcp-Param-Region' => '=?base64?' . base64_encode($value) . '?=']))
			->validate('tools/call', $params, $schema);
	}

	public function testCustomHeaderCannotClaimAnAbsentArgument(): void {
		$schema = (object) ['properties' => ['region' => ['type' => 'string', 'x-mcp-header' => 'Region']]];
		(new HttpRequest(self::headers()))->validate('tools/call', self::params(), $schema);
		$this->expectExceptionCode(-32020);
		$this->expectException(MCPInvalidArgumentException::class);
		(new HttpRequest(self::headers() + ['Mcp-Param-Region' => 'eu']))->validate('tools/call', self::params(), $schema);
	}

	public function testIntegerHeadersCompareNumerically(): void {
		$schema = (object) ['properties' => ['count' => ['type' => 'integer', 'x-mcp-header' => 'Count']]];
		$params = self::params();
		$params->arguments = (object) ['count' => 42.0];
		(new HttpRequest(self::headers() + ['Mcp-Param-Count' => '42']))->validate('tools/call', $params, $schema);
		$params->arguments = (object) ['count' => 42];
		(new HttpRequest(self::headers() + ['Mcp-Param-Count' => '42.0']))->validate('tools/call', $params, $schema);
		$params->arguments = (object) ['count' => 9007199254740992];
		$this->expectExceptionCode(-32020);
		$this->expectException(MCPInvalidArgumentException::class);
		(new HttpRequest(self::headers() + ['Mcp-Param-Count' => '9007199254740992']))->validate('tools/call', $params, $schema);
	}
}
