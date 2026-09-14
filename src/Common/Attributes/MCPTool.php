<?php

namespace McpSrv\Common\Attributes;

use Attribute;

/**
 * @phpstan-type TJsonSchema array<string, mixed>|object{}
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
readonly class MCPTool {
	/**
	 * @param string $name Stable tool identifier used by tools/list and tools/call.
	 * @param string $description Explains the tool's purpose and usage to clients and language models.
	 * @param TJsonSchema|null $parametersSchema Input schema to supplement parameter types and MCPDescription attributes.
	 * @param TJsonSchema|null $annotations Optional behavior hints; these do not enforce permissions.
	 * @param TJsonSchema|null $outputSchema Optional JSON Schema describing the method's structured result.
	 * @param bool $useIf Registration condition, typically a boolean constant expression; false skips the method.
	 */
	public function __construct(
		public string $name,
		public string $description,
		public array|object|null $parametersSchema = null,
		public array|object|null $annotations = null,
		public array|object|null $outputSchema = null,
		public bool $useIf = true,
	) {}
}
