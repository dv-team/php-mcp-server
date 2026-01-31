<?php

declare(strict_types=1);

namespace McpSrv;

use McpSrv\Common\AttributeToolRegistrarFixture;
use McpSrv\Common\CapturingResponseHandler;
use McpSrv\Common\Tools\AttributeToolRegistrar;
use JsonSchema\Constraints\Factory;
use JsonSchema\SchemaStorage;
use JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

class AttributeToolRegistrarTest extends TestCase {
	public function testRegisterBuildsInputSchemaFromAttributes(): void {
		$handler = new CapturingResponseHandler();
		$server = new MCPServer('test', $handler);

		AttributeToolRegistrar::registerObject(new AttributeToolRegistrarFixture(), $server);

		$request = json_encode([
			'method' => 'tools/list',
			'id' => 31,
			'params' => (object) [],
		], JSON_THROW_ON_ERROR);

		$server->run($request);

		$this->assertNotNull($handler->reply);
		$this->assertSame(31, $handler->reply['id']);
		$this->assertIsObject($handler->reply['result']);
		/** @var object{tools: list<object{name: string, description: string, isDangerous: bool, inputSchema: object}>} $result */
		$result = $handler->reply['result'];
		$this->assertCount(2, $result->tools);

		$toolsByName = [];
		foreach($result->tools as $tool) {
			$this->assertToolSchemaIsValid($tool);
			$toolsByName[$tool->name] = $tool;
		}

		$this->assertArrayHasKey('sum', $toolsByName);
		$sum = $toolsByName['sum'];
		$this->assertSame('Sum numbers', $sum->description);
		$this->assertTrue($sum->isDangerous);

		/** @var object{type: string, properties: object{a: object, b: object}, required?: string[]} $sumSchema */
		$sumSchema = $sum->inputSchema;
		$this->assertSame('object', $sumSchema->type);
		$this->assertObjectHasProperty('a', $sumSchema->properties);
		$this->assertSame('integer', $sumSchema->properties->a->type ?? null);
		$this->assertSame('First number', $sumSchema->properties->a->description ?? null);
		$this->assertObjectHasProperty('b', $sumSchema->properties);
		$this->assertSame('integer', $sumSchema->properties->b->type ?? null);
		$this->assertSame('Second number', $sumSchema->properties->b->description ?? null);

		/** @var string[] $required */
		$required = $sumSchema->required ?? [];
		$this->assertContains('a', $required);
		$this->assertContains('b', $required);

		$this->assertArrayHasKey('echo', $toolsByName);
		$echo = $toolsByName['echo'];
		$this->assertFalse($echo->isDangerous);

		/** @var object{type: string, properties: object{message: object}, required?: string[]} $echoSchema */
		$echoSchema = $echo->inputSchema;
		$this->assertObjectHasProperty('message', $echoSchema->properties);
		$this->assertSame('string', $echoSchema->properties->message->type ?? null);
		$this->assertSame('Message', $echoSchema->properties->message->description ?? null);
		$echoRequired = $echoSchema->required ?? [];
		$this->assertContains('message', $echoRequired);
	}

	private function assertToolSchemaIsValid(object $tool): void {
		$schemaPath = realpath(__DIR__ . '/../schema/2025-11-25/schema.json');
		if($schemaPath === false) {
			self::fail('MCP schema file not found.');
		}

		$schema = json_decode((string) file_get_contents($schemaPath), false, 512, JSON_THROW_ON_ERROR);
		if(!is_object($schema)) {
			self::fail('MCP schema must decode to an object.');
		}
		$schemaUri = 'file://' . $schemaPath;

		$schemaStorage = new SchemaStorage();
		$schemaStorage->addSchema($schemaUri, $schema);
		$validator = new Validator(new Factory($schemaStorage));

		$validator->validate($tool, (object) ['$ref' => $schemaUri . '#/$defs/Tool']);

		if($validator->isValid()) {
			return;
		}

		$messages = [];
		foreach($validator->getErrors() as $error) {
			if(!is_array($error)) {
				continue;
			}
			$property = '';
			if(array_key_exists('property', $error) && is_string($error['property'])) {
				$property = $error['property'];
			}
			$message = '';
			if(array_key_exists('message', $error) && is_string($error['message'])) {
				$message = $error['message'];
			}
			$messages[] = sprintf('%s: %s', $property, $message);
		}
		self::fail("Schema validation failed:\n" . implode("\n", $messages));
	}

	public function testInvokeToolHandlesDefaultsAndMissingArgs(): void {
		$handler = new CapturingResponseHandler();
		$server = new MCPServer('test', $handler);

		AttributeToolRegistrar::registerObject(new AttributeToolRegistrarFixture(), $server);

		$request = json_encode([
			'method' => 'tools/call',
			'id' => 41,
			'params' => (object) [
				'name' => 'echo',
				'arguments' => (object) [],
			],
		], JSON_THROW_ON_ERROR);

		$server->run($request);

		$this->assertNotNull($handler->reply);
		$this->assertSame(41, $handler->reply['id']);
		$this->assertIsArray($handler->reply['result']);
		$result = $handler->reply['result'];
		$this->assertArrayHasKey('structuredContent', $result);
		$this->assertIsArray($result['structuredContent']);
		$this->assertSame('default', $result['structuredContent']['value'] ?? null);

		$handler = new CapturingResponseHandler();
		$server = new MCPServer('test', $handler);

		AttributeToolRegistrar::registerObject(new AttributeToolRegistrarFixture(), $server);

		$request = json_encode([
			'method' => 'tools/call',
			'id' => 42,
			'params' => (object) [
				'name' => 'sum',
				'arguments' => (object) ['a' => 1],
			],
		], JSON_THROW_ON_ERROR);

		$server->run($request);

		$this->assertNotNull($handler->error);
		$this->assertSame(42, $handler->error['id']);
		$this->assertSame("Missing required argument 'b'", $handler->error['message']);
		$this->assertSame(100, $handler->error['code']);
	}

	public function testRegisterMethodRegistersSingleTool(): void {
		$handler = new CapturingResponseHandler();
		$server = new MCPServer('test', $handler);
		$fixture = new AttributeToolRegistrarFixture();

		AttributeToolRegistrar::registerMethod($fixture, new \ReflectionMethod($fixture, 'sum'), $server);

		$request = json_encode([
			'method' => 'tools/list',
			'id' => 51,
			'params' => (object) [],
		], JSON_THROW_ON_ERROR);

		$server->run($request);

		$this->assertNotNull($handler->reply);
		$this->assertSame(51, $handler->reply['id']);
		/** @var object{tools: list<object{name: string}>} $result */
		$result = $handler->reply['result'];
		$this->assertCount(1, $result->tools);
		$this->assertSame('sum', $result->tools[0]->name);
	}

	public function testRegisterFnRegistersInstanceCallable(): void {
		$handler = new CapturingResponseHandler();
		$server = new MCPServer('test', $handler);
		$fixture = new AttributeToolRegistrarFixture();

		AttributeToolRegistrar::registerFn($fixture->echoMessage(...), $server);

		$request = json_encode([
			'method' => 'tools/list',
			'id' => 52,
			'params' => (object) [],
		], JSON_THROW_ON_ERROR);

		$server->run($request);

		$this->assertNotNull($handler->reply);
		$this->assertSame(52, $handler->reply['id']);
		/** @var object{tools: list<object{name: string}>} $result */
		$result = $handler->reply['result'];
		$this->assertCount(1, $result->tools);
		$this->assertSame('echo', $result->tools[0]->name);
	}
}
