<?php

declare(strict_types=1);

namespace Afiphp;

enum Environment: string
{
    case HOMOLOGATION = 'homologation';
    case PRODUCTION = 'production';
}
