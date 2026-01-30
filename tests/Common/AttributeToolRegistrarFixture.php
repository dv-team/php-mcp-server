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
		returnSchema: [],
		isDangerous: true
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
		returnSchema: []
	)]
	public function echoMessage(
		#[MCPDescription('Message')] string $message = 'default',
	): string {
		return $message;
	}
}
