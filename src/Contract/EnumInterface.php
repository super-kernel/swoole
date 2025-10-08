<?php
declare(strict_types=1);

namespace SuperKernel\Swoole\Contract;

/**
 * This interface defines the definition of a pure enumeration class with a value.
 */
interface EnumInterface
{
	public function value();
}