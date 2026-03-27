<?php

declare(strict_types=1);

namespace McpSrv\Common;

use McpSrv\Common\Attributes\MCPDescription;
use McpSrv\Common\Attributes\MCPTool as MCPToolAttribute;

class AttributeToolRegistrarFixture {
	/**
	 * @param int $a
	 * @param int $b
	 * @return array{sum: int}
	 */
	#[MCPToolAttribute(
		name: 'sum',
		description: 'Sum numbers',
		parametersSchema: ['properties' => []],
		annotations: [
			'readOnlyHint' => true,
			'idempotentHint' => true,
			'openWorldHint' => false,
		],
		outputSchema: [
			'type' => 'object',
			'properties' => [
				'sum' => ['type' => 'integer'],
			],
			'required' => ['sum'],
		]
	)]
	public function sum(
		#[MCPDescription('First number')] int $a,
		#[MCPDescription('Second number')] int $b,
	): array {
		return ['sum' => $a + $b];
	}

	#[MCPToolAttribute(
		name: 'echo',
		description: 'Echo text',
		parametersSchema: [
			'properties' => [
				'message' => ['type' => 'string'],
			],
			'required' => ['message'],
		],
		annotations: [
			'readOnlyHint' => true,
			'idempotentHint' => true,
			'openWorldHint' => false,
		],
		outputSchema: [
			'type' => 'object',
			'properties' => [
				'value' => ['type' => 'string'],
			],
			'required' => ['value'],
		]
	)]
	public function echoMessage(
		#[MCPDescription('Message')] string $message = 'default',
	): string {
		return $message;
	}
}
