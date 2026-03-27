<?php

namespace McpSrv\Types\Tools;

use JsonSerializable;

class MCPTool implements JsonSerializable {
	/**
	 * @param string $name
	 * @param string $description
	 * @param MCPToolInputSchemaInterface $arguments
	 * @param callable(object): MCPToolResult $handler
	 * @param null|object $annotations
	 * @param null|object $outputSchema
	 */
	public function __construct(
		public readonly string $name,
		public readonly string $description,
		public readonly MCPToolInputSchemaInterface $arguments,
		public $handler,
		public readonly ?object $annotations = null,
		public readonly ?object $outputSchema = null,
	) {}

	/**
	 * @return object{
	 *     name: string,
	 *     description: string,
	 *     inputSchema?: object,
	 *     annotations?: object,
	 *     outputSchema?: object
	 * }
	 */
	public function jsonSerialize(): object {
		$inputSchema = $this->arguments->jsonSerialize();

		$result = [
			'name' => $this->name,
			'description' => $this->description,
			'inputSchema' => $inputSchema,
		];

		if($this->annotations !== null) {
			$result['annotations'] = $this->annotations;
		}

		if($this->outputSchema !== null) {
			$result['outputSchema'] = $this->outputSchema;
		}

		return (object) $result;
	}
}
