<?php

namespace McpSrv\Types\Tools;

use JsonSerializable;

class MCPTool implements JsonSerializable {
	/**
	 * @param string $name
	 * @param string $description
	 * @param MCPToolInputSchema $arguments
	 * @param callable(object): MCPToolResult $handler
	 * @param null|array<string, mixed> $returnSchema
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $description,
		public readonly MCPToolInputSchema $arguments,
		public readonly bool $isDangerous,
		public $handler,
		public readonly ?array $returnSchema = null,
	) {}
	
	/**
	 * @return array{
	 *     name: string,
	 *     description: string,
	 *     inputSchema: array<string, mixed>,
	 *     returnSchema?: array<string, mixed>
	 * }
	 */
	public function jsonSerialize(): array {
		$result = [
			'name' => $this->name,
			'description' => $this->description,
			'inputSchema' => $this->arguments->jsonSerialize(),
		];

		if($this->returnSchema !== null) {
			$result['returnSchema'] = $this->returnSchema;
		}

		return $result;
	}
}
