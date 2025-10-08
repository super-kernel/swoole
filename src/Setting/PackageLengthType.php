<?php
declare(strict_types=1);

namespace SuperKernel\Swoole\Setting;

enum PackageLengthType: string
{
	/**
	 * Signed, 1 byte
	 */
	case  c = 'c';

	/**
	 * Unsigned, 1 byte
	 */
	case   C = 'C';

	/**
	 * Signed, host byte order, 2 bytes
	 */
	case   s = 's';

	/**
	 * Unsigned, host byte order, 2 bytes
	 */
	case   S = 'S';

	/**
	 * Unsigned, network byte order, 2 bytes
	 */
	case   n = 'n';

	/**
	 * Unsigned, network byte order, 4 bytes
	 */
	case   N = 'N';

	/**
	 * Signed, host byte order, 4 bytes (lowercase L)
	 */
	case   l = 'l';

	/**
	 * Unsigned, host byte order, 4 bytes (uppercase L)
	 */
	case   L = 'L';

	/**
	 * Unsigned, little-endian byte order, 2 bytes
	 */
	case   v = 'v';

	/**
	 * Unsigned, little-endian byte order, 4 bytes
	 */
	case   V = 'V';
}