<?php

namespace App\Domain\Projects;

enum ProjectOverspendResult: string
{
    case None = 'none';
    case Created = 'created';
    case Increased = 'increased';
}
