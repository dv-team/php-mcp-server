<?php

declare(strict_types=1);

namespace McpSrv;

use InvalidArgumentException;
use McpSrv\Common\Properties\MCPToolEnum;
use McpSrv\Common\Properties\MCPToolInteger;
use McpSrv\Common\Properties\MCPToolString;
use McpSrv\Types\Tools\MCPToolInputSchema;
use McpSrv\Types\Tools\MCPToolProperties;
use PHPUnit\Framework\TestCase;

class MCPToolSchemaTest extends TestCase {
	public function testRequiredFieldsAreMergedAndUnique(): void {
		$schema = new MCPToolInputSchema(
			properties: new MCPToolProperties(
				new MCPToolString('a', 'first', required: true),
				new MCPToolInteger('b', 'second', required: false)
			),
			required: ['b']
		);
		
		$serialized = $schema->jsonSerialize();
		
		if(!array_key_exists('required', $serialized)) {
			self::fail('Required key missing from serialized schema');
		}
		
		self::assertEqualsCanonicalizing(['a', 'b'], $serialized['required']);
	}
	
	public function testEnumSerializesTypeAndDefaults(): void {
		$enum = new MCPToolEnum(
			name: 'letters',
			description: 'allowed letters',
			options: ['a', 'b', 'c'],
			required: true,
			minItems: 1,
			maxItems: 3,
			uniqueItems: true,
			default: ['a']
		);
		
		$serialized = $enum->jsonSerialize();
		
		$this->assertSame('array', $serialized['type']);
		$this->assertSame('string', $serialized['items']['type']);
		$this->assertSame(['a', 'b', 'c'], $serialized['items']['enum']);
		
		if(!array_key_exists('default', $serialized)) {
			self::fail('Default key missing from serialized enum');
		}
		
		$this->assertSame(['a'], $serialized['default']);
		$this->assertTrue($serialized['required']);
	}
	
	public function testEnumRejectsMixedTypes(): void {
		$enum = new MCPToolEnum(
			name: 'mixed',
			description: 'invalid mix',
			options: ['x', 1]
		);
		
		$this->expectException(InvalidArgumentException::class);
		$enum->jsonSerialize();
	}
}
