<?php

declare(strict_types=1);

/**
 * Architecture presets over the application's own namespace, the same three `robot-council/core`
 * applies. `strict()` forbids a non-final class and a protected method, `security()` bans the
 * functions that are a weak default rather than a choice, and `php()` covers the rest.
 *
 * @command  vendor/bin/pest --compact tests/ArchTest.php
 */
arch()->preset()->php();

arch()->preset()->security();

arch()->preset()->strict();
