<?php

namespace McpSrv\Types\Tools;

class MCPToolRawInputSchema implements MCPToolInputSchemaInterface {
	/**
	 * @param object|mixed[] $schema
	 */
	public function __construct(
		public object|array $schema
	) {}

	public function jsonSerialize(): object {
		$schema = (array) $this->schema;
		$schema['additionalProperties'] = false;
		return (object) $schema;
	}
}
