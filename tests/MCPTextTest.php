<?php

declare(strict_types=1);

namespace McpSrv;

use McpSrv\Common\CapturingResponseHandler;
use McpSrv\Common\PromptResult\RoleEnum;
use McpSrv\Common\Request;
use Rkr\WatermarkRemoval\TextCleaner;
use McpSrv\Types\Prompts\MCPPromptArguments;
use McpSrv\Types\Prompts\MCPPromptResult;
use McpSrv\Types\Prompts\PromptResult\PromptResultStringMessage;
use McpSrv\Types\Resources\MCPResource;
use McpSrv\Types\Tools\MCPToolInputSchema;
use McpSrv\Types\Tools\MCPToolProperties;
use McpSrv\Types\Tools\MCPToolResult;
use PHPUnit\Framework\TestCase;

final class MCPTextTest extends TestCase {
	public function testRenderingCharactersArePreservedInContext(): void {
		$examples = [
			"👩🏽\u{200D}💻", "🏳\u{FE0F}\u{200D}🌈", "❤\u{FE0F}", "1\u{FE0F}\u{20E3}",
			"🏴\u{E0067}\u{E0062}\u{E0073}\u{E0063}\u{E0074}\u{E007F}",
			"می\u{200C}روم", "क्\u{200D}ष", "葛\u{E0100}", "ไทย\u{200B}ไทย",
			"abc \u{2067}שלום\u{2069}", "a\u{0301}\u{034F}\u{0327}",
		];
		foreach($examples as $example) {
			self::assertSame($example, TextCleaner::text($example));
		}
		foreach(["\u{200C}", "\u{200D}", "\u{202E}", "\u{034F}", "\u{FE0F}", "\u{E0100}", "\u{E0067}"] as $marker) {
			self::assertSame('Hallo', TextCleaner::text("Ha{$marker}llo"));
		}
	}

	public function testPromptAndResourceRequestsPreserveTheirIdentifiers(): void {
		$responses = new CapturingResponseHandler();
		$server = new MCPServer('test', $responses);
		$text = "Te\u{200B}xt";
		$name = "demo\u{200B}";
		$uri = "file://{$name}";
		$server->registerPrompt($name, $text, new MCPPromptArguments(),
			static fn(): MCPPromptResult => new MCPPromptResult($text, [new PromptResultStringMessage(RoleEnum::User, $text)]));
		$server->registerResource($uri, 'demo', $text, static fn(): array => [new MCPResource($text, 'text/plain')]);

		$server->run(Request::encode(['id' => 1, 'method' => 'prompts/get', 'params' => (object) ['name' => $name]]));
		/** @var array{description: string, messages: list<array{content: array{text: string}}>} $prompt */
		$prompt = json_decode(json_encode($responses->reply['result'] ?? null, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
		self::assertSame('Text', $prompt['description']);
		self::assertSame('Text', $prompt['messages'][0]['content']['text']);

		$server->run(Request::encode(['id' => 2, 'method' => 'resources/read', 'params' => (object) ['uri' => $uri]]));
		/** @var array{contents: list<array{text: string, uri: string}>} $resource */
		$resource = json_decode(json_encode($responses->reply['result'] ?? null, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
		self::assertSame('Text', $resource['contents'][0]['text']);
		self::assertSame($uri, $resource['contents'][0]['uri']);
	}

	public function testSchemaDescriptionsPreserveLiteralConstraints(): void {
		$marked = "a\u{200B}b";
		$literal = (object) ['description' => $marked];
		$schema = ['properties' => (object) ['description' => ['anyOf' => [
			['title' => $marked, 'pattern' => $marked], ['description' => $marked, 'const' => $literal],
		]]]];
		self::assertEquals(['properties' => (object) ['description' => ['anyOf' => [
			['title' => 'ab', 'pattern' => $marked], ['description' => 'ab', 'const' => $literal],
		]]]], TextCleaner::schema($schema));
	}

	public function testToolArgumentsAndBothResultRepresentationsAreCleaned(): void {
		$responses = new CapturingResponseHandler();
		$server = new MCPServer('test', $responses);
		$clean = "Grüße\t日本語\r\nCafe\u{0301} 🙂 \\u200b";
		$marked = "\u{FEFF}\u{200B}\u{2060}\u{00AD}\u{0000}\u{0080}" . $clean;
		$key = "key\u{200B}";
		$id = "id\u{200B}";
		$seen = null;
		$server->registerTool('echo', 'Echo', new MCPToolInputSchema(new MCPToolProperties()),
			static function(object $args) use (&$seen, $marked): MCPToolResult {
				$seen = $args;
				return new MCPToolResult(['output' => (object) ['values' => [$marked, false, 42, null]]], false);
			});
		$server->run(Request::encode(['id' => $id, 'method' => 'tools/call',
			'params' => (object) ['name' => 'echo', 'arguments' => (object) [$key => [$marked]]]]));

		self::assertEquals((object) [$key => [$clean]], $seen);
		self::assertSame($id, $responses->reply['id'] ?? null);
		/** @var array{content: list<array{text: string}>, structuredContent: mixed} $result */
		$result = (array) $responses->reply['result'];
		$expected = '{"output":{"values":' . json_encode([$clean, false, 42, null], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . '}}';
		self::assertSame($expected, $result['content'][0]['text']);
		self::assertSame($expected, json_encode($result['structuredContent'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
	}
}
