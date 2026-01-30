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
	 * @param TJsonSchema|null $returnSchema
	 */
	public function __construct(
		public string $name,
		public string $description,
		public array|object|null $parametersSchema = null,
		public array|object|null $returnSchema = null,
		public bool $isDangerous = false,
	) {}
}
