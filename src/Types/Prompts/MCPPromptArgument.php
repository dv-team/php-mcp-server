<?php

namespace McpSrv\Types\Prompts;

class MCPPromptArgument {
	public function __construct(
		public string $name,
		public ?string $description = null,
		public bool $required = false
	) {}
}