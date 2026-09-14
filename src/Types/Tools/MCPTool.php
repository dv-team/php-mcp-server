<?php

namespace McpSrv\Types\Tools;

use JsonSerializable;

class MCPTool implements JsonSerializable {
	/**
	 * @param string $name Stable tool identifier used by tools/list and tools/call.
	 * @param string $description Explains the tool's purpose and usage to clients and language models.
	 * @param MCPToolInputSchemaInterface $arguments JSON Schema describing accepted tool arguments.
	 * @param callable(object): MCPToolResult $handler Executes the tool with the supplied argument object.
	 * @param null|object $annotations Optional behavior hints such as readOnlyHint; these do not enforce permissions.
	 * @param null|object $outputSchema Optional JSON Schema describing the structured result returned by the handler.
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
