<?php

namespace McpSrv\Types\Tools;

use JsonSerializable;

class MCPTool implements JsonSerializable {
	/**
	 * @param string $name
	 * @param string $description
	 * @param MCPToolInputSchemaInterface $arguments
	 * @param callable(object): MCPToolResult $handler
	 * @param null|object $returnSchema
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $description,
		public readonly MCPToolInputSchemaInterface $arguments,
		public readonly bool $isDangerous,
		public $handler,
		public readonly ?object $returnSchema = null,
	) {}
	
	/**
	 * @return object{
	 *     name: string,
	 *     description: string,
	 *     inputSchema: object,
	 *     returnSchema?: object
	 * }
	 */
	public function jsonSerialize(): object {
		$result = [
			'name' => $this->name,
			'description' => $this->description,
			'inputSchema' => $this->arguments->jsonSerialize(),
		];

		if($this->returnSchema !== null) {
			$result['returnSchema'] = $this->returnSchema;
		}

		return (object) $result;
	}
}
