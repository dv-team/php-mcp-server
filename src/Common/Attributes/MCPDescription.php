<?php

namespace McpSrv\Common\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
readonly class MCPDescription {
	public function __construct(
		public string $description
	) {}
}
