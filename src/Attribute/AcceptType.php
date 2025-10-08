<?php
declare(strict_types=1);

namespace SuperKernel\Swoole\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class AcceptType
{
	public const string Array    = 'array';
	public const string Boolean  = 'boolean';
	public const string Callback = 'callback';
	public const string Float    = 'float';
	public const string Integer  = 'integer';
	public const string True     = 'true';
	public const string False    = 'false';
	public const string String   = 'string';


	public function __construct(public string $types)
	{
	}

	public function check(mixed $value): bool
	{
	}
}