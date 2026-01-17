<?php

namespace McpSrv\Common\Attributes;

use Attribute;

/**
 * @phpstan-type TJsonSchema object{}
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
readonly class MCPTool {
	/**
	 * @param TJsonSchema $parametersSchema
	 * @param TJsonSchema $returnSchema
	 */
	public function __construct(
		public string $name,
		public string $description,
		public object $parametersSchema,
		public object $returnSchema,
	) {}
}
