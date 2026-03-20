<?php

declare(strict_types=1);

namespace Nubos\Init\Enums;

enum SubStructure: string
{
    case None = 'None';
    case Teams = 'Teams';
    case Workspaces = 'Workspaces';
    case WorkspacesAndTeams = 'Workspaces + Teams';
}
