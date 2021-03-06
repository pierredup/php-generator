<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\PhpGenerator;

use Nette;
use Nette\Utils\Strings;


/**
 * Generates PHP code.
 */
class Printer
{
	use Nette\SmartObject;

	/** @var string */
	protected $indentation = "\t";

	/** @var int */
	protected $linesBetweenMethods = 2;


	public function printFunction(GlobalFunction $function, PhpNamespace $namespace = null): string
	{
		return Helpers::formatDocComment($function->getComment() . "\n")
			. 'function '
			. ($function->getReturnReference() ? '&' : '')
			. $function->getName()
			. $this->printParameters($function, $namespace)
			. $this->printReturnType($function, $namespace)
			. "\n{\n" . $this->indent(ltrim(rtrim($function->getBody()) . "\n")) . '}';
	}


	public function printClosure(Closure $closure): string
	{
		$uses = [];
		foreach ($closure->getUses() as $param) {
			$uses[] = ($param->isReference() ? '&' : '') . '$' . $param->getName();
		}
		$useStr = strlen($tmp = implode(', ', $uses)) > Helpers::WRAP_LENGTH && count($uses) > 1
			? "\n" . $this->indentation . implode(",\n" . $this->indentation, $uses) . "\n"
			: $tmp;

		return 'function '
			. ($closure->getReturnReference() ? '&' : '')
			. $this->printParameters($closure, null)
			. ($uses ? " use ($useStr)" : '')
			. $this->printReturnType($closure, null)
			. " {\n" . $this->indent(ltrim(rtrim($closure->getBody()) . "\n")) . '}';
	}


	public function printMethod(Method $method, PhpNamespace $namespace = null): string
	{
		return Helpers::formatDocComment($method->getComment() . "\n")
			. ($method->isAbstract() ? 'abstract ' : '')
			. ($method->isFinal() ? 'final ' : '')
			. ($method->getVisibility() ? $method->getVisibility() . ' ' : '')
			. ($method->isStatic() ? 'static ' : '')
			. 'function '
			. ($method->getReturnReference() ? '&' : '')
			. $method->getName()
			. ($params = $this->printParameters($method, $namespace))
			. $this->printReturnType($method, $namespace)
			. ($method->isAbstract() || $method->getBody() === null
				? ';'
				: (strpos($params, "\n") === false ? "\n" : ' ')
					. "{\n"
					. $this->indent(ltrim(rtrim($method->getBody()) . "\n"))
					. '}');
	}


	public function printClass(ClassType $class, PhpNamespace $namespace = null): string
	{
		$resolver = $namespace ? [$namespace, 'unresolveName'] : function ($s) { return $s; };

		$traits = [];
		foreach ($class->getTraitResolutions() as $trait => $resolutions) {
			$traits[] = 'use ' . $resolver($trait)
				. ($resolutions ? " {\n" . $this->indentation . implode(";\n" . $this->indentation, $resolutions) . ";\n}" : ';');
		}

		$consts = [];
		foreach ($class->getConstants() as $const) {
			$consts[] = Helpers::formatDocComment((string) $const->getComment())
				. ($const->getVisibility() ? $const->getVisibility() . ' ' : '')
				. 'const ' . $const->getName() . ' = ' . Helpers::dump($const->getValue()) . ';';
		}

		$properties = [];
		foreach ($class->getProperties() as $property) {
			$properties[] = Helpers::formatDocComment((string) $property->getComment())
				. ($property->getVisibility() ?: 'public') . ($property->isStatic() ? ' static' : '') . ' $' . $property->getName()
				. ($property->getValue() === null ? '' : ' = ' . Helpers::dump($property->getValue()))
				. ';';
		}

		$methods = [];
		foreach ($class->getMethods() as $method) {
			$methods[] = $this->printMethod($method, $namespace);
		}

		$methodSpace = str_repeat("\n", $this->linesBetweenMethods + 1);

		return Strings::normalize(
			Helpers::formatDocComment($class->getComment() . "\n")
			. ($class->isAbstract() ? 'abstract ' : '')
			. ($class->isFinal() ? 'final ' : '')
			. ($class->getName() ? $class->getType() . ' ' . $class->getName() . ' ' : '')
			. ($class->getExtends() ? 'extends ' . implode(', ', array_map($resolver, (array) $class->getExtends())) . ' ' : '')
			. ($class->getImplements() ? 'implements ' . implode(', ', array_map($resolver, $class->getImplements())) . ' ' : '')
			. ($class->getName() ? "\n" : '') . "{\n"
			. $this->indent(
				($traits ? implode("\n", $traits) . "\n\n" : '')
				. ($consts ? implode("\n", $consts) . "\n\n" : '')
				. ($properties ? implode("\n\n", $properties) . $methodSpace : '')
				. ($methods ? implode($methodSpace, $methods) . "\n" : ''))
			. '}'
		) . ($class->getName() ? "\n" : '');
	}


	public function printNamespace(PhpNamespace $namespace): string
	{
		$name = $namespace->getName();

		$uses = [];
		foreach ($namespace->getUses() as $alias => $original) {
			$useNamespace = Helpers::extractNamespace($original);

			if ($name !== $useNamespace) {
				if ($alias === $original || substr($original, -(strlen($alias) + 1)) === '\\' . $alias) {
					$uses[] = "use $original;";
				} else {
					$uses[] = "use $original as $alias;";
				}
			}
		}

		$classes = [];
		foreach ($namespace->getClasses() as $class) {
			$classes[] = $this->printClass($class, $namespace);
		}

		$body = ($uses ? implode("\n", $uses) . "\n\n" : '')
			. implode("\n", $classes);

		if ($namespace->getBracketedSyntax()) {
			return 'namespace' . ($name ? " $name" : '') . " {\n\n"
				. $this->indent($body)
				. "\n}\n";

		} else {
			return ($name ? "namespace $name;\n\n" : '')
				. $body;
		}
	}


	public function printFile(PhpFile $file): string
	{
		$namespaces = [];
		foreach ($file->getNamespaces() as $namespace) {
			$namespaces[] = $this->printNamespace($namespace);
		}

		return Strings::normalize(
			"<?php\n"
			. ($file->getComment() ? "\n" . Helpers::formatDocComment($file->getComment() . "\n") . "\n" : '')
			. implode("\n\n", $namespaces)
		) . "\n";
	}


	protected function indent(string $s): string
	{
		return Strings::indent($s, 1, $this->indentation);
	}


	/**
	 * @param Nette\PhpGenerator\Traits\FunctionLike  $function
	 */
	protected function printParameters($function, ?PhpNamespace $namespace): string
	{
		$params = [];
		$list = $function->getParameters();
		foreach ($list as $param) {
			$variadic = $function->isVariadic() && $param === end($list);
			$hint = $param->getTypeHint();
			$params[] = ($hint ? ($param->isNullable() ? '?' : '') . ($namespace ? $namespace->unresolveName($hint) : $hint) . ' ' : '')
				. ($param->isReference() ? '&' : '')
				. ($variadic ? '...' : '')
				. '$' . $param->getName()
				. ($param->hasDefaultValue() && !$variadic ? ' = ' . Helpers::dump($param->getDefaultValue()) : '');
		}

		return strlen($tmp = implode(', ', $params)) > Helpers::WRAP_LENGTH && count($params) > 1
			? "(\n" . $this->indentation . implode(",\n" . $this->indentation, $params) . "\n)"
			: "($tmp)";
	}


	/**
	 * @param Nette\PhpGenerator\Traits\FunctionLike  $function
	 */
	protected function printReturnType($function, ?PhpNamespace $namespace): string
	{
		return $function->getReturnType()
			? ': ' . ($function->getReturnNullable() ? '?' : '') . ($namespace ? $namespace->unresolveName($function->getReturnType()) : $function->getReturnType())
			: '';
	}
}
