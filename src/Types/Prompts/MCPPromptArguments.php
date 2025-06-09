<?php

namespace McpSrv\Types\Prompts;

use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<MCPPromptArgument>
 */
class MCPPromptArguments implements IteratorAggregate {
	/** @var MCPPromptArgument[] */
	public readonly array $arguments;
	
	public function __construct(
		MCPPromptArgument ...$arguments
	) {
		$this->arguments = $arguments;
	}
	
	public function getIterator(): Traversable {
		yield from $this->arguments;
	}
}