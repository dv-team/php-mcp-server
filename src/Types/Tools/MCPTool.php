<?php

namespace McpSrv\Types\Tools;

use JsonSerializable;

class MCPTool implements JsonSerializable {
	/**
	 * @param string $name
	 * @param string $description
	 * @param MCPToolInputSchema $arguments
	 * @param callable(object): MCPToolResult $handler
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $description,
		public readonly MCPToolInputSchema $arguments,
		public readonly bool $isDangerous,
		public $handler,
	) {}
	
	/**
	 * @return array{
	 *     name: string,
	 *     description: string,
	 *     inputSchema: array<string, mixed>
	 * }
	 */
	public function jsonSerialize(): array {
		return [
			'name' => $this->name,
			'description' => $this->description,
			'inputSchema' => $this->arguments->jsonSerialize(),
		];
	}
}
