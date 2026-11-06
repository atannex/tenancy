<?php

declare(strict_types=1);

namespace Atannex\Tenancy\Attributes;

use Attribute;

/** Mark a queued job as central: it never carries or restores a tenant. */
#[Attribute(Attribute::TARGET_CLASS)]
final class Central {}
