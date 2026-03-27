<?php

namespace McpSrv\Common\Attributes;

use Attribute;

/**
 * @phpstan-type TJsonSchema array<string, mixed>|object{}
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
readonly class MCPTool {
	/**
	 * @param TJsonSchema|null $parametersSchema
	 * @param TJsonSchema|null $annotations
	 * @param TJsonSchema|null $outputSchema
	 */
	public function __construct(
		public string $name,
		public string $description,
		public array|object|null $parametersSchema = null,
		public array|object|null $annotations = null,
		public array|object|null $outputSchema = null,
	) {}
}
