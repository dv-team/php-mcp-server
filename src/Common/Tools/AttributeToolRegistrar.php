<?php

namespace McpSrv\Common\Tools;

use McpSrv\Common\Attributes\MCPDescription;
use McpSrv\Common\Attributes\MCPTool as MCPToolAttribute;
use McpSrv\Common\MCPInvalidArgumentException;
use McpSrv\Common\Properties\MCPToolRawProperty;
use McpSrv\MCPServer;
use McpSrv\Types\Tools\MCPToolInputSchema;
use McpSrv\Types\Tools\MCPToolProperties;
use McpSrv\Types\Tools\MCPToolResult;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * @internal Use {@see MCPServer} instead.
 */
class AttributeToolRegistrar {
	public static function register(object $toolCollection, MCPServer $server, bool $isDangerous = false): void {
		$reflection = new ReflectionClass($toolCollection);

		foreach($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			$attributes = $method->getAttributes(MCPToolAttribute::class);

			if(!count($attributes)) {
				continue;
			}

			/** @var MCPToolAttribute $toolAttribute */
			$toolAttribute = $attributes[0]->newInstance();

			$inputSchema = self::buildInputSchema($toolAttribute->parametersSchema, $method);

			$server->registerTool(
				name: $toolAttribute->name,
				description: $toolAttribute->description,
				inputSchema: $inputSchema,
				isDangerous: $isDangerous || $toolAttribute->isDangerous,
				handler: static function(object $arguments) use ($toolCollection, $method): MCPToolResult {
					return self::invokeTool($toolCollection, $method, $arguments);
				},
				returnSchema: (object) $toolAttribute->returnSchema
			);
		}
	}

	/**
	 * @param array{}|object $schema
	 */
	private static function buildInputSchema(array|object $schema, ReflectionMethod $method): MCPToolInputSchema {
		$schema = (object) $schema;
		$properties = $schema->properties ?? [];
		if(!is_array($properties)) {
			throw new MCPInvalidArgumentException('parametersSchema must contain a properties map');
		}

		$requiredList = [];
		if(property_exists($schema, 'required')) {
			if(!is_array($schema->required)) {
				throw new MCPInvalidArgumentException('parametersSchema required must be an array of property names');
			}

			foreach($schema->required as $requiredEntry) {
				if(is_string($requiredEntry)) {
					$requiredList[] = $requiredEntry;
				}
			}
		}

		$parameterDescriptions = self::getParameterDescriptions($method);
		$parameters = $method->getParameters();

		foreach($parameters as $parameter) {
			$name = $parameter->getName();
			$description = $parameterDescriptions[$name] ?? null;

			if(!array_key_exists($name, $properties)) {
				if($description === null) {
					continue;
				}

				$type = self::mapParameterType($parameter);

				if($type === null) {
					throw new MCPInvalidArgumentException("Cannot derive schema for parameter '$name' without a type");
				}

				$properties[$name] = [
					'type' => $type,
					'description' => $description,
				];
			} elseif(is_array($properties[$name]) && $description !== null && !array_key_exists('description', $properties[$name])) {
				$properties[$name]['description'] = $description;
			}

			if(is_array($properties[$name]) && !array_key_exists('required', $properties[$name])) {
				$properties[$name]['required'] = in_array($name, $requiredList, true) || (!$parameter->isOptional() && !$parameter->allowsNull());
			}
		}

		$propertyObjects = [];

		foreach($properties as $name => $definition) {
			if(!is_array($definition)) {
				throw new MCPInvalidArgumentException("Property schema for '$name' must be an array");
			}

			if(!array_key_exists('type', $definition)) {
				$type = null;

				foreach($parameters as $parameter) {
					if($parameter->getName() === $name) {
						$type = self::mapParameterType($parameter);
						break;
					}
				}

				if($type === null) {
					throw new MCPInvalidArgumentException("Schema for '$name' must include a type");
				}

				$definition['type'] = $type;
			}

			$required = $definition['required'] ?? in_array($name, $requiredList, true);

			$propertyObjects[] = new MCPToolRawProperty(
				name: $name,
				schema: $definition,
				required: (bool) $required,
			);
		}

		return new MCPToolInputSchema(
			properties: new MCPToolProperties(...$propertyObjects),
			required: $requiredList,
		);
	}

	/**
	 * @return array<string, string>
	 */
	private static function getParameterDescriptions(ReflectionMethod $method): array {
		$descriptions = [];

		foreach($method->getParameters() as $parameter) {
			$attribute = $parameter->getAttributes(MCPDescription::class);

			if(!count($attribute)) {
				continue;
			}

			/** @var MCPDescription $description */
			$description = $attribute[0]->newInstance();
			$descriptions[$parameter->getName()] = $description->description;
		}

		return $descriptions;
	}

	private static function invokeTool(object $tool, ReflectionMethod $method, object $arguments): MCPToolResult {
		$parameterValues = [];

		foreach($method->getParameters() as $parameter) {
			$name = $parameter->getName();

			if(property_exists($arguments, $name)) {
				$parameterValues[] = $arguments->$name;
				continue;
			}

			if($parameter->isDefaultValueAvailable()) {
				$parameterValues[] = $parameter->getDefaultValue();
				continue;
			}

			if($parameter->allowsNull()) {
				$parameterValues[] = null;
				continue;
			}

			throw new MCPInvalidArgumentException("Missing required argument '$name'", 100);
		}

		$result = $method->invokeArgs($tool, $parameterValues);

		if($result instanceof MCPToolResult) {
			return $result;
		}

		if(is_object($result) || is_array($result)) {
			$content = $result;
		} elseif($result === null || is_scalar($result)) {
			$content = ['value' => $result];
		} else {
			$content = ['value' => get_debug_type($result)];
		}

		return new MCPToolResult(
			content: $content,
			isError: false
		);
	}

	private static function mapParameterType(ReflectionParameter $parameter): ?string {
		$type = $parameter->getType();

		if(!($type instanceof ReflectionNamedType)) {
			return null;
		}

		return match($type->getName()) {
			'int' => 'integer',
			'float' => 'number',
			'string' => 'string',
			'bool' => 'boolean',
			'array' => 'array',
			'object' => 'object',
			default => null,
		};
	}
}
