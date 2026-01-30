<?php

namespace McpSrv\Common\Attributes;

use Attribute;

/**
 * @phpstan-type TJsonSchema array<string, mixed>|object{}
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
		public array|object $parametersSchema,
		public array|object $returnSchema,
		public bool $isDangerous = false,
	) {}
}
