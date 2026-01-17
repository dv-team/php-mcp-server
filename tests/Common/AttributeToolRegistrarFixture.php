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
		parametersSchema: (object) ['properties' => []],
		returnSchema: (object) []
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
		parametersSchema: (object) [
			'properties' => [
				'message' => ['type' => 'string'],
			],
			'required' => ['message'],
		],
		returnSchema: (object) []
	)]
	public function echoMessage(
		#[MCPDescription('Message')] string $message = 'default',
	): string {
		return $message;
	}
}
