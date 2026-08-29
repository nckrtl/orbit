<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

enum ToolDoctorIssueCode: string implements DoctorIssueCode
{
    case NotInstalled = 'tool.not_installed';
    case VersionMismatch = 'tool.version_mismatch';
    case InspectionFailed = 'tool.inspection_failed';
    case NodeUnreachable = 'tool.node_unreachable';

    public function code(): string
    {
        return $this->value;
    }

    public function family(): DoctorFamily
    {
        return DoctorFamily::Tool;
    }
}
