<?php
/**
 * Pest bootstrap that reuses the existing PHPUnit bootstrap to preserve
 * Brain Monkey and test stubs. This ensures Pest runs tests in the same
 * lightweight environment as PHPUnit.
 */

require_once __DIR__ . '/bootstrap.php';

